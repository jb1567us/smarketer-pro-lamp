<?php
/**
 * FIX 4 — paused-campaign queue gate tests.
 *
 * Usage: php tests/sending/PausedCampaignGateTest.php
 *
 * Pure PHP against in-memory SQLite: no database server, no network.
 * Exercises the real App\CampaignPauseGate SQL through native PDO.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\CampaignPauseGate;

$passed = 0;
$failed = 0;
function ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failed++; echo "  FAIL: {$name}\n"; }
}

function freshDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE campaigns (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_active INTEGER DEFAULT 1, status TEXT DEFAULT 'active')");
    $pdo->exec("CREATE TABLE leads (id INTEGER PRIMARY KEY AUTOINCREMENT, company_name TEXT, email TEXT, campaign_id INTEGER)");
    $pdo->exec("CREATE TABLE task_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, task_type TEXT, payload TEXT, status TEXT DEFAULT 'Pending', scheduled_at TEXT, retry_count INTEGER DEFAULT 0)");
    // One active, one paused campaign.
    $pdo->exec("INSERT INTO campaigns (name, is_active, status) VALUES ('Active Co', 1, 'active')");
    $pdo->exec("INSERT INTO campaigns (name, is_active, status) VALUES ('Paused Co', 0, 'paused')");
    $active = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Active Co'")->fetchColumn();
    $paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
    // A lead bound to the paused campaign (for the payload-less fallback).
    $pdo->exec("INSERT INTO leads (company_name, email, campaign_id) VALUES ('Lead Co', 'lead@example.com', {$paused})");
    $leadPaused = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO leads (company_name, email, campaign_id) VALUES ('Free Co', 'free@example.com', NULL)");
    $leadFree = (int)$pdo->lastInsertId();
    return $pdo;
}

function insertTask(PDO $pdo, string $type, ?string $payload, int $leadId = 0): int
{
    $stmt = $pdo->prepare("INSERT INTO task_queue (lead_id, task_type, payload, status, scheduled_at) VALUES (?, ?, ?, 'Pending', datetime('now'))");
    $stmt->execute([$leadId, $type, $payload]);
    return (int)$pdo->lastInsertId();
}

function pendingRows(PDO $pdo): array
{
    return $pdo->query("SELECT id, task_type, payload, lead_id FROM task_queue WHERE status = 'Pending'")->fetchAll(PDO::FETCH_ASSOC);
}

/* ── 1. Send-type registry ─────────────────────────────────────────── */
ok(CampaignPauseGate::isSendTaskType('EmailOutreach'), 'EmailOutreach is a send task');
ok(CampaignPauseGate::isSendTaskType('SocialOutreach'), 'SocialOutreach is a send task');
ok(!CampaignPauseGate::isSendTaskType('Qualify'), 'Qualify is not a send task');
ok(!CampaignPauseGate::isSendTaskType('BulkVerify'), 'BulkVerify is not a send task');

/* ── 2. Paused campaign task is held; active campaign task is not ──── */
$pdo = freshDb();
$active = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Active Co'")->fetchColumn();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$tPaused = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $paused]));
$tActive = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $active]));
$tNoCamp = insertTask($pdo, 'EmailOutreach', null);
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(isset($held[$tPaused]), 'paused campaign send task is held');
ok(!isset($held[$tActive]), 'active campaign send task is not held');
ok(!isset($held[$tNoCamp]), 'campaign-less send task fails open (not held)');

/* ── 3. Non-send task types are never held, even with a paused campaign ─ */
$pdo = freshDb();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$tQual = insertTask($pdo, 'Qualify', json_encode(['campaign_id' => $paused]), 1);
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(!isset($held[$tQual]), 'non-send task for paused campaign is not held');

/* ── 4. Payload-less send falls back to the lead's campaign ────────── */
$pdo = freshDb();
$leadPaused = (int)$pdo->query("SELECT id FROM leads WHERE email='lead@example.com'")->fetchColumn();
$leadFree = (int)$pdo->query("SELECT id FROM leads WHERE email='free@example.com'")->fetchColumn();
$tLeadPaused = insertTask($pdo, 'EmailOutreach', null, $leadPaused);
$tLeadFree = insertTask($pdo, 'EmailOutreach', null, $leadFree);
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(isset($held[$tLeadPaused]), 'send task with paused lead campaign is held via fallback');
ok(!isset($held[$tLeadFree]), 'send task with campaign-less lead is not held');

