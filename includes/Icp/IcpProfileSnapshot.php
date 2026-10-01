<?php

declare(strict_types=1);

namespace App\Icp;

/**
 * Immutable, versioned snapshots of the ICP profile (workstream P4).
 *
 * Problem (run-6 phantom-config lesson): IcpProfile is LIVE DB state. A
 * scoring run read the profile at run time and left no record of what it
 * saw, so a past run's inputs were not reproducible — the phantom ICP that
 * runs 4-6 measured could never be reconstructed.
 *
 * Invariant (ported from the KG serving pattern, integration map section 4b
 * P4): every scoring/enrollment run pins (profile_snapshot_id,
 * evidence_set_hash); snapshots are immutable after write; rollback =
 * repoint the pin. Lighter than a full KG by design: immutable profile
 * snapshots, NOT a separate SQLite file.
 *
 * Mechanics:
 *   - capture() reads the full scoring-relevant profile state (all
 *     dimensions: weight, buyer_locked, enabled incl. tech_stack,
 *     target_config; exclusions; thresholds), canonicalizes it
 *     (recursive key sort, fixed dimension order), and hashes it
 *     (content_hash, UNIQUE). Identical state reuses the existing row:
 *     snapshot writes are IDEMPOTENT — one row per distinct state.
 *   - The evidence_set_hash folds the lead/evidence inputs the run consumed
 *     into the same hash, so two runs pin to the same snapshot but diverge
 *     in evidence when their lead inputs differ.
 *   - Immutability is enforced at the DB layer (BEFORE UPDATE / BEFORE
 *     DELETE triggers raise SQLSTATE 45000); this class exposes NO mutator.
 *     Any attempted mutation fails loudly instead of rewriting history.
 *   - recordRun() persists the pin on the run record (lead_scoring_runs).
 *     It is fail-safe by design: missing tables, bad connections, and
 *     write failures are logged and return false — the pin is
 *     observability, never load-bearing for qualification.
 *   - restoredProfile() + ScoreLeadFitAction::scoreWithSnapshot() repoint a
 *     run at a named earlier snapshot to reproduce its inputs exactly.
 *
 * Product rules (docs/NEVER_JEV.md): snapshot/hashing machinery is
 * deterministic code (boundary 5 — candidate retrieval/aggregation/
 * thresholds stay code, never judgment). No JEV call, no human gate, no
 * change to the determination path or to review semantics (boundary 6).
 */
class IcpProfileSnapshot
{
    public const SNAPSHOTS_TABLE = 'icp_profile_snapshots';
    public const RUNS_TABLE = 'lead_scoring_runs';

    /**
     * The lead fields a scoring run consumes (ScoreLeadFitAction::leadContext
     * + the veto match inputs). Only changes to THESE fields change the
     * evidence_set_hash; other lead fields (status, tags, campaign_id, ...)
     * are not run inputs and must not perturb the hash.
     */
    public const LEAD_EVIDENCE_FIELDS = [
        'company_name',
        'website',
        'contact_name',
        'email',
        'target_persona',
        'country_code',
        'notes',
    ];

    /**
     * notes are consumed by the scorer capped at 4000 chars
     * (ScoreLeadFitAction::leadContext); hash exactly what was consumed.
     */
    public const NOTES_CAP = 4000;

    /** Code version stamp stored in the snapshot envelope (audit aid). */
    public const CODE_VERSION = 'P4-v1';

    // ------------------------------------------------------------------
    // Pure functions (deterministic, DB-free, safe for unit tests)
    // ------------------------------------------------------------------

    /**
     * Canonical JSON: object keys sorted recursively, numeric lists keep
     * their order. Two equal payloads always serialize byte-identically
     * regardless of insertion order — hash inputs must be order-stable.
     */
    public static function canonicalJson(mixed $value): string
    {
        $json = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        return $json === false ? 'null' : $json;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        return $out;
    }

