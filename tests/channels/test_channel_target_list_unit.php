#!/usr/bin/env php
<?php
/**
 * ChannelTargetList unit tests (P5 — channel_reach target list).
 *
 * Zero network, zero DB. Everything under test is a public static on
 * App\Channels\ChannelTargetList:
 *   1. Determinism: identical query twice -> byte-identical result.
 *   2. Total order: strength_num DESC, channel_key ASC (ties break by key).
 *   3. Scope filtering: a local-only channel is NOT returned for a national
 *      scope query; a broader edge IS returned for a narrower query;
 *      missing facet inherits the industry scope_default.
 *   4. Strength floor: low-strength channels filtered at the default 0.4,
 *      present at 0.0.
 *   5. Unknown industry: fail closed — empty list + explicit miss code
 *      (never a guessed list); miss names the known industries.
 *   6. Unknown scope: fail closed — invalid scope miss (never a pass).
 *   7. selfCheck(): seed integrity audit is clean (closed vocabs, ladder).
 *   8. targetBlock() seam: shape for ScoreLeadFitAction injection; miss
 *      passes through honestly (channels=[]).
 *   9. mentionCheck(): render-time tripwire — pass case; unknown channel,
 *      not-in-target-list, strength floor, scope mismatch -> NOT allowed,
 *      with the right failed_check code AND flag-not-drop routing
 *      (suggested_routing=human_review, review_status='Needs Review').
 *  10. resolveChannel(): aliases resolve; unmappable -> null (fail-closed).
 *
 * Usage: php tests/channels/test_channel_target_list_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Channels\ChannelTargetList;
use App\Evidence\MentionValidity;

$pass = 0;
$fail = 0;
function ct_check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$name}\n";
    } else {
        $fail++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function channelKeys(array $res): array
{
    return array_map(static fn (array $c): string => $c['channel_key'], $res['channels']);
}

echo "== seed integrity ==\n";
$violations = ChannelTargetList::selfCheck();
ct_check('selfCheck(): no seed violations', $violations === [], implode('; ', $violations));
ct_check('TABLE_VERSION is a date', (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', ChannelTargetList::TABLE_VERSION));
ct_check('scope ladder is the shared P1 ladder',
    MentionValidity::SCOPES === ['local', 'state', 'regional', 'national', 'online-global']);

echo "== determinism ==\n";
$a = ChannelTargetList::channelsFor('saas_b2b', 'online-global');
$b = ChannelTargetList::channelsFor('saas_b2b', 'online-global');
ct_check('same query twice -> identical result', serialize($a) === serialize($b));
ct_check('result pins table + extension versions',
    $a['table_version'] === ChannelTargetList::TABLE_VERSION
    && is_string($a['extension_version']) && $a['extension_version'] !== '');

echo "== total order ==\n";
$keys = channelKeys($a);
$nums = array_map(static fn (array $c): float => $c['strength_num'], $a['channels']);
$desc = true;
for ($i = 1; $i < count($nums); $i++) {
    if ($nums[$i] > $nums[$i - 1]) {
        $desc = false;
    }
    if ($nums[$i] === $nums[$i - 1] && $keys[$i] < $keys[$i - 1]) {
        $desc = false;
    }
}
ct_check('strength_num DESC, channel_key ASC', $desc, implode(',', $keys));
ct_check('high-strength channels come first for saas_b2b',
    $keys[0] === 'cold_email' && $keys[1] === 'linkedin_outreach',
    implode(',', array_slice($keys, 0, 3)));
ct_check('every channel row carries the full evidence shape',
    array_reduce($a['channels'], static fn (bool $ok, array $c): bool =>
        $ok && isset($c['channel_key'], $c['label'], $c['strength'], $c['strength_num'],
            $c['basis'], $c['basis_definition'], $c['scope'], $c['source']), true));

echo "== scope filtering ==\n";
$localNat = ChannelTargetList::channelsFor('local_services_home', 'national');
ct_check('local-only channel NOT returned for a national query',
    !in_array('direct_mail', channelKeys($localNat), true));
$localLocal = ChannelTargetList::channelsFor('local_services_home', 'local');
ct_check('local-only channel IS returned for a local query',
    in_array('direct_mail', channelKeys($localLocal), true));
$manu = ChannelTargetList::channelsFor('manufacturing', 'state');
ct_check('online-global-faceted edge returned for a narrower (state) query',
    in_array('cold_email', channelKeys($manu), true));
$ag = ChannelTargetList::channelsFor('agencies', 'state');
ct_check('missing facet inherits industry scope_default (national covers state)',
    in_array('cold_email', channelKeys($ag), true));
ct_check('every returned channel covers the requested scope',
    array_reduce($localLocal['channels'], static fn (bool $ok, array $c): bool =>
        $ok && MentionValidity::scopeCovers($c['scope'], 'local'), true));

echo "== strength floor ==\n";
$def = ChannelTargetList::channelsFor('saas_b2b', 'online-global'); // default 0.4
$all = ChannelTargetList::channelsFor('saas_b2b', 'online-global', 0.0);
ct_check('low-strength channel filtered at default min_strength 0.4',
    !in_array('direct_mail', channelKeys($def), true));
ct_check('low-strength channel present at min_strength 0.0',
    in_array('direct_mail', channelKeys($all), true));

echo "== fail-closed: unknown industry / scope ==\n";
$miss = ChannelTargetList::channelsFor('crypto_mining', 'national');
ct_check('unknown industry -> matched=false', $miss['matched'] === false);
ct_check('unknown industry -> empty channel list', $miss['channels'] === []);
ct_check('unknown industry -> explicit miss code (not a guess)',
    ($miss['miss']['code'] ?? null) === ChannelTargetList::MISS_UNKNOWN_INDUSTRY);
ct_check('miss names the known industries',
    is_array($miss['miss']['available_industries'] ?? null)
    && count($miss['miss']['available_industries']) === count(ChannelTargetList::INDUSTRY_SEED));
$badScope = ChannelTargetList::channelsFor('saas_b2b', 'county');
ct_check('unknown scope -> matched=false', $badScope['matched'] === false);
ct_check('unknown scope -> invalid scope miss code',
    ($badScope['miss']['code'] ?? null) === ChannelTargetList::MISS_INVALID_SCOPE);

echo "== targetBlock() seam ==\n";
$block = ChannelTargetList::targetBlock('agencies', 'national');
ct_check('targetBlock carries labels + channel_target + versions',
    $block['matched'] === true
    && in_array('Cold email', $block['channels'], true)
    && isset($block['channel_target'][0]['channel_key'], $block['channel_target'][0]['basis'])
    && $block['table_version'] === ChannelTargetList::TABLE_VERSION);
$blockMiss = ChannelTargetList::targetBlock('crypto_mining', 'national');
ct_check('targetBlock passes the miss through honestly',
    $blockMiss['matched'] === false
    && $blockMiss['channels'] === []
    && ($blockMiss['miss']['code'] ?? null) === ChannelTargetList::MISS_UNKNOWN_INDUSTRY);

echo "== resolveChannel() ==\n";
ct_check("alias 'cold email' -> cold_email",
    ChannelTargetList::resolveChannel('cold email') === 'cold_email');
ct_check("alias 'LinkedIn DMs' -> linkedin_outreach (case-insensitive)",
    ChannelTargetList::resolveChannel('LinkedIn DMs') === 'linkedin_outreach');
ct_check('exact key resolves', ChannelTargetList::resolveChannel('cold_call') === 'cold_call');
ct_check('unmappable text -> null (fail-closed)',
    ChannelTargetList::resolveChannel('carrier pigeon') === null);

echo "== mentionCheck() tripwire ==\n";
$ok = ChannelTargetList::mentionCheck('saas_b2b', 'online-global', 'cold email');
ct_check('pass: known channel in scope -> allowed',
    $ok['allowed'] === true && $ok['channel_key'] === 'cold_email'
    && $ok['suggested_routing'] === 'none');

$unknown = ChannelTargetList::mentionCheck('saas_b2b', 'online-global', 'carrier pigeon');
ct_check('fail: unknown channel -> NOT allowed',
    $unknown['allowed'] === false
    && in_array(ChannelTargetList::FAIL_UNKNOWN_CHANNEL, $unknown['failed_checks'], true));

$notListed = ChannelTargetList::mentionCheck('saas_b2b', 'online-global', 'sms');
ct_check('fail: channel not in target list -> NOT allowed',
    $notListed['allowed'] === false
    && in_array(ChannelTargetList::FAIL_NOT_IN_TARGET_LIST, $notListed['failed_checks'], true));

$weak = ChannelTargetList::mentionCheck('saas_b2b', 'online-global', 'direct mail');
ct_check('fail: below strength floor -> NOT allowed',
    $weak['allowed'] === false
    && in_array(ChannelTargetList::FAIL_STRENGTH_BELOW_MIN, $weak['failed_checks'], true));

$scopeMiss = ChannelTargetList::mentionCheck('local_services_home', 'national', 'direct mail');
ct_check('fail: scope mismatch -> NOT allowed',
    $scopeMiss['allowed'] === false
    && in_array(ChannelTargetList::FAIL_SCOPE, $scopeMiss['failed_checks'], true));

$indMiss = ChannelTargetList::mentionCheck('crypto_mining', 'national', 'cold email');
ct_check('fail: unknown industry -> NOT allowed, miss code carried',
    $indMiss['allowed'] === false
    && in_array(ChannelTargetList::MISS_UNKNOWN_INDUSTRY, $indMiss['failed_checks'], true));

foreach (['unknown' => $unknown, 'notListed' => $notListed, 'weak' => $weak,
          'scopeMiss' => $scopeMiss, 'indMiss' => $indMiss] as $label => $v) {
    ct_check("flag-not-drop ({$label}): routed to human review, never dropped",
        $v['suggested_routing'] === 'human_review'
        && $v['review_status'] === 'Needs Review'
        && isset($v['detail']) && $v['detail'] !== '');
}

echo "\n== channel target list: {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
