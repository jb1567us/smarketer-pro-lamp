<?php
/**
 * ICP (Ideal Customer Profile) API.
 *
 * Security model:
 *  - GET  requires auth (session or API key via \App\Auth::requireApiAuth).
 *  - POST/PUT requires a CSRF token (\App\Auth::requireCsrf) and an
 *    allowlisted `action`. All inputs are validated server-side; weight
 *    vectors must sum to exactly 100 (enforced in App\Icp\IcpProfile).
 *  - No secrets are read or written here.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';

use App\Auth;
use App\Database;
use App\Icp\IcpProfile;
use App\PDO;
use App\Actions\AdjustIcpWeightsAction;

Auth::requireApiAuth();

/** Known trigger-signal tokens accepted in target configs. */
const ICP_KNOWN_SIGNALS = ['funding', 'hiring', 'leadership_change', 'product_launch'];

const ICP_TARGET_SPEC = [
    'company_size'    => ['min_employees' => 'int_or_null', 'max_employees' => 'int_or_null'],
    'industry_fit'    => ['include' => 'string_list', 'exclude' => 'string_list'],
    'target_title'    => ['titles' => 'string_list'],
    'geography'       => ['countries' => 'string_list', 'regions' => 'string_list'],
    'trigger_signals' => ['signals' => 'signal_list'],
    // tech_stack is the toggleable dimension: the buyer lists the target
    // technology surface they sell into; when enabled it is scored on
    // discoverability (how much of that surface was found in the evidence).
    'tech_stack'      => ['tools' => 'string_list'],
];

/** @throws InvalidArgumentException */
function cleanStringList(mixed $value, string $field, int $maxItems = 100, int $maxLen = 100): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException("{$field} must be a list of strings.");
    }
    $out = [];
    foreach (array_values($value) as $item) {
        if (!is_string($item)) {
            throw new InvalidArgumentException("{$field} must contain only strings.");
        }
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        if (mb_strlen($item) > $maxLen) {
            throw new InvalidArgumentException("{$field} item too long (max {$maxLen} chars).");
        }
        $out[] = $item;
        if (count($out) >= $maxItems) {
            throw new InvalidArgumentException("{$field} accepts at most {$maxItems} items.");
        }
    }
    return array_values(array_unique($out));
}

/** @throws InvalidArgumentException */
function cleanTargetConfig(string $dimensionKey, mixed $config): array
{
    if (!is_array($config)) {
        throw new InvalidArgumentException("Target config for {$dimensionKey} must be an object.");
    }
    if (!isset(ICP_TARGET_SPEC[$dimensionKey])) {
        throw new InvalidArgumentException("Unknown dimension: {$dimensionKey}");
    }
    $spec = ICP_TARGET_SPEC[$dimensionKey];
    $out = [];
    foreach ($spec as $field => $kind) {
        if (!array_key_exists($field, $config)) {
            continue;
        }
        $value = $config[$field];
        switch ($kind) {
            case 'int_or_null':
                if ($value === null || $value === '') {
                    $out[$field] = null;
                } elseif (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $v = (int)$value;
                    if ($v < 1 || $v > 1000000000) {
                        throw new InvalidArgumentException("{$field} must be a positive employee count.");
                    }
                    $out[$field] = $v;
                } else {
                    throw new InvalidArgumentException("{$field} must be a positive integer or empty.");
                }
                break;
            case 'string_list':
                $out[$field] = cleanStringList($value, $field);
                break;
            case 'signal_list':
                $list = cleanStringList($value, $field, 20, 64);
                foreach ($list as $sig) {
                    if (!in_array($sig, ICP_KNOWN_SIGNALS, true) && !preg_match('/^[a-z][a-z0-9_]*$/', $sig)) {
                        throw new InvalidArgumentException("Unknown trigger signal: {$sig}");
                    }
                }
                $out[$field] = $list;
                break;
        }
    }
    if (isset($out['min_employees'], $out['max_employees'])
        && $out['min_employees'] !== null && $out['max_employees'] !== null
        && $out['min_employees'] > $out['max_employees']) {
        throw new InvalidArgumentException('min_employees cannot exceed max_employees.');
    }
    return $out;
}

/** @throws RuntimeException when no profile is active (fail-closed). */
function activeProfileId(): int
{
    $profile = IcpProfile::active();
    if ($profile === null) {
        throw new RuntimeException('No active ICP profile. Apply migrations/2026-09-28-icp-scoring.sql.');
    }
    return (int)$profile['id'];
}

function jsonOk(array $data): void
{
    echo json_encode(['success' => true, 'data' => $data]);
}

