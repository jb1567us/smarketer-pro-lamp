<?php

declare(strict_types=1);

namespace App\Icp;

/**
 * Weighted ICP (Ideal Customer Profile) data-model accessor.
 *
 * Read path for the scoring engine (qualification subject agent) and the
 * write path for manual weight edits from the settings UI. Automatic weight
 * adjustments from the auto-tuner also go through updateWeights() so every
 * change is recorded in icp_weight_history.
 *
 * Invariants:
 *  - weights on one profile always sum to exactly 100 (server-side enforced);
 *  - every weight change writes an icp_weight_history row (fail-closed: the
 *    update rolls back if the audit write fails);
 *  - manual edits mark the dimension buyer_locked so the auto-tuner skips it.
 */
class IcpProfile
{
    public const DIMENSIONS = [
        'company_size',
        'industry_fit',
        'target_title',
        'geography',
        'trigger_signals',
    ];

    public const DIMENSION_LABELS = [
        'company_size'    => 'Company size',
        'industry_fit'    => 'Industry fit',
        'target_title'    => 'Target title',
        'geography'       => 'Geography',
        'trigger_signals' => 'Trigger signals',
    ];

    public const QUALIFY_THRESHOLD_DEFAULT = 75;
    public const REVIEW_THRESHOLD_DEFAULT = 50;