    /**
     * Build the canonical scoring-input content for a profile: everything a
     * scoring run reads from the profile. The 'enabled' flag of every
     * dimension (incl. tech_stack) is part of the content — a toggle flip
     * changes the snapshot even when weights are unchanged.
     *
     * @param array<string,array{weight:int,buyer_locked:bool,enabled:bool,target_config:array}> $dimensions
     * @param list<array{exclusion_type:string,value:string,note:string}> $exclusions
     * @param array{qualify:int,review:int} $thresholds
     */
    public static function profileContent(
        int $profileId,
        string $profileName,
        ?string $painStatement,
        array $dimensions,
        array $exclusions,
        array $thresholds
    ): array {
        $dims = [];
        foreach (IcpProfile::DIMENSIONS as $dimKey) {
            $d = $dimensions[$dimKey] ?? null;
            $dims[$dimKey] = [
                'weight'        => (int)(is_array($d) ? ($d['weight'] ?? 0) : 0),
                'buyer_locked'  => is_array($d) && !empty($d['buyer_locked']),
                'enabled'       => is_array($d) && !empty($d['enabled']),
                'target_config' => (is_array($d) && is_array($d['target_config'] ?? null))
                    ? $d['target_config']
                    : [],
            ];
        }
        $ex = [];
        foreach ($exclusions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ex[] = [
                'exclusion_type' => strtolower((string)($row['exclusion_type'] ?? '')),
                'value'          => (string)($row['value'] ?? ''),
                'note'           => (string)($row['note'] ?? ''),
            ];
        }
        return [
            'profile_id'    => $profileId,
            'profile_name'  => $profileName,
            'pain_statement' => $painStatement ?? '',
            'dimensions'    => $dims,
            'exclusions'    => $ex,
            'thresholds'    => [
                'qualify' => (int)($thresholds['qualify'] ?? 75),
                'review'  => (int)($thresholds['review'] ?? 50),
            ],
        ];
    }

    /** sha256 over the canonical content JSON. */
    public static function contentHash(array $content): string
    {
        return hash('sha256', self::canonicalJson($content));
    }

    /**
     * The lead inputs a scoring run consumed, in a fixed order.
     * 'notes' is capped exactly as the scorer consumes it (NOTES_CAP).
     */
    public static function leadEvidenceInputs(array $lead): array
    {
        $out = [];
        foreach (self::LEAD_EVIDENCE_FIELDS as $field) {
            $v = (string)($lead[$field] ?? '');
            if ($field === 'notes') {
                $v = mb_substr($v, 0, self::NOTES_CAP);
            }
            $out[$field] = $v;
        }
        return $out;
    }

    /**
     * The per-run evidence hash: profile content the run saw + the
     * lead/evidence inputs it consumed. Same snapshot + same inputs =
     * identical hash (the repoint-rollback equality test); different lead
     * inputs pin to the same snapshot with a different evidence hash.
     */
    public static function evidenceSetHash(string $contentHash, array $lead): string
    {
        return hash('sha256', self::canonicalJson([
            'profile_content_hash' => $contentHash,
            'lead_inputs'          => self::leadEvidenceInputs($lead),
        ]));
    }

    /**
     * Restore a stored snapshot row to the resolveProfile() shape
     * ScoreLeadFitAction::score() consumes, with the pin attached
     * ('snapshot_id', 'content_hash'). The restored dimensions carry the
     * exact weight/enabled/target_config the original run saw — repointing
     * a run at this profile reproduces its scoring inputs exactly.
     *
     * @param array{id:int,profile_id:int,profile_name:string,content_hash:string,snapshot:string} $row
     * @return array{id:int,key:string,dimensions:array,weights:array<string,int>,
     *               exclusions:array,thresholds:array{qualify:int,review:int},
     *               snapshot_id:int,content_hash:string}|null
     */
    public static function restoredProfile(array $row): ?array
    {
        $payload = json_decode((string)($row['snapshot'] ?? ''), true);
        $content = is_array($payload) ? ($payload['content'] ?? null) : null;
        if (!is_array($content) || !is_array($content['dimensions'] ?? null)) {
            return null;
        }
        $dimensions = [];
        $weights = [];
        foreach (IcpProfile::DIMENSIONS as $dimKey) {
            $d = $content['dimensions'][$dimKey] ?? [];
            $dimensions[$dimKey] = [
                'weight'        => (int)($d['weight'] ?? 0),
                'buyer_locked'  => !empty($d['buyer_locked']),
                'enabled'       => !empty($d['enabled']),
                'target_config' => is_array($d['target_config'] ?? null) ? $d['target_config'] : [],
            ];
            $weights[$dimKey] = (int)($d['weight'] ?? 0);
        }
        return [
            'id'           => (int)($content['profile_id'] ?? $row['profile_id']),
            'key'          => (string)($content['profile_name'] ?? $row['profile_name']),
            'dimensions'   => $dimensions,
            'weights'      => $weights,
            'exclusions'   => array_values(array_filter(
                (array)($content['exclusions'] ?? []),
                'is_array'
            )),
            'thresholds'   => [
                'qualify' => (int)($content['thresholds']['qualify'] ?? 75),
                'review'  => (int)($content['thresholds']['review'] ?? 50),
            ],
            'snapshot_id'  => (int)$row['id'],
            'content_hash' => (string)$row['content_hash'],
        ];
    }

