<?php

declare(strict_types=1);

namespace App\Jev;

use App\Database;

/**
 * Decision tier — routes decision-class calls to Jev with configurable modes.
 *
 * Modes (settings: jev_enabled / jev_mode):
 *   off    — Jev is bypassed entirely; the legacy LLM path runs. Only when
 *            jev_enabled is explicitly '0'.
 *   shadow — both run; the LLM result is returned and the Jev result is logged
 *            to logs/jev_shadow.jsonl for agreement analysis. Zero behavior change.
 *   live   — the Jev result is returned. If Jev errors, or confidence falls below
 *            jev_min_confidence (default 0.65), the legacy LLM path runs instead.
 *            SHIPPED DEFAULT (owner ship call 2026-10-01): a fresh install with
 *            no stored jev_enabled/jev_mode settings runs enabled + live.
 *            Existing installs keep their stored values — defaults apply only
 *            when the setting row is absent.
 *
 * Each integration calls decide() with a stable $decisionName (e.g.
 * "qualify_lead.decide_qualification") so shadow logs can be sliced per
 * decision point.
 *
 * Logging must never break the pipeline: every failure path falls back to the
 * legacy result and is recorded via error_log().
 *
 * PHP port of smarketer-pro's src/llm/decision_tier.py.
 */
class DecisionTier
{
    /** @var JevProvider|null|false null = not attempted yet, false = unavailable */
    private static $provider = null;
    private static bool $providerAttempted = false;

    /** @var array|null Cached config for the current request lifecycle */
    private static ?array $configCache = null;

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    /**
     * @return array{enabled: bool, mode: string, min_confidence: float, model: ?string,
     *               base_url: ?string, timeout: int, shadow_log: ?string}
     */
    public static function config(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        self::$configCache = [
            // Shipped default (owner ship call 2026-10-01): fresh installs run
            // enabled + live. Stored values on existing installs always win.
            'enabled' => self::setting('jev_enabled', '1') === '1',
            'mode' => strtolower(self::setting('jev_mode', 'live') ?: 'live'),
            'min_confidence' => (float)(self::setting('jev_min_confidence', '0.65') ?: 0.65),
            'model' => self::setting('jev_model', '') ?: null,
            'base_url' => self::setting('jev_base_url', '') ?: null,
            'timeout' => (int)(self::setting('jev_timeout', '30') ?: 30),
            'shadow_log' => self::setting('jev_shadow_log', '') ?: null,
        ];
        return self::$configCache;
    }

