<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;
use App\Icp\IcpProfile;

/**
 * Human review queue for borderline leads (goal_67693fcbba4c, review workflow).
 *
 * Leads whose weighted ICP fit score lands in the 50-75 band are routed to
 * leads.status = 'Needs Review' by QualifyLeadAction (subject 2's routing;
 * the ENUM value itself comes from
 * migrations/2026-09-28-lead-review-status.sql). This class owns the human
 * side of that loop:
 *
 *   - listQueue():  paginated 'Needs Review' leads with per-dimension scores
 *                   (parsed from the leads.notes qualification marker), plus
 *                   a 'decided' view of already-reviewed items (who/when).
 *   - transition(): approve (-> 'Qualified', sequence-eligible) or
 *                   disqualify (-> 'Unqualified', never mailed). The status
 *                   change and the review_decisions audit row are written in
 *                   one transaction; the lead must actually be in
 *                   'Needs Review' at write time (no blind writes), and a
 *                   re-POST of an already-recorded decision is a no-op that
 *                   reports already_decided=true instead of duplicating
 *                   the audit row.
 *
 * Fail-closed: unknown decisions, unknown lead IDs, and transitions from
 * any status other than 'Needs Review' throw OutreachException and change
 * nothing. This class never writes the 'Needs Review' status itself — that
 * is the scorer's job.
 */
class ReviewQueue
{
    /** The contract status value on leads.status (subject 1's ENUM value). */
    public const REVIEW_STATUS = 'Needs Review';

    /** Status targets after a human decision. 'Qualified' is in
     *  SequenceManager::ELIGIBLE_LEAD_STATUSES / SENDABLE_LEAD_STATUSES;
     *  'Unqualified' is in neither, so approval is the only path back to
     *  eligibility. */
    public const STATUS_APPROVED = 'Qualified';
    public const STATUS_DISQUALIFIED = 'Unqualified';

    public const DECISION_APPROVED = 'approved';
    public const DECISION_DISQUALIFIED = 'disqualified';

    /** API action allowlist for api/review.php. */
    public const ACTIONS = ['list', 'approve', 'disqualify'];

    public const FILTER_QUEUE = 'queue';
    public const FILTER_DECIDED = 'decided';

    /**
     * Map a review decision to its leads.status target.
     */
    public static function targetStatus(string $decision): string
    {
        return match ($decision) {
            self::DECISION_APPROVED => self::STATUS_APPROVED,
            self::DECISION_DISQUALIFIED => self::STATUS_DISQUALIFIED,
            default => throw new OutreachException("Invalid review decision '{$decision}'."),
        };
    }