    // ------------------------------------------------------------------
    // DB-backed operations
    // ------------------------------------------------------------------

    /**
     * Capture the current scoring-relevant state of a profile and return the
     * resolved profile shape with the pin attached
     * ('snapshot_id', 'content_hash', 'evidence_set_hash').
     *
     * Idempotent: identical profile state reuses the existing snapshot row
     * (content_hash is UNIQUE; a concurrent double-insert falls back to the
     * re-read path instead of failing).
     *
     * Fail-safe: returns null on ANY failure (missing tables, bad
     * connection, incomplete profile) — the pin is observability, never
     * load-bearing. The caller scores without a pin, exactly as before P4.
     */
    public static function capture(int $profileId, array $lead): ?array
    {
        try {
            $active = IcpProfile::get($profileId);
            if (!is_array($active)) {
                return null;
            }
            $dimensions = IcpProfile::dimensions($profileId);
            foreach (IcpProfile::DIMENSIONS as $dim) {
                if (!isset($dimensions[$dim]) || !is_array($dimensions[$dim])) {
                    error_log("[IcpProfileSnapshot] profile {$profileId} incomplete (missing '{$dim}'); no snapshot.");
                    return null;
                }
            }
            $content = self::profileContent(
                $profileId,
                (string)($active['name'] ?? ('profile-' . $profileId)),
                isset($active['pain_statement']) ? (string)$active['pain_statement'] : null,
                $dimensions,
                IcpProfile::exclusions($profileId),
                IcpProfile::thresholds()
            );
            $hash = self::contentHash($content);

            $pdo = \App\Database::getConnection();
            if (!self::tableReady($pdo, self::SNAPSHOTS_TABLE)) {
                error_log('[IcpProfileSnapshot] ' . self::SNAPSHOTS_TABLE . ' not installed; no snapshot.');
                return null;
            }
            $sel = $pdo->prepare(
                'SELECT id FROM ' . self::SNAPSHOTS_TABLE . ' WHERE content_hash = ? LIMIT 1'
            );
            $sel->execute([$hash]);
            $existing = $sel->fetch(\App\PDO::FETCH_ASSOC);
            if ($existing !== false) {
                $snapshotId = (int)$existing['id'];
            } else {
                $envelope = [
                    'content' => $content,
                    'meta'    => [
                        'captured_at'  => gmdate('c'),
                        'code'         => self::CODE_VERSION,
                        'profile_id'   => $profileId,
                    ],
                ];
                $ins = $pdo->prepare(
                    'INSERT INTO ' . self::SNAPSHOTS_TABLE . '
                     (profile_id, profile_name, content_hash, snapshot)
                     VALUES (?, ?, ?, ?)'
                );
                try {
                    $ins->execute([
                        $profileId,
                        $content['profile_name'],
                        $hash,
                        self::canonicalJson($envelope),
                    ]);
                    $snapshotId = (int)$pdo->lastInsertId();
                } catch (\App\PDOException $e) {
                    if ((int)$e->getCode() !== 1062) {
                        throw $e;
                    }
                    // Lost the insert race with another worker: re-read the
                    // winner's row instead of failing the run.
                    $sel->execute([$hash]);
                    $winner = $sel->fetch(\App\PDO::FETCH_ASSOC);
                    if ($winner === false) {
                        return null;
                    }
                    $snapshotId = (int)$winner['id'];
                }
            }

            $weights = [];
            foreach (IcpProfile::DIMENSIONS as $dimKey) {
                $weights[$dimKey] = (int)($dimensions[$dimKey]['weight'] ?? 0);
            }

            return [
                'id'                => $profileId,
                'key'               => $content['profile_name'],
                'dimensions'        => $dimensions,
                'weights'           => $weights,
                'exclusions'        => IcpProfile::exclusions($profileId),
                'thresholds'        => $content['thresholds'],
                'snapshot_id'       => $snapshotId,
                'content_hash'      => $hash,
                'evidence_set_hash' => self::evidenceSetHash($hash, $lead),
            ];
        } catch (\Throwable $e) {
            error_log('[IcpProfileSnapshot::capture] snapshot pin unavailable: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Read a stored snapshot row (id, profile_id, profile_name,
     * content_hash, snapshot, created_at), or null when unknown/unreadable.
     */
    public static function get(int $snapshotId): ?array
    {
        try {
            $pdo = \App\Database::getConnection();
            if (!self::tableReady($pdo, self::SNAPSHOTS_TABLE)) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT id, profile_id, profile_name, content_hash, snapshot, created_at
                 FROM ' . self::SNAPSHOTS_TABLE . ' WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$snapshotId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            error_log('[IcpProfileSnapshot::get] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Persist the pin on the run record (lead_scoring_runs). Fail-safe: a
     * missing table, a NULL pin (unscored/legacy-only run), or any write
     * failure returns false and is logged — recording the run must never
     * break qualification.
     *
     * @param \App\PDO|null $pdo  caller-supplied connection (never the static
     *                           gateway — the pin write rides the caller's
     *                           connection).
     * @param array{snapshot_id?:int|null,evidence_set_hash?:string|null,
     *              fit_score?:int,verdict?:string,source?:string} $scoreResult
     */
    public static function recordRun(?\App\PDO $pdo, int $leadId, array $scoreResult): bool
    {
        try {
            if ($pdo === null || !self::tableReady($pdo, self::RUNS_TABLE)) {
                return false;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::RUNS_TABLE . '
                 (lead_id, profile_snapshot_id, evidence_set_hash, fit_score, verdict, source)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $leadId,
                $scoreResult['snapshot_id'] ?? null,
                $scoreResult['evidence_set_hash'] ?? null,
                isset($scoreResult['fit_score']) ? (int)$scoreResult['fit_score'] : null,
                (string)($scoreResult['verdict'] ?? ''),
                (string)($scoreResult['source'] ?? ''),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[IcpProfileSnapshot::recordRun] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * The latest pin recorded for a lead (for enrollment/review surfaces
     * that need "what did the last scoring run see?").
     *
     * @return array{lead_id:int,profile_snapshot_id:?int,evidence_set_hash:?string,
     *               fit_score:?int,verdict:string,source:string,created_at:string}|null
     */
    public static function latestPinForLead(?\App\PDO $pdo, int $leadId): ?array
    {
        try {
            if ($pdo === null || !self::tableReady($pdo, self::RUNS_TABLE)) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT lead_id, profile_snapshot_id, evidence_set_hash,
                        fit_score, verdict, source, created_at
                 FROM ' . self::RUNS_TABLE . '
                 WHERE lead_id = ? ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$leadId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            error_log('[IcpProfileSnapshot::latestPinForLead] ' . $e->getMessage());
            return null;
        }
    }

    /** information_schema table-exists check (repo convention). */
    private static function tableReady(?\App\PDO $pdo, string $table): bool
    {
        if ($pdo === null) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS c FROM information_schema.TABLES ' .
                'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            return $row !== false && (int)$row['c'] > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Reset hook for tests that swap tableReady() state between scratch-DB
     * sections. (No static caches exist today; kept for API symmetry with
     * SequenceManager::resetReadyCache().)
     */
    public static function resetForTests(): void
    {
    }
}
