<?php

declare(strict_types=1);

namespace App\Verification;

class TrustScorer
{
    // Trust weights per specifications
    private const WEIGHTS = [
        'level_1' => 50,  // On-site extraction
        'level_2' => 30,  // Directory baseline
        'level_3' => 10,  // Search snippet corroboration
        'dns'     => 10,  // DNS receptivity
    ];

    /**
     * Compute the weighted trust score for a lead prospect.
     *
     * @param bool $hasLevel1 Contact found via on-site crawler extraction.
     * @param bool $hasLevel2 Contact confirmed in directory baseline (G-Maps, Yellow Pages).
     * @param bool $hasLevel3 Contact mentioned in general search engine snippets.
     * @param bool $dnsReceptive Target domain verified receptive to incoming mail.
     * @param float $level1Confidence Confidence multiplier for Level 1 (0.0 to 1.0).
     * @param float $level2Confidence Confidence multiplier for Level 2 (0.0 to 1.0).
     * @param bool $crossReferenceMatch True if Level 1 and Level 2 data points match.
     * @return array Contains 'trust_score', 'trust_tier', 'is_gold_standard', 'breakdown', 'verification_status'.
     */
    public static function computeTrustScore(
        bool $hasLevel1 = false,
        bool $hasLevel2 = false,
        bool $hasLevel3 = false,
        bool $dnsReceptive = false,
        float $level1Confidence = 1.0,
        float $level2Confidence = 1.0,
        bool $crossReferenceMatch = false
    ): array {
        $score = 0;
        $breakdown = [];

        // Level 1: On-site extraction
        if ($hasLevel1) {
            $l1Score = (int) round(self::WEIGHTS['level_1'] * $level1Confidence);
            $score += $l1Score;
            $breakdown['level_1'] = $l1Score;
        } else {
            $breakdown['level_1'] = 0;
        }

        // Level 2: Directory baseline
        if ($hasLevel2) {
            $l2Score = (int) round(self::WEIGHTS['level_2'] * $level2Confidence);
            $score += $l2Score;
            $breakdown['level_2'] = $l2Score;
        } else {
            $breakdown['level_2'] = 0;
        }

        // Level 3: Search snippet
        if ($hasLevel3) {
            $score += self::WEIGHTS['level_3'];
            $breakdown['level_3'] = self::WEIGHTS['level_3'];
        } else {
            $breakdown['level_3'] = 0;
        }

        // DNS Bonus
        if ($dnsReceptive) {
            $score += self::WEIGHTS['dns'];
            $breakdown['dns'] = self::WEIGHTS['dns'];
        } else {
            $breakdown['dns'] = 0;
        }

        // Cap trust score at 100
        $score = min($score, 100);

        // Determine highest achieved tier
        $tier = 'unverified';
        if ($hasLevel1) {
            $tier = 'level_1';
        } elseif ($hasLevel2) {
            $tier = 'level_2';
        } elseif ($hasLevel3) {
            $tier = 'level_3';
        }

        // Gold standard verification condition
        $isGold = ($crossReferenceMatch && $hasLevel1 && $hasLevel2);

        // Determine verification status string
        if ($isGold) {
            $status = 'gold_standard';
        } elseif ($crossReferenceMatch) {
            $status = 'cross_source_matched';
        } elseif ($hasLevel1 && $dnsReceptive) {
            $status = 'dns_confirmed';
        } elseif ($hasLevel1) {
            $status = 'evidence_backed';
        } else {
            $status = 'unverified';
        }

        return [
            'trust_score'         => $score,
            'trust_tier'          => $tier,
            'is_gold_standard'    => $isGold,
            'breakdown'           => $breakdown,
            'verification_status' => $status
        ];
    }
}