/* ── 5. Missing campaign row fails open ────────────────────────────── */
$pdo = freshDb();
$tGhost = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => 99999]));
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(!isset($held[$tGhost]), 'unknown campaign id fails open (not held)');

/* ── 6. Unpause resumes: task stays Pending and is no longer held ──── */
$pdo = freshDb();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$tPaused = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $paused]));
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(isset($held[$tPaused]), 'held while paused (precondition)');
$status = $pdo->query("SELECT status FROM task_queue WHERE id = {$tPaused}")->fetchColumn();
ok($status === 'Pending', 'held task remains Pending (not failed, not claimed)');
$retry = (int)$pdo->query("SELECT retry_count FROM task_queue WHERE id = {$tPaused}")->fetchColumn();
ok($retry === 0, 'held task burns no attempts');
$pdo->exec("UPDATE campaigns SET is_active = 1, status = 'active' WHERE id = {$paused}");
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(!isset($held[$tPaused]), 'task no longer held after unpause (resumes next tick)');
$status = $pdo->query("SELECT status FROM task_queue WHERE id = {$tPaused}")->fetchColumn();
ok($status === 'Pending', 'resumed task is still Pending, ready to process');

/* ── 7. Manual pause (is_active=0 without status='paused') also holds ─ */
$pdo = freshDb();
$active = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Active Co'")->fetchColumn();
$pdo->exec("UPDATE campaigns SET is_active = 0 WHERE id = {$active}"); // manual toggle path
$tManual = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $active]));
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(isset($held[$tManual]), 'manually paused campaign task is held');

/* ── 8. Lookup failure fails open (broken schema) ──────────────────── */
$pdo = freshDb();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$tPaused = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $paused]));
$pdo->exec("DROP TABLE campaigns"); // simulate a schema the gate cannot read
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok($held === [], 'gate fails open when campaign lookup errors');

/* ── 9. Batched: one tick with mixed campaigns holds only the paused one */
$pdo = freshDb();
$active = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Active Co'")->fetchColumn();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$ids = [];
for ($i = 0; $i < 3; $i++) $ids[] = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $paused]));
for ($i = 0; $i < 2; $i++) $ids[] = insertTask($pdo, 'EmailOutreach', json_encode(['campaign_id' => $active]));
$held = CampaignPauseGate::holdTaskIds($pdo, pendingRows($pdo));
ok(count($held) === 3, 'exactly the 3 paused-campaign tasks held, other 2 unaffected');

/* ── 10. throwIfPaused: manual sends blocked for paused campaigns ─── */
$pdo = freshDb();
$pdo->exec("ALTER TABLE campaigns ADD COLUMN paused_reason TEXT");
$active = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Active Co'")->fetchColumn();
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$pdo->exec("UPDATE campaigns SET paused_reason = 'bounce rate exceeded' WHERE id = {$paused}");

$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, $paused); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown !== null && stripos($thrown, 'paused') !== false, 'manual send refused for paused campaign');
ok($thrown !== null && strpos($thrown, 'bounce rate exceeded') !== false, 'auto-pause reason included in refusal message');

$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, $active); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown === null, 'manual send allowed for active campaign');

$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, null); CampaignPauseGate::throwIfPaused($pdo, 0); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown === null, 'no campaign context = no gate (fail-open)');

$pdo->exec("UPDATE campaigns SET is_active = 0, status = 'active', paused_reason = NULL WHERE id = {$active}"); // manual pause
$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, $active); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown !== null && strpos($thrown, 'Pause reason:') === false, 'manual pause refused without a reason suffix');

$pdo = freshDb(); // no paused_reason column at all: reason lookup must not fatal
$paused = (int)$pdo->query("SELECT id FROM campaigns WHERE name='Paused Co'")->fetchColumn();
$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, $paused); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown !== null && stripos($thrown, 'paused') !== false, 'refusal works pre-migration (no paused_reason column)');

$pdo->exec("DROP TABLE campaigns");
$thrown = null;
try { CampaignPauseGate::throwIfPaused($pdo, $paused); }
catch (\RuntimeException $e) { $thrown = $e->getMessage(); }
ok($thrown === null, 'gate fails open when campaign lookup errors');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