    /**
     * Parse per-dimension scores from the leads.notes qualification marker.
     *
     * QualifyLeadAction::notesMarker() appends
     *   "Dimensions: company_size=8/10, industry_fit=7/10, ..."
     * as the LAST line of the marker block (see
     * AdjustIcpWeightsAction::parseNotesDimensionScores, which seeds the
     * same format). Markers are only ever appended, so the LAST
     * "Dimensions:" line in the notes is the latest scoring run.
     *
     * @return array<string,int> dimension_key => score (1-10), possibly empty
     *                           when the lead was scored by the legacy path.
     */
    public static function parseDimensionScores(string $notes): array
    {
        $scores = [];
        if ($notes === '') {
            return $scores;
        }
        $lines = preg_split('/\r\n|\r|\n/', $notes);
        if (!is_array($lines)) {
            return $scores;
        }
        // Walk from the end: the latest marker's Dimensions line wins.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^Dimensions:\s*(.+)$/i', trim($lines[$i]), $m)) {
                foreach (explode(',', $m[1]) as $part) {
                    if (preg_match('/^\s*([a-z_]+)\s*=\s*(\d{1,2})\s*\/\s*10\s*\.?\s*$/i', $part, $d)) {
                        $key = strtolower($d[1]);
                        if (in_array($key, IcpProfile::DIMENSIONS, true)) {
                            $scores[$key] = max(1, min(10, (int)$d[2]));
                        }
                    }
                }
                break; // latest marker only
            }
        }
        return $scores;
    }

    /**
     * Whether the review_decisions audit table exists (the
     * 2026-09-28-review-decisions migration applied). Lets the API fail
     * with a clear 503 instead of a raw 500 on a pre-migration install.
     */
    public static function auditTableExists(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()" .
                " AND TABLE_NAME = 'review_decisions' LIMIT 1"
            );
            $stmt->execute();
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Paginated review queue / decision history.
     *
     * @param string $filter 'queue' (awaiting review) or 'decided' (already reviewed)
     * @return array{total:int, rows:array<int,array>}
     */
    public static function listQueue(PDO $pdo, string $filter, int $limit, int $offset): array
    {
        $limit = min(max($limit, 1), 100);
        $offset = max($offset, 0);

        if ($filter === self::FILTER_DECIDED) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM review_decisions');
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT rd.id, rd.lead_id, rd.decision, rd.decided_by, rd.decided_at,' .
                ' rd.previous_status, rd.fit_score_snapshot,' .
                ' l.company_name, l.contact_name, l.email, l.status AS current_status' .
                ' FROM review_decisions rd' .
                ' JOIN leads l ON l.id = rd.lead_id' .
                ' ORDER BY rd.decided_at DESC, rd.id DESC LIMIT ? OFFSET ?'
            );
            $stmt->execute([$limit, $offset]);
            return ['total' => $total, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }

        // Default: the queue of leads awaiting human review.
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE status = ?');
        $countStmt->execute([self::REVIEW_STATUS]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, company_name, contact_name, email, website, lead_score, status, notes, created_at' .
            ' FROM leads WHERE status = ?' .
            ' ORDER BY lead_score DESC, created_at ASC LIMIT ? OFFSET ?'
        );
        $stmt->execute([self::REVIEW_STATUS, $limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['dimensions'] = self::parseDimensionScores((string)($row['notes'] ?? ''));
            unset($row['notes']); // keep the payload lean; dimensions carry the scoring detail
        }
        unset($row);
        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Apply a human review decision.
     *
     * Transactional: the guarded status UPDATE (only from 'Needs Review')
     * and the review_decisions INSERT either both land or neither does.
     * The notes marker is appended in the same UPDATE (never overwritten —
     * Phase 0 defect C2 rule).
     *
     * Idempotent re-POSTs: if the lead already carries the requested
     * decision (status already flipped AND a matching audit row exists),
     * nothing is written and ['already_decided' => true] is returned.
     *
     * @return array{already_decided:bool, lead_id:int, decision:string, new_status:string}
     * @throws OutreachException on invalid decision, unknown lead, or a
     *         transition guard violation (409 semantics for the API layer).
     */
    public static function transition(PDO $pdo, int $leadId, string $decision, string $decidedBy): array
    {
        $target = self::targetStatus($decision); // throws on unknown decision
        if ($leadId <= 0) {
            throw new OutreachException('Invalid lead id.');
        }
        $decidedBy = trim($decidedBy);
        if ($decidedBy === '') {
            throw new OutreachException('Reviewer identity is required.');
        }

        $pdo->exec('START TRANSACTION');
        try {
            $stmt = $pdo->prepare(
                'SELECT id, status, lead_score, notes FROM leads WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                throw new OutreachException("Lead ID {$leadId} not found.");
            }

            $current = (string)($lead['status'] ?? '');
            $fitScore = (int)($lead['lead_score'] ?? 0);

            if ($current !== self::REVIEW_STATUS) {
                // Idempotent replay: the exact decision was already recorded.
                if ($current === $target && self::hasDecision($pdo, $leadId, $decision)) {
                    $pdo->exec('COMMIT');
                    return [
                        'already_decided' => true,
                        'lead_id' => $leadId,
                        'decision' => $decision,
                        'new_status' => $current,
                    ];
                }
                throw new OutreachException(
                    "Lead {$leadId} is not awaiting review (status '{$current}')."
                );
            }

            $date = date('Y-m-d');
            $marker = "\n\n[Human Review {$date}]: {$decision} by {$decidedBy} " .
                "(fit {$fitScore}/100) — status → {$target}";

            // Guarded write: the status predicate is part of the UPDATE, so a
            // concurrent decision on the same lead cannot silently double-apply.
            $upd = $pdo->prepare(
                'UPDATE leads SET status = ?, notes = CONCAT(COALESCE(notes, \'\'), ?)' .
                ' WHERE id = ? AND status = ?'
            );
            $upd->execute([$target, $marker, $leadId, self::REVIEW_STATUS]);
            if ($upd->rowCount() !== 1) {
                throw new OutreachException(
                    "Lead {$leadId} changed state during review; no decision recorded."
                );
            }

            $ins = $pdo->prepare(
                'INSERT INTO review_decisions' .
                ' (lead_id, decision, decided_by, previous_status, fit_score_snapshot)' .
                ' VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$leadId, $decision, $decidedBy, self::REVIEW_STATUS, $fitScore]);

            $pdo->exec('COMMIT');
            return [
                'already_decided' => false,
                'lead_id' => $leadId,
                'decision' => $decision,
                'new_status' => $target,
            ];
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
                // ROLLBACK failing means there is nothing to roll back.
            }
            throw $e;
        }
    }

    /**
     * Whether an identical decision was already recorded for this lead.
     */
    private static function hasDecision(PDO $pdo, int $leadId, string $decision): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM review_decisions WHERE lead_id = ? AND decision = ? LIMIT 1'
        );
        $stmt->execute([$leadId, $decision]);
        return (bool)$stmt->fetchColumn();
    }
}