    /**
     * Effective mode: "off", "shadow", or "live".
     */
    public static function mode(): string
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return 'off';
        }
        return in_array($cfg['mode'], ['shadow', 'live'], true) ? $cfg['mode'] : 'shadow';
    }

    private static function setting(string $key, string $default): string
    {
        try {
            $val = Database::getSetting($key, $default);
            return $val === null ? $default : (string)$val;
        } catch (\Throwable $e) {
            error_log('[DecisionTier] Could not read setting ' . $key . ': ' . $e->getMessage());
            return $default;
        }
    }

    /**
     * Lazily builds (and caches) the shared JevProvider. Returns null when
     * unusable (missing key, network misconfiguration, ...).
     */
    /**
     * Build a one-off provider with a caller-specified timeout. Used for
     * per-decision timeout overrides; the shared cached provider is
     * untouched. Returns null when Jev is unavailable (fail-closed: the
     * caller falls back to the legacy path).
     */
    private static function providerWithTimeout(int $timeout): ?JevProvider
    {
        $cfg = self::config();
        try {
            $apiKey = self::setting('jev_api_key', '') ?: null;
            return new JevProvider($apiKey, $cfg['model'], $cfg['base_url'], $timeout);
        } catch (\Throwable $e) {
            error_log('[DecisionTier] Jev unavailable (timeout override): ' . $e->getMessage());
            return null;
        }
    }

    public static function getProvider(): ?JevProvider
    {
        if (!self::$providerAttempted) {
            self::$providerAttempted = true;
            $cfg = self::config();
            try {
                $apiKey = self::setting('jev_api_key', '') ?: null;
                self::$provider = new JevProvider(
                    $apiKey,
                    $cfg['model'],
                    $cfg['base_url'],
                    $cfg['timeout']
                );
            } catch (JevException $e) {
                error_log('[DecisionTier] Jev unavailable: ' . $e->getMessage());
                self::$provider = false;
            } catch (\Throwable $e) {
                error_log('[DecisionTier] Jev unavailable: ' . $e->getMessage());
                self::$provider = false;
            }
        }
        return self::$provider === false ? null : self::$provider;
    }

    /**
     * Reset cached provider/config state. Intended for tests only.
     */
    public static function resetForTests(): void
    {
        self::$provider = null;
        self::$providerAttempted = false;
        self::$configCache = null;
    }

    // ------------------------------------------------------------------
    // Main entry point
    // ------------------------------------------------------------------

    /**
     * Run a decision through the configured tier.
     *
     * @param string   $decisionName Stable key, e.g. "qualify_lead.decide_qualification".
     * @param mixed    $state        Jev state (array|string).
     * @param array    $questions    Jev questions map.
     * @param callable $llmFallback  Zero-arg callable returning the legacy result.
     * @param callable|null $extract Optional: mixed (jev answers OR legacy result) -> comparable value.
     * @param callable|null $agree   Optional: (jev_value, llm_value) -> bool.
     * @param int|null $timeoutOverride Optional per-decision timeout (seconds).
     * @param bool $perDimensionAbstention Opt-in (live mode only): low-confidence
     *        answers are marked with 'abstained' => true instead of escalating
     *        the whole decision to the legacy path. The caller applies its
     *        coverage floor and fail-closed rules. Used by lead_fit.score_fit
     *        only; the other decision points keep the all-or-nothing veto.
     *
     * @return mixed The legacy result in "off"/"shadow" mode; the raw Jev
     *               *answers* array in "live" mode (callers adapt it).
     */
    public static function decide(
        string $decisionName,
        $state,
        array $questions,
        callable $llmFallback,
        ?callable $extract = null,
        ?callable $agree = null,
        ?int $timeoutOverride = null,
        bool $perDimensionAbstention = false
    ) {
        $mode = self::mode();
        // A per-decision timeout override (e.g. the draft reviewer's <=8s
        // budget) builds a dedicated provider instead of reusing the cached
        // one, so one decision's urgency never changes the global timeout.
        if ($timeoutOverride !== null && $mode !== 'off') {
            $provider = self::providerWithTimeout($timeoutOverride);
        } else {
            $provider = $mode === 'off' ? null : self::getProvider();
        }

        if ($mode === 'off' || $provider === null) {
            return $llmFallback();
        }

        $started = microtime(true);
        try {
            $answers = $provider->systemOne($state, $questions);
            $latencyMs = (int)((microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            error_log("[DecisionTier] [{$decisionName}] Jev call failed ({$e->getMessage()}); using legacy path.");
            return $llmFallback();
        }

        if ($mode === 'shadow') {
            $legacy = $llmFallback();
            $record = [
                'mode' => 'shadow',
                'latency_ms' => $latencyMs,
                'min_confidence' => self::minConfidence($answers),
                'jev_answers' => $answers,
            ];
            if ($extract !== null) {
                try {
                    $jv = $extract($answers);
                    $lv = $extract($legacy);
                    $record['jev_value'] = $jv;
                    $record['llm_value'] = $lv;
                    $record['agree'] = $agree ? (bool)$agree($jv, $lv) : ($jv == $lv);
                } catch (\Throwable $e) {
                    $record['extract_error'] = $e->getMessage();
                }
            }
            self::logShadow($decisionName, $record);
            return $legacy;
        }

        // live mode — escalate to the legacy path on low confidence
        $cfg = self::config();
        $minConf = $cfg['min_confidence'];
        if ($perDimensionAbstention) {
            // Opt-in abstention marking (lead_fit.score_fit only): answers
            // below the threshold are flagged 'abstained' for the caller,
            // which applies the coverage floor and fail-closed rules. The
            // whole verdict is never discarded over one uncertain dimension.
            // Shadow mode is unchanged: marking applies to live mode only.
            return self::markAbstentions($answers, $minConf);
        }
        if (self::minConfidence($answers) < $minConf) {
            error_log("[DecisionTier] [{$decisionName}] Jev confidence below {$minConf}; escalating to LLM.");
            return $llmFallback();
        }
        return $answers;
    }

    // ------------------------------------------------------------------
    // Answer helpers
    // ------------------------------------------------------------------

    /**
     * Lowest confidence across answers. Noul answers without an explicit
     * confidence field are treated as 1.0 (vendor: noul probability IS the
     * calibration).
     */
    public static function minConfidence(array $answers): float
    {
        $confs = [];
        foreach ($answers as $ans) {
            if (is_array($ans) && array_key_exists('confidence', $ans)) {
                $confs[] = (float)$ans['confidence'];
            }
        }
        return $confs ? min($confs) : 1.0;
    }

    /**
     * Mark per-dimension abstentions (opt-in, lead_fit.score_fit only).
     * Answers whose confidence is below $minConf get 'abstained' => true;
     * answers without an explicit confidence field count as 1.0 (mirrors
     * minConfidence) and are never abstained. Non-array answers are left
     * untouched. The flag is always set on array answers so callers can
     * test it with !empty().
     *
     * @return array<string,array> the answers with the flag added
     */
    public static function markAbstentions(array $answers, float $minConf): array
    {
        foreach ($answers as $k => $ans) {
            if (!is_array($ans)) {
                continue;
            }
            $conf = array_key_exists('confidence', $ans) ? (float)$ans['confidence'] : 1.0;
            $answers[$k]['abstained'] = ($conf < $minConf);
        }
        return $answers;
    }

    public static function answersToBool(array $answers, string $key, float $threshold = 0.5): bool
    {
        return (float)($answers[$key]['noul'] ?? 0.0) >= $threshold;
    }

    public static function answersToChoice(array $answers, string $key)
    {
        return $answers[$key]['choice'] ?? null;
    }

    public static function answersToScore(array $answers, string $key): float
    {
        return (float)($answers[$key]['score'] ?? 0.0);
    }

    // ------------------------------------------------------------------
    // Shadow logging (must never break the pipeline)
    // ------------------------------------------------------------------

    private static function logShadow(string $decisionName, array $record): void
    {
        try {
            $path = self::shadowLogPath();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $record['ts'] = gmdate('c');
            $record['decision'] = $decisionName;
            $line = json_encode($record);
            if ($line === false) {
                return;
            }
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log('[DecisionTier] Could not write shadow log: ' . $e->getMessage());
        }
    }

    /**
     * Writable shadow-log path: explicit setting first, then
     * <app_root>/logs/jev_shadow.jsonl, then the system temp dir.
     */
    private static function shadowLogPath(): string
    {
        $cfg = self::config();
        if ($cfg['shadow_log']) {
            return $cfg['shadow_log'];
        }
        $appRoot = dirname(__DIR__, 2);
        $candidate = $appRoot . '/logs/jev_shadow.jsonl';
        $dir = dirname($candidate);
        if ((is_dir($dir) && is_writable($dir)) || (!is_dir($dir) && is_writable($appRoot))) {
            return $candidate;
        }
        return rtrim(sys_get_temp_dir(), '/\\') . '/jev_shadow.jsonl';
    }
}
