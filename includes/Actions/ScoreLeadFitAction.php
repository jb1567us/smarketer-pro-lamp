<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\OutreachException;
use App\Icp\IcpProfile;
use App\Jev\DecisionTier;
use App\Jev\JevProvider;

/**
 * ScoreLeadFitAction — weighted ICP fit scoring for a lead.
 *
 * ONE batched Jev decide() call answers one independent score question per
 * ENABLED ICP dimension (1-10 each, ordered criteria), each converted to
 * 0-100 via JevProvider::scoreToPercent() and combined with the active ICP
 * profile's weights into a single fit score 0-100. Disabled (optional)
 * dimensions get no question at all (zero tokens) and are excluded from
 * aggregation; the enabled weights renormalize to sum 100 at scoring time.
 *
 * Order of operations:
 *   1. HARD VETO FIRST: the profile's icp_exclusions are checked against the
 *      lead (industry/company/domain/title/keyword match). A hit forces
 *      fit = 0, qualified = false, and cites the matched exclusion — no Jev
 *      call is made. The veto is a deterministic ICP rule and applies in
 *      every DecisionTier mode, including "off".
 *   2. No active / unreadable / incomplete ICP profile -> legacy path only
 *      (fail-closed: never invent a score without a profile).
 *   3. Otherwise the weighted Jev decision runs through DecisionTier:
 *        off    — the legacy single-question qualification result is
 *                 returned, exactly as before. Jev never runs.
 *        shadow — legacy result returned; the Jev per-dimension scores,
 *                 weighted fit score, and Jev-vs-legacy agreement are logged
 *                 to logs/jev_shadow.jsonl (via the extract/agree closures,
 *                 which extend the existing shadow record). Zero behavior change.
 *        live   — the Jev weighted result is returned. Jev error, timeout, or
 *                 sub-threshold confidence falls back to the legacy result
 *                 (fail-closed to the existing behavior).
 *
 * The legacy fallback is injected as a callable so this action never
 * duplicates the legacy qualification logic — QualifyLeadAction owns it.
 *
 * @see QualifyLeadAction for the pipeline entry point (status mapping + notes).
 */
class ScoreLeadFitAction extends AbstractAction
{
    public const DECISION_NAME = 'lead_fit.score_fit';

    /**
     * Hard per-call timeout (seconds) for the batched fit-scoring call.
     * Mirrors QualifyLeadAction::JEV_TIMEOUT_SECONDS — the pipeline never
     * hangs waiting for the decision API.
     */
    public const JEV_TIMEOUT_SECONDS = 20;

    /**
     * Test seam for profile resolution (tests must not hit the static DB).
     *   null  = resolve via IcpProfile (production behavior)
     *   array = use this snapshot instead of resolving
     *   false = force "no usable profile" (legacy-only path)
     * @var array|null|false
     */
    public static $profileOverride = null;

    /**
     * ActionInterface contract. The pipeline entry point for qualification is
     * QualifyLeadAction::execute (it owns status transitions); this wrapper
     * routes there so a bare execute() on this action can never leave a lead
     * half-scored.
     */
    public function execute(int $leadId): bool
    {
        $qualify = new QualifyLeadAction($this->pdo, $this->llmRouter);
        return $qualify->execute($leadId);
    }