function jsonFail(int $httpCode, string $error): void
{
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'error' => $error]);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $profileId = activeProfileId();
        $pdo = Database::getConnection();
        $histStmt = $pdo->prepare(
            "SELECT dimension_key, old_weight, new_weight, reason, sample_size,
                    created_by, created_at
             FROM icp_weight_history WHERE profile_id = ?
             ORDER BY created_at DESC LIMIT 25"
        );
        $histStmt->execute([$profileId]);
        jsonOk([
            'profile'        => IcpProfile::active(),
            'dimensions'     => IcpProfile::dimensions($profileId),
            'dimension_labels' => IcpProfile::DIMENSION_LABELS,
            'exclusions'     => IcpProfile::exclusions($profileId),
            'exclusion_types' => IcpProfile::EXCLUSION_TYPES,
            'thresholds'     => IcpProfile::thresholds(),
            'weight_history' => $histStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
        exit;
    }

    if ($method !== 'POST' && $method !== 'PUT') {
        jsonFail(405, 'Method not allowed');
        exit;
    }

    Auth::requireCsrf();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        jsonFail(400, 'Invalid JSON body');
        exit;
    }
    unset($data['csrf_token']); // transport-only

    $action = $data['action'] ?? '';
    $profileId = activeProfileId();

    switch ($action) {
        // (a) Day-zero founder hypothesis: pain statement + dimension targets.
        case 'save_hypothesis': {
            $pain = isset($data['pain_statement']) ? (string)$data['pain_statement'] : '';
            IcpProfile::updatePainStatement($profileId, $pain);

            $targets = $data['targets'] ?? [];
            if (!is_array($targets)) {
                jsonFail(400, 'targets must be an object.');
                exit;
            }
            $unknown = array_diff_key($targets, array_fill_keys(IcpProfile::DIMENSIONS, null));
            if ($unknown !== []) {
                jsonFail(400, 'Unknown dimension(s): ' . implode(', ', array_keys($unknown)));
                exit;
            }
            foreach ($targets as $key => $config) {
                IcpProfile::updateTargetConfig($profileId, $key, cleanTargetConfig($key, $config));
            }
            jsonOk(['saved' => true]);
            break;
        }

        // (b) Weight editor: full vector, server-side sum-to-100 validation.
        case 'save_weights': {
            $weights = $data['weights'] ?? [];
            if (!is_array($weights)) {
                jsonFail(400, 'weights must be an object of dimension => weight.');
                exit;
            }
            $reason = isset($data['reason']) ? substr((string)$data['reason'], 0, 255) : 'manual weight edit';
            IcpProfile::updateWeights($profileId, $weights, $reason, null, true, 'user');
            jsonOk(['saved' => true, 'weights' => IcpProfile::weights($profileId)]);
            break;
        }

        // (c) Exclusion list manager.
        case 'add_exclusion': {
            $id = IcpProfile::addExclusion(
                $profileId,
                (string)($data['exclusion_type'] ?? ''),
                (string)($data['value'] ?? ''),
                isset($data['note']) ? (string)$data['note'] : null
            );
            jsonOk(['saved' => true, 'id' => $id]);
            break;
        }
        case 'delete_exclusion': {
            $exclusionId = (int)($data['id'] ?? 0);
            if ($exclusionId <= 0) {
                jsonFail(400, 'Missing exclusion id.');
                exit;
            }
            IcpProfile::deleteExclusion($profileId, $exclusionId);
            jsonOk(['deleted' => true]);
            break;
        }

        // (b) Unlock a buyer-locked dimension so the auto-tuner may adjust it.
        case 'unlock_dimension': {
            $key = (string)($data['dimension_key'] ?? '');
            IcpProfile::unlockDimension($profileId, $key);
            jsonOk(['unlocked' => true]);
            break;
        }

        // (e) Toggle an optional dimension (today: tech_stack) on/off.
        // When enabling, the buyer sets its weight in the same call via the
        // full weights vector; when disabling, its weight is forced to 0.
        // The dimension is buyer-locked by the toggle, so the auto-tuner
        // will not move the buyer-chosen weight afterwards.
        case 'set_dimension_enabled': {
            $key = (string)($data['dimension_key'] ?? '');
            $enabled = filter_var(
                $data['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE
            );
            if ($enabled === null) {
                jsonFail(400, 'enabled must be true or false.');
                exit;
            }
            $weights = $data['weights'] ?? [];
            if (!is_array($weights)) {
                jsonFail(400, 'weights must be an object of dimension => weight.');
                exit;
            }
            $reason = isset($data['reason'])
                ? substr((string)$data['reason'], 0, 255)
                : ($enabled ? 'buyer enabled tech_stack dimension' : 'buyer disabled tech_stack dimension');
            $pdo = Database::getConnection();
            $result = (new AdjustIcpWeightsAction($pdo))
                ->setDimensionEnabled($profileId, $key, $enabled, $weights, $reason);
            jsonOk($result);
            break;
        }

        // (d) Thresholds (stored as settings rows).
        case 'save_thresholds': {
            $qualify = (int)($data['qualify'] ?? 0);
            $review = (int)($data['review'] ?? 0);
            if ($qualify < 1 || $qualify > 100 || $review < 0 || $review >= $qualify) {
                jsonFail(400, 'Thresholds invalid: need 0 <= review < qualify <= 100.');
                exit;
            }
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = ?"
            );
            $stmt->execute(['icp_threshold_qualify', (string)$qualify, (string)$qualify]);
            $stmt->execute(['icp_threshold_review', (string)$review, (string)$review]);
            jsonOk(['saved' => true, 'thresholds' => ['qualify' => $qualify, 'review' => $review]]);
            break;
        }

        default:
            jsonFail(400, 'Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    jsonFail(400, $e->getMessage());
} catch (RuntimeException $e) {
    // Fail-closed on misconfiguration: 500, no internals leaked beyond the message.
    error_log('[api/icp.php] ' . $e->getMessage());
    jsonFail(500, $e->getMessage());
} catch (Exception $e) {
    error_log('[api/icp.php] ' . $e->getMessage());
    jsonFail(500, 'ICP request failed');
}