    public const EXCLUSION_TYPES = ['industry', 'company', 'domain', 'title', 'keyword'];

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * The active profile row, or null when no profile is active.
     * Fail-closed: a missing active profile is treated as "not configured",
     * never as a default that qualifies leads.
     *
     * @return array{id:int,name:string,pain_statement:string|null,is_active:int}|null
     */
    public static function active(): ?array
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->query(
            "SELECT id, name, pain_statement, is_active FROM icp_profiles
             WHERE is_active = 1 ORDER BY id LIMIT 1"
        );
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{id:int,name:string,pain_statement:string|null}|null */
    public static function get(int $profileId): ?array
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT id, name, pain_statement FROM icp_profiles WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$profileId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Dimensions keyed by dimension_key:
     * ['company_size' => ['weight'=>17,'buyer_locked'=>0,'target_config'=>[...]], ...]
     */
    public static function dimensions(int $profileId): array
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT dimension_key, weight, buyer_locked, target_config
             FROM icp_dimensions WHERE profile_id = ?"
        );
        $stmt->execute([$profileId]);
        $out = [];
        while ($row = $stmt->fetch(\App\PDO::FETCH_ASSOC)) {
            $config = [];
            if ($row['target_config'] !== null && $row['target_config'] !== '') {
                $decoded = json_decode((string)$row['target_config'], true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }
            $out[(string)$row['dimension_key']] = [
                'weight'        => (int)$row['weight'],
                'buyer_locked'  => (int)$row['buyer_locked'] === 1,
                'target_config' => $config,
            ];
        }
        return $out;
    }

    /** dimension_key => weight for every dimension on the profile. */
    public static function weights(int $profileId): array
    {
        $weights = [];
        foreach (self::dimensions($profileId) as $key => $dim) {
            $weights[$key] = $dim['weight'];
        }
        return $weights;
    }

    /**
     * Anti-persona veto rows for the profile.
     * @return list<array{id:int,exclusion_type:string,value:string,note:string|null}>
     */
    public static function exclusions(int $profileId): array
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT id, exclusion_type, value, note FROM icp_exclusions
             WHERE profile_id = ? ORDER BY exclusion_type, value"
        );
        $stmt->execute([$profileId]);
        return $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
    }

    /**
     * Scoring thresholds read from the settings table.
     * @return array{qualify:int,review:int}
     */
    public static function thresholds(): array
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT setting_key, setting_value FROM settings
             WHERE setting_key IN ('icp_threshold_qualify','icp_threshold_review')"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(\App\PDO::FETCH_KEY_PAIR);
        $qualify = isset($rows['icp_threshold_qualify']) ? (int)$rows['icp_threshold_qualify'] : self::QUALIFY_THRESHOLD_DEFAULT;
        $review = isset($rows['icp_threshold_review']) ? (int)$rows['icp_threshold_review'] : self::REVIEW_THRESHOLD_DEFAULT;
        // Fail-closed: clamp to a sane range; a missing/zero qualify threshold
        // must never auto-qualify everything.
        $qualify = max(1, min(100, $qualify));
        $review = max(0, min(99, $review));
        if ($review >= $qualify) {
            $review = $qualify - 1;
        }
        return ['qualify' => $qualify, 'review' => $review];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Replace the full weight vector for a profile.
     *
     * Validates: every known dimension present exactly once, each weight an
     * int 0-100, and the vector sums to exactly 100. Records one
     * icp_weight_history row per changed dimension and marks touched
     * dimensions buyer_locked when $buyerSet is true (manual UI edit).
     *
     * @param array<string,int> $weights dimension_key => weight
     * @throws \InvalidArgumentException on validation failure (no writes).
     * @throws \App\Exceptions\OutreachException on DB failure (rolled back).
     */
    public static function updateWeights(
        int $profileId,
        array $weights,
        string $reason = '',
        ?int $sampleSize = null,
        bool $buyerSet = false,
        string $createdBy = 'user'
    ): void {
        $known = array_fill_keys(self::DIMENSIONS, null);
        $diff = array_diff_key($weights, $known);
        if ($diff !== []) {
            throw new \InvalidArgumentException(
                'Unknown ICP dimension(s): ' . implode(', ', array_keys($diff))
            );
        }
        $missing = array_diff_key($known, $weights);
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Missing weight(s) for dimension(s): ' . implode(', ', array_keys($missing))
            );
        }

        $normalized = [];
        foreach ($weights as $key => $weight) {
            if (!is_int($weight) && !(is_string($weight) && ctype_digit($weight))) {
                throw new \InvalidArgumentException("Weight for {$key} must be an integer 0-100");
            }
            $w = (int)$weight;
            if ($w < 0 || $w > 100) {
                throw new \InvalidArgumentException("Weight for {$key} must be between 0 and 100");
            }
            $normalized[$key] = $w;
        }
        if (array_sum($normalized) !== 100) {
            throw new \InvalidArgumentException(
                'ICP weights must sum to exactly 100 (got ' . array_sum($normalized) . ')'
            );
        }
        if (!in_array($createdBy, ['user', 'auto_tuner'], true)) {
            $createdBy = 'user';
        }

        $pdo = \App\Database::getConnection();
        $current = self::weights($profileId);
        if ($current === []) {
            throw new \InvalidArgumentException("Unknown ICP profile {$profileId}");
        }

        try {
            // App\PDO is a mysqli shim without beginTransaction()/commit()/
            // rollBack(); use raw SQL transaction statements (repo convention,
            // see api/import_leads.php).
            $pdo->exec('START TRANSACTION');
            $updStmt = $pdo->prepare(
                "UPDATE icp_dimensions SET weight = ?" .
                ($buyerSet ? ", buyer_locked = 1" : "") .
                " WHERE profile_id = ? AND dimension_key = ?"
            );
            $histStmt = $pdo->prepare(
                "INSERT INTO icp_weight_history
                 (profile_id, dimension_key, old_weight, new_weight, reason, sample_size, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($normalized as $key => $w) {
                $old = (int)($current[$key] ?? 0);
                $updStmt->execute([$w, $profileId, $key]);
                if ($old !== $w) {
                    $histStmt->execute([
                        $profileId, $key, $old, $w,
                        substr($reason, 0, 255) ?: null,
                        $sampleSize,
                        $createdBy,
                    ]);
                }
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
                // best-effort rollback; original failure is what matters
            }
            error_log('[IcpProfile::updateWeights] ' . $e->getMessage());
            throw new \App\Exceptions\OutreachException('ICP weight update failed: ' . $e->getMessage());
        }
    }

    /**
     * Clear the buyer lock on one dimension (auto-tuner may adjust it again).
     */
    public static function unlockDimension(int $profileId, string $dimensionKey): void
    {
        if (!in_array($dimensionKey, self::DIMENSIONS, true)) {
            throw new \InvalidArgumentException("Unknown ICP dimension: {$dimensionKey}");
        }
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE icp_dimensions SET buyer_locked = 0
             WHERE profile_id = ? AND dimension_key = ?"
        );
        $stmt->execute([$profileId, $dimensionKey]);
    }

    /**
     * Replace the target_config for one dimension.
     *
     * @param array<string,mixed> $config dimension-specific targets.
     */
    public static function updateTargetConfig(int $profileId, string $dimensionKey, array $config): void
    {
        if (!in_array($dimensionKey, self::DIMENSIONS, true)) {
            throw new \InvalidArgumentException("Unknown ICP dimension: {$dimensionKey}");
        }
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE icp_dimensions SET target_config = ?
             WHERE profile_id = ? AND dimension_key = ?"
        );
        $json = json_encode($config, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException("target_config for {$dimensionKey} is not JSON-encodable");
        }
        $stmt->execute([$json, $profileId, $dimensionKey]);
    }

    /**
     * Replace the pain statement on the profile (one sentence, fail-closed:
     * blank or over-long input is rejected rather than stored half-formed).
     */
    public static function updatePainStatement(int $profileId, string $painStatement): void
    {
        $painStatement = trim($painStatement);
        if ($painStatement === '' || mb_strlen($painStatement) > 500) {
            throw new \InvalidArgumentException('Pain statement must be 1-500 characters.');
        }
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE icp_profiles SET pain_statement = ? WHERE id = ?"
        );
        $stmt->execute([$painStatement, $profileId]);
    }

    /** @return int the new exclusion id */
    public static function addExclusion(
        int $profileId,
        string $type,
        string $value,
        ?string $note = null
    ): int {
        if (!in_array($type, self::EXCLUSION_TYPES, true)) {
            throw new \InvalidArgumentException(
                'exclusion_type must be one of: ' . implode(', ', self::EXCLUSION_TYPES)
            );
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 255) {
            throw new \InvalidArgumentException('Exclusion value must be 1-255 characters.');
        }
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO icp_exclusions (profile_id, exclusion_type, value, note)
             VALUES (?, ?, ?, ?)"
        );
        try {
            $stmt->execute([$profileId, $type, $value, $note === null ? null : substr($note, 0, 500)]);
        } catch (\App\PDOException $e) {
            // Duplicate key (1062) on (profile, type, value) — surface a clean
            // error; anything else is a real DB failure (fail-closed).
            if ((int)$e->getCode() === 1062) {
                throw new \InvalidArgumentException('That exclusion is already on the list.');
            }
            error_log('[IcpProfile::addExclusion] ' . $e->getMessage());
            throw new \App\Exceptions\OutreachException('Failed to add exclusion: ' . $e->getMessage());
        }
        return (int)$pdo->lastInsertId();
    }

    public static function deleteExclusion(int $profileId, int $exclusionId): void
    {
        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            "DELETE FROM icp_exclusions WHERE id = ? AND profile_id = ?"
        );
        $stmt->execute([$exclusionId, $profileId]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Exclusion not found.');
        }
    }
}