    /**
     * Score a lead's ICP fit.
     *
     * @param array<string,mixed> $lead           Lead row (company_name, contact_name,
     *                                            email, website, notes, target_persona, ...).
     * @param callable            $legacyFallback Zero-arg callable returning the legacy
     *                                            qualification shape
     *                                            ['qualified'=>bool,'score'=>0-100,
     *                                             'reason'=>string,'source'=>string].
     * @param array|null          $profileSnapshot Optional resolved profile (same shape
     *                                            as resolveProfile() returns); used by
     *                                            tests to avoid static DB reads.
     *
     * @return array{qualified:bool,verdict:string,fit_score:int,score:int,
     *               dimensions:array<string,int>,dimension_pcts:array<string,float>,
     *               reason:string,source:string,veto:?array,latency_ms:int,profile:string}
     *   verdict: 'qualified' | 'needs_review' | 'unqualified'
     *   source:  'jev' | 'veto' | 'legacy'
     */
    public function score(array $lead, callable $legacyFallback, ?array $profileSnapshot = null): array
    {
        if ($profileSnapshot !== null) {
            $profile = $profileSnapshot;
        } elseif (self::$profileOverride === false) {
            $profile = null; // test seam: force "no usable profile"
        } elseif (is_array(self::$profileOverride)) {
            $profile = self::$profileOverride;
        } else {
            $profile = self::resolveProfile();
        }

        // --- 1. Hard veto first: no Jev call needed on a hit ----------------
        if ($profile !== null) {
            $veto = self::findVeto($lead, $profile['exclusions']);
            if ($veto !== null) {
                return self::vetoResult($lead, $veto, $profile);
            }
        }

        // --- 2. No usable profile -> legacy only (fail-closed) --------------
        if ($profile === null) {
            $legacy = self::safeLegacy($legacyFallback);
            return self::normalizeLegacy($legacy, '(no active ICP profile)');
        }

        $weights = $profile['weights'];
        $thresholds = $profile['thresholds'];
        // Only enabled dimensions are asked and aggregated. Default-off
        // (tech_stack disabled) is mathematically identical to the
        // five-dimension model: the disabled weight is dropped and the
        // remaining weights renormalize to sum 100.
        $enabled = self::enabledKeys($profile['dimensions']);
        $questions = self::buildFitQuestions($profile['dimensions']);

        $state = [
            'persona' => 'B2B ICP Specialist',
            'goal' => 'Score this lead\'s fit against the active ICP profile on ' . count($enabled) . ' dimensions.',
            'lead_context' => self::leadContext($lead),
            'icp_profile' => $profile['key'],
            'dimensions_scored' => $enabled,
        ];

        // Extract/​agree extend the existing shadow JSONL record: jev_value
        // carries the weighted fit score AND the per-dimension pcts; llm_value
        // carries the legacy verdict/score. Agreement reuses the established
        // rule (same binary act-decision and score within 15 points).
        // (Public static factories so tests can exercise the exact production
        // closures through DecisionTier::decide without the timeout override —
        // the override path builds its own provider and cannot take a scripted
        // one, same as the other Phase 4 decision points.)
        $extract = self::shadowExtract($weights, $thresholds, $enabled);
        $agree = self::shadowAgree($thresholds);

        $t0 = microtime(true);
        try {
            $raw = DecisionTier::decide(
                self::DECISION_NAME,
                $state,
                $questions,
                $legacyFallback,
                $extract,
                $agree,
                self::JEV_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            // Belt-and-braces: DecisionTier already fails over internally;
            // a throw here must still never break the pipeline.
            error_log('[ScoreLeadFitAction] DecisionTier::decide threw: ' . $e->getMessage());
            $raw = self::safeLegacy($legacyFallback);
        }
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        if (self::isJevFitAnswers($raw, $enabled)) {
            $norm = self::normalizeJevAnswers($raw, $weights, $thresholds, $enabled);
            $norm['reason'] = self::jevReason($norm, $profile, $enabled);
            $norm['veto'] = null;
            $norm['latency_ms'] = $latencyMs;
            $norm['profile'] = $profile['key'];
            $norm['thresholds'] = $thresholds;
            // 'score' aliases fit_score for legacy-shape compatibility.
            $norm['score'] = $norm['fit_score'];
            return $norm;
        }

        $legacy = self::normalizeLegacy(is_array($raw) ? $raw : [], '', $thresholds);
        $legacy['latency_ms'] = $latencyMs;
        $legacy['profile'] = $profile['key'];
        return $legacy;
    }

    // ------------------------------------------------------------------
    // Profile resolution (fail-closed)
    // ------------------------------------------------------------------

    /**
     * Resolve the active ICP profile into the snapshot shape score() uses.
     * Returns null when no profile is active, the configuration is
     * incomplete, or the tables are unreadable — callers then take the
     * legacy path. Never throws.
     *
     * @return array{id:int,key:string,dimensions:array,weights:array<string,int>,
     *               exclusions:array,thresholds:array{qualify:int,review:int}}|null
     */
    public static function resolveProfile(): ?array
    {
        try {
            $active = IcpProfile::active();
            if (!is_array($active) || !isset($active['id'])) {
                return null;
            }
            $profileId = (int)$active['id'];
            $dimensions = IcpProfile::dimensions($profileId);
            foreach (IcpProfile::DIMENSIONS as $dim) {
                if (!isset($dimensions[$dim]) || !is_array($dimensions[$dim])) {
                    error_log("[ScoreLeadFitAction] ICP profile {$profileId} missing dimension '{$dim}'.");
                    return null;
                }
            }
            $weights = IcpProfile::weights($profileId);
            $weightSum = array_sum($weights);
            if ($weightSum <= 0) {
                error_log("[ScoreLeadFitAction] ICP profile {$profileId} has no positive weights.");
                return null;
            }
            return [
                'id' => $profileId,
                'key' => (string)($active['name'] ?? ('profile-' . $profileId)),
                'dimensions' => $dimensions,
                'weights' => $weights,
                'exclusions' => IcpProfile::exclusions($profileId),
                'thresholds' => IcpProfile::thresholds(),
            ];
        } catch (\Throwable $e) {
            // Missing tables, bad connection, corrupt config — fail closed to
            // the legacy qualification path, and say why.
            error_log('[ScoreLeadFitAction] ICP profile unreadable, using legacy qualification: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Shadow extract/agree (extend the existing shadow JSONL logging)
    // ------------------------------------------------------------------

    /**
     * The extract closure passed to DecisionTier::decide. In shadow mode the
     * record's jev_value carries the weighted fit score plus the per-dimension
     * pcts, and llm_value carries the legacy verdict/score — this is how the
     * per-dimension Jev scores land in logs/jev_shadow.jsonl without any
     * change to DecisionTier.
     *
     * @param array<string,int> $weights
     * @param array{qualify:int,review:int} $thresholds
     * @param string[]|null $enabledDims enabled dimension keys; null = all
     *   IcpProfile::DIMENSIONS (the maximal interpretation).
     */
    public static function shadowExtract(array $weights, array $thresholds, ?array $enabledDims = null): callable
    {
        $enabled = $enabledDims ?? IcpProfile::DIMENSIONS;
        return function ($a) use ($weights, $thresholds, $enabled) {
            if (self::isJevFitAnswers($a, $enabled)) {
                $norm = self::normalizeJevAnswers($a, $weights, $thresholds, $enabled);
                return [
                    'verdict' => $norm['verdict'],
                    'fit_score' => $norm['fit_score'],
                    'dimensions' => $norm['dimension_pcts'],
                    'source' => 'jev',
                ];
            }
            return [
                'verdict' => ($a['qualified'] ?? false) ? 'qualified' : 'unqualified',
                'fit_score' => (float)($a['score'] ?? 0),
                'dimensions' => [],
                'source' => is_array($a) ? ($a['source'] ?? 'legacy') : 'legacy',
            ];
        };
    }

    /**
     * Agreement rule: same binary act-decision (qualified vs not) AND the
     * weighted fit score within 15 points of the legacy score — the ±15 rule
     * the qualification decision point already used.
     */
    public static function shadowAgree(array $thresholds): callable
    {
        return function ($jv, $lv) use ($thresholds) {
            $qualifyAt = (int)($thresholds['qualify'] ?? 75);
            $jevActsQualified = (float)($jv['fit_score'] ?? 0) >= $qualifyAt;
            $legacyQualified = ($lv['verdict'] ?? 'unqualified') === 'qualified';
            return $jevActsQualified === $legacyQualified
                && abs((float)($jv['fit_score'] ?? 0) - (float)($lv['fit_score'] ?? 0)) <= 15;
        };
    }

    // ------------------------------------------------------------------
    // Hard veto
    // ------------------------------------------------------------------

    /**
     * Check the profile's exclusions against the lead. Returns the first
     * matching exclusion row, or null.
     *
     * Exclusion row shape (subject 1): ['exclusion_type'=>..., 'value'=>...,
     * 'note'=>...]. Matching is case-insensitive substring, except 'domain'
     * which compares normalized hosts (exact or subdomain suffix).
     */
    public static function findVeto(array $lead, array $exclusions): ?array
    {
        if ($exclusions === []) {
            return null;
        }
        $company = (string)($lead['company_name'] ?? '');
        $contact = (string)($lead['contact_name'] ?? '');
        $persona = (string)($lead['target_persona'] ?? '');
        $notes = (string)($lead['notes'] ?? '');
        $website = (string)($lead['website'] ?? '');
        $everything = $company . "\n" . $contact . "\n" . $persona . "\n" . $notes . "\n" . $website;
        $domains = self::leadDomains($lead);

        foreach ($exclusions as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $type = strtolower((string)($ex['exclusion_type'] ?? $ex['type'] ?? ''));
            $match = trim((string)($ex['value'] ?? $ex['match'] ?? ''));
            if ($match === '') {
                continue;
            }
            $hit = false;
            switch ($type) {
                case 'industry':
                    $hit = self::containsFold($notes . "\n" . $persona . "\n" . $company, $match);
                    break;
                case 'company':
                    $hit = self::containsFold($company, $match);
                    break;
                case 'domain':
                    $hit = self::domainMatches($domains, $match);
                    break;
                case 'title':
                    $hit = self::containsFold($contact . "\n" . $persona . "\n" . $notes, $match);
                    break;
                case 'keyword':
                    $hit = self::containsFold($everything, $match);
                    break;
                default:
                    // Unknown exclusion type: ignore the row (fail-open on the
                    // row, the veto list as a whole still applies). Logged so a
                    // typo'd type gets noticed instead of silently passing.
                    error_log("[ScoreLeadFitAction] Unknown exclusion_type '{$type}' — row ignored.");
                    break;
            }
            if ($hit) {
                return $ex;
            }
        }
        return null;
    }

    private static function vetoResult(array $lead, array $veto, array $profile): array
    {
        $type = (string)($veto['exclusion_type'] ?? $veto['type'] ?? 'exclusion');
        $value = (string)($veto['value'] ?? $veto['match'] ?? '');
        $note = trim((string)($veto['note'] ?? ''));
        $reason = "EXCLUDED by ICP exclusion [{$type}: \"{$value}\"]"
            . ($note !== '' ? " — {$note}" : '')
            . '. Fit score forced to 0; no Jev call made.';
        return [
            'qualified' => false,
            'verdict' => 'unqualified',
            'fit_score' => 0,
            'score' => 0,
            'dimensions' => [],
            'dimension_pcts' => [],
            'reason' => $reason,
            'source' => 'veto',
            'veto' => $veto,
            'latency_ms' => 0,
            'profile' => $profile['key'],
            'thresholds' => $profile['thresholds'],
        ];
    }

    private static function containsFold(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * Normalized hosts for the lead: email domain + website host,
     * lowercased, leading www. stripped.
     *
     * @return string[]
     */
    private static function leadDomains(array $lead): array
    {
        $domains = [];
        $email = (string)($lead['email'] ?? '');
        if (str_contains($email, '@')) {
            $domains[] = substr($email, (int)strrpos($email, '@') + 1);
        }
        $website = trim((string)($lead['website'] ?? ''));
        if ($website !== '') {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://' . $website, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[] = $host;
            }
        }
        $out = [];
        foreach ($domains as $d) {
            $d = strtolower(trim($d));
            $d = preg_replace('/^www\./', '', $d) ?? $d;
            if ($d !== '' && !in_array($d, $out, true)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    private static function domainMatches(array $domains, string $match): bool
    {
        $match = strtolower(ltrim(trim($match), '.'));
        $match = preg_replace('/^www\./', '', $match) ?? $match;
        foreach ($domains as $d) {
            if ($d === $match || str_ends_with($d, '.' . $match)) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Question building
    // ------------------------------------------------------------------

    /**
     * Enabled dimension keys for a resolved dimensions map, in canonical
     * IcpProfile::DIMENSIONS order. A dimension entry without an 'enabled'
     * key is treated as enabled (backward-compatible with fixtures that
     * predate the toggle flag); the production read path
     * (IcpProfile::dimensions()) always carries the flag.
     *
     * @param array<string,array{weight:int,buyer_locked?:bool,enabled?:bool,target_config?:array}> $dimensions
     * @return string[]
     */
    public static function enabledKeys(array $dimensions): array
    {
        $out = [];
        foreach (IcpProfile::DIMENSIONS as $dimKey) {
            if (!isset($dimensions[$dimKey]) || !is_array($dimensions[$dimKey])) {
                continue;
            }
            if (array_key_exists('enabled', $dimensions[$dimKey]) && !$dimensions[$dimKey]['enabled']) {
                continue;
            }
            $out[] = $dimKey;
        }
        return $out;
    }

    /**
     * Build the batched score questions for the ENABLED dimensions only.
     * Each question's instructions reference that dimension's target_config
     * (rendered to prose) from the active ICP profile. A disabled dimension
     * gets no question at all — zero tokens, zero aggregation weight.
     *
     * @param array<string,array{weight:int,buyer_locked?:bool,enabled?:bool,target_config?:array}> $dimensions
     * @return array<string,array> question key => Jev question
     */
    public static function buildFitQuestions(array $dimensions): array
    {
        $questions = [];
        foreach (self::enabledKeys($dimensions) as $dimKey) {
            $label = IcpProfile::DIMENSION_LABELS[$dimKey] ?? $dimKey;
            $targetProse = self::targetProse($dimKey, $dimensions[$dimKey]['target_config'] ?? []);
            if ($dimKey === 'tech_stack') {
                $questions['dim_' . $dimKey] = JevProvider::scoreQuestion(
                    self::techStackInstructions($targetProse),
                    self::techStackScoreLevels($label)
                );
                continue;
            }
            $questions['dim_' . $dimKey] = JevProvider::scoreQuestion(
                "Score this lead's {$label} against the ICP target. " .
                "Target: {$targetProse} " .
                'Use ONLY evidence present in the lead context — never invent evidence. ' .
                'When the context directly matches the stated target (a named item on the target list, ' .
                'a value inside the target range, an explicitly stated trigger), score 8-10: ' .
                'direct matches ARE strong evidence. ' .
                'In particular: if the lead context names an item from the target list word-for-word ' .
                '(or an obvious variant of it, e.g. "Salesforce CRM" for Salesforce), that dimension ' .
                'scores 9 or 10 — never 7 or below for a verbatim match. ' .
                'Score 4-7 when the evidence is partial, indirect, or merely suggestive. ' .
                'Score 2-3 when the evidence is thin or ambiguous. ' .
                'Answer 1 only when there is no evidence at all for this dimension.',
                self::scoreLevels($label)
            );
        }
        return $questions;
    }

    /**
     * Discoverability rubric for the tech_stack dimension (only ever asked
     * when the buyer has enabled it). The dimension measures HOW MUCH of the
     * target technology surface is discoverable in the evidence — not whether
     * the prospect's stack is "good". Nothing found = low score, and the
     * never-invent-evidence instruction stays prominent.
     */
    private static function techStackInstructions(string $targetProse): string
    {
        return "Score this lead's Tech stack on DISCOVERABILITY: how much of the target " .
            "technology surface was actually found in the lead context. This dimension " .
            "measures evidence availability, NOT whether the prospect's stack is good. " .
            "Target technology surface: {$targetProse} " .
            'Use ONLY evidence present in the lead context — never invent evidence. ' .
            'A technology you cannot see in the evidence was not found; score accordingly. ' .
            'Rubric: 9-10 = target tech surface confirmed from evidence (key technologies ' .
            'identified); 7-8 = partial, core buying-center tech (e.g. CRM) found; ' .
            '4-6 = thin hints only; 2-3 = minimal traces; 1 = nothing discoverable in ' .
            'the evidence.';
    }

    /**
     * Ten ordered levels for the tech_stack discoverability spectrum
     * (level 0 = worst). Mirrors scoreLevels() but in discoverability
     * language, so the model scores evidence availability, not stack quality.
     *
     * @return string[]
     */
    private static function techStackScoreLevels(string $label): array
    {
        return [
            "1 — No {$label} discoverability: no technology evidence in the lead context at all.",
            "2 — Minimal {$label} traces: at most one vague hint at a technology.",
            "3 — Weak {$label} discoverability: faint hints, nothing attributable to the target surface.",
            "4 — Thin {$label} hints: some technology mentioned, but not the target surface.",
            "5 — Partial {$label} evidence: scattered tech mentions, target surface mostly unconfirmed.",
            "6 — Moderate {$label} discoverability: part of the target surface evidenced, key gaps remain.",
            "7 — Good {$label} discoverability: core buying-center tech (e.g. CRM) found in evidence.",
            "8 — Strong {$label} discoverability: most of the target surface confirmed.",
            "9 — Near-complete {$label} discoverability: target tech surface confirmed from evidence (key technologies identified).",
            "10 — Exceptional {$label} discoverability: the full target surface confirmed on multiple strong evidence points.",
        ];
    }

    /**
     * Ten ordered levels for the 1-10 dimension spectrum (level 0 = worst).
     *
     * @return string[]
     */
    private static function scoreLevels(string $label): array
    {
        return [
            "1 — No {$label} fit: no supporting evidence in the lead context, or the evidence contradicts the target.",
            "2 — Very weak {$label} fit: at most one vague hint toward the target.",
            "3 — Weak {$label} fit: partial evidence with major gaps.",
            "4 — Below-average {$label} fit: some evidence, but important gaps remain.",
            "5 — Moderate {$label} fit: roughly half the target is evidenced.",
            "6 — Above-average {$label} fit: most of the target is evidenced, with notable gaps.",
            "7 — Strong {$label} fit: the target is largely met on solid evidence.",
            "8 — Very strong {$label} fit: nearly all of the target is met.",
            "9 — Near-perfect {$label} fit: the target is fully met with direct evidence.",
            "10 — Exceptional {$label} fit: the target is exceeded on multiple strong evidence points.",
        ];
    }

    /**
     * Render a dimension's target_config JSON into one prose sentence for the
     * question instructions. Falls back to a generic (documented) target when
     * the buyer has not configured one yet.
     */
    private static function targetProse(string $dimKey, $config): string
    {
        if (!is_array($config)) {
            $config = [];
        }
        $list = function ($v): string {
            $v = is_array($v) ? array_values(array_filter(array_map('strval', $v))) : [];
            return implode(', ', array_slice($v, 0, 12));
        };
        switch ($dimKey) {
            case 'company_size':
                $min = $config['min_employees'] ?? null;
                $max = $config['max_employees'] ?? null;
                if ($min !== null || $max !== null) {
                    $range = ($min !== null ? (int)$min . '+' : 'any size up to ')
                        . ($max !== null ? ' to ' . (int)$max : '') . ' employees';
                    return "Target company size: {$range}.";
                }
                return 'Target company size: SMB to mid-market (roughly 10-500 employees) — the size range this outreach operation sells to.';
            case 'industry_fit':
                $inc = $list($config['include'] ?? []);
                $exc = $list($config['exclude'] ?? []);
                if ($inc !== '' || $exc !== '') {
                    $t = 'Target industries' . ($inc !== '' ? ": {$inc}" : ' (buyer has not listed any yet)') . '.';
                    if ($exc !== '') {
                        $t .= " Avoid: {$exc}.";
                    }
                    return $t;
                }
                return 'Target industries: B2B industries that buy through proactive outreach (marketing agencies, B2B service firms, SaaS, consultancies, professional services).';
            case 'target_title':
                $titles = $list($config['titles'] ?? []);
                if ($titles !== '') {
                    return "Target contact titles: {$titles}.";
                }
                return 'Target contact: a decision-maker or budget influencer — founder, owner, co-founder, partner, or head/director/VP of marketing, sales, or growth.';
            case 'geography':
                $countries = $list($config['countries'] ?? []);
                $regions = $list($config['regions'] ?? []);
                $geo = implode(', ', array_filter([$countries, $regions]));
                if ($geo !== '') {
                    return "Target geography: {$geo}.";
                }
                return 'Target geography: regions the outreach operation can serve (US, UK, EU, Canada, Australia by default).';
            case 'trigger_signals':
                $signals = $list($config['signals'] ?? []);
                if ($signals !== '') {
                    return "Buying-trigger signals: {$signals}.";
                }
                return 'Buying-trigger signals: recent hiring (especially sales/marketing), funding rounds, expansion or new office openings, leadership changes.';
            case 'tech_stack':
                // Discoverability rubric (see techStackInstructions): the
                // buyer lists the technologies they sell into; the model
                // scores how much of that surface is actually found.
                $tools = $list($config['tools'] ?? []);
                if ($tools !== '') {
                    return "the target technology surface: {$tools}.";
                }
                return 'the target technology surface (the buyer has not listed target technologies yet — score what technology evidence the context reveals).';
            default:
                return 'the buyer-configured target for this dimension';
        }
    }

    // ------------------------------------------------------------------
    // Answer normalization
    // ------------------------------------------------------------------

    /**
     * @param string[]|null $enabledDims enabled dimension keys; null = all
     *   IcpProfile::DIMENSIONS (the maximal interpretation).
     */
    private static function isJevFitAnswers($a, ?array $enabledDims = null): bool
    {
        if (!is_array($a)) {
            return false;
        }
        foreach ($enabledDims ?? IcpProfile::DIMENSIONS as $dimKey) {
            $q = 'dim_' . $dimKey;
            if (!isset($a[$q]) || !is_array($a[$q]) || !array_key_exists('score', $a[$q])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Convert the raw Jev score answers into the weighted result.
     * Each answer's position (0..9) becomes 0-100 via scoreToPercent() and a
     * 1-10 display score; the fit score is the weight-weighted sum over the
     * ENABLED dimensions only, renormalized so the enabled weights sum to
     * 100. A disabled dimension's weight can never dilute the score — with
     * the default-off profile (five dims x 20) this is exactly the
     * five-dimension model.
     *
     * @param array<string,int> $weights dimension key => weight (0-100)
     * @param string[]|null $enabledDims enabled dimension keys; null = all
     *   IcpProfile::DIMENSIONS (the maximal interpretation).
     * @return array{qualified:bool,verdict:string,fit_score:int,dimensions:array<string,int>,
     *               dimension_pcts:array<string,float>,confidence:float,source:string}
     */
    public static function normalizeJevAnswers(
        array $answers,
        array $weights,
        array $thresholds,
        ?array $enabledDims = null
    ): array {
        $enabled = $enabledDims ?? IcpProfile::DIMENSIONS;
        $dimensions = [];
        $pcts = [];
        $confidences = [];
        // Belt-and-braces: weight keys for dimensions outside the enabled
        // set (unknown keys, or a stale row for a disabled dimension) must
        // never inflate the denominator and dilute every score.
        $weights = array_intersect_key($weights, array_fill_keys($enabled, true));
        $weightSum = max(1, (int)array_sum($weights));
        $fitAccum = 0.0;

        foreach ($enabled as $dimKey) {
            $ans = $answers['dim_' . $dimKey] ?? [];
            $position = (float)($ans['score'] ?? 0.0);
            $position = max(0.0, min(9.0, $position)); // clamp to the 10-level spectrum
            $pct = JevProvider::scoreToPercent($position, 10);
            $score10 = (int)max(1, min(10, round($position) + 1));
            $dimensions[$dimKey] = $score10;
            $pcts[$dimKey] = $pct;
            $fitAccum += $pct * ((int)($weights[$dimKey] ?? 0) / $weightSum);
            if (is_array($ans) && array_key_exists('confidence', $ans)) {
                $confidences[] = (float)$ans['confidence'];
            }
        }

        $fit = (int)round($fitAccum);
        $qualifyAt = (int)($thresholds['qualify'] ?? 75);
        $reviewAt = (int)($thresholds['review'] ?? 50);
        $verdict = $fit >= $qualifyAt ? 'qualified'
            : ($fit >= $reviewAt ? 'needs_review' : 'unqualified');

        return [
            'qualified' => $verdict === 'qualified',
            'verdict' => $verdict,
            'fit_score' => $fit,
            'dimensions' => $dimensions,
            'dimension_pcts' => $pcts,
            'confidence' => $confidences === [] ? 1.0 : min($confidences),
            'source' => 'jev',
        ];
    }

    /**
     * Normalize a legacy qualification result into the score() result shape.
     */
    private static function normalizeLegacy(array $legacy, string $prefixNote, ?array $thresholds = null): array
    {
        $qualified = (bool)($legacy['qualified'] ?? false);
        $score = (int)round((float)($legacy['score'] ?? 0));
        $reason = trim((string)($legacy['reason'] ?? ''));
        if ($prefixNote !== '') {
            $reason = trim($prefixNote . ($reason !== '' ? ' ' . $reason : ''));
        }
        $thresholds = $thresholds ?? [
            'qualify' => IcpProfile::QUALIFY_THRESHOLD_DEFAULT,
            'review' => IcpProfile::REVIEW_THRESHOLD_DEFAULT,
        ];
        return [
            'qualified' => $qualified,
            'verdict' => $qualified ? 'qualified' : 'unqualified',
            'fit_score' => $score,
            'score' => $score,
            'dimensions' => [],
            'dimension_pcts' => [],
            'reason' => $reason,
            'source' => 'legacy',
            'veto' => null,
            'latency_ms' => 0,
            'profile' => '',
            'thresholds' => $thresholds,
        ];
    }

    private static function jevReason(array $norm, array $profile, ?array $enabledDims = null): string
    {
        $parts = [];
        foreach ($enabledDims ?? IcpProfile::DIMENSIONS as $dimKey) {
            $parts[] = $dimKey . '=' . ($norm['dimensions'][$dimKey] ?? 0) . '/10';
        }
        $verdictLabel = [
            'qualified' => 'qualified',
            'needs_review' => 'needs human review',
            'unqualified' => 'not qualified',
        ][$norm['verdict']] ?? $norm['verdict'];
        return sprintf(
            'Jev weighted ICP fit %d/100 (profile "%s", confidence %.2f) — %s. %s.',
            $norm['fit_score'],
            $profile['key'],
            $norm['confidence'],
            $verdictLabel,
            implode(', ', $parts)
        );
    }

    /**
     * Run the legacy fallback without ever letting it throw into the caller.
     */
    private static function safeLegacy(callable $legacyFallback): array
    {
        try {
            $result = $legacyFallback();
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            error_log('[ScoreLeadFitAction] Legacy fallback threw: ' . $e->getMessage());
            return [
                'qualified' => false,
                'score' => 0,
                'reason' => 'Legacy qualification unavailable (' . $e->getMessage() . '); fail-closed to unqualified.',
                'source' => 'legacy',
            ];
        }
    }

    /**
     * Lead context for the Jev state: the same fields the legacy qualifier
     * consumes (see EnrichSufficiencyAction), plus enrichment notes.
     */
    private static function leadContext(array $lead): string
    {
        $company = $lead['company_name'] ?? 'Unknown';
        $website = $lead['website'] ?? 'Unknown';
        $contact = $lead['contact_name'] ?? 'Unknown';
        $email = $lead['email'] ?? 'Unknown';
        $persona = $lead['target_persona'] ?? '';
        $country = $lead['country_code'] ?? '';
        $notes = trim((string)($lead['notes'] ?? ''));
        $context = "Company: {$company}\nWebsite: {$website}\nContact: {$contact}\nEmail: {$email}";
        if ($persona !== '') {
            $context .= "\nTarget persona: {$persona}";
        }
        if ($country !== '') {
            $context .= "\nCountry: {$country}";
        }
        if ($notes !== '') {
            $context .= "\nEnrichment notes:\n" . mb_substr($notes, 0, 4000);
        }
        return $context;
    }
}
