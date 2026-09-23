<?php

declare(strict_types=1);

namespace App;

/**
 * FIX2 — verified funnel stats.
 *
 * Mechanical data-quality counts behind the dashboard's Lead Funnel tile.
 * Definitions (raw DB counts, no marketing language):
 *   harvested      — every row in leads (raw; unverified by definition)
 *   verified_valid — verification_status = 'valid' (actually checked by a
 *                    verification provider at some point)
 *   invalid        — verification_status = 'invalid'
 *   risky          — verification_status = 'risky'
 *   unknown        — verification_status = 'unknown'; never actually verified
 *   checked        — valid + invalid + risky
 *   suppressed     — leads whose normalized email is on suppression_list
 *   mailable       — verified valid AND not suppressed
 *
 * "Mailable" is NOT "ready to send": the per-send gates (fresh verification
 * verdict, consent/CASL country gate, throttles, DNS preflight, suppression)
 * still apply at send time. The dashboard caption states exactly what the
 * number means so it cannot be misread as a send-ready count.
 */
final class FunnelStats
{
    public static function compute($pdo): array
    {
        $funnel = [
            'harvested'      => 0,
            'checked'        => 0,
            'verified_valid' => 0,
            'invalid'        => 0,
            'risky'          => 0,
            'unknown'        => 0,
            'suppressed'     => 0,
            'mailable'       => 0,
        ];
        try {
            $funnel['harvested'] = (int)$pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn();

            $byStatus = [];
            $rows = $pdo->query('SELECT verification_status, COUNT(*) AS c FROM leads GROUP BY verification_status')
                ->fetchAll(\App\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $byStatus[(string)$row['verification_status']] = (int)$row['c'];
            }
            $funnel['verified_valid'] = $byStatus['valid'] ?? 0;
            $funnel['invalid']        = $byStatus['invalid'] ?? 0;
            $funnel['risky']          = $byStatus['risky'] ?? 0;
            $funnel['unknown']        = $byStatus['unknown'] ?? 0;
            $funnel['checked']        = $funnel['verified_valid'] + $funnel['invalid'] + $funnel['risky'];

            // Suppression rows store normalized (lower, trimmed) emails —
            // see Compliance::suppress(). GDPR hash-only rows have no
            // plaintext match; their lead rows are erased anyway, so they
            // would not be counted in `leads` at all.
            $funnel['suppressed'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM leads l WHERE EXISTS (" .
                "SELECT 1 FROM suppression_list s WHERE s.email = LOWER(TRIM(l.email)))"
            )->fetchColumn();

            $funnel['mailable'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM leads l WHERE l.verification_status = 'valid' AND NOT EXISTS (" .
                "SELECT 1 FROM suppression_list s WHERE s.email = LOWER(TRIM(l.email)))"
            )->fetchColumn();
        } catch (\Throwable $e) {
            // Degrade to zeros on DBs without the verification columns or
            // the suppression table — never break the stats endpoint.
            error_log('[FunnelStats] lookup failed: ' . $e->getMessage());
        }
        return $funnel;
    }
}
