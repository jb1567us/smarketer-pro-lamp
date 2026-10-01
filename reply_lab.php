<?php
/**
 * reply_lab.php — manual reply classification + routing lab.
 *
 * Two explicit user steps:
 *   1. "Classify reply"  → ClassifyReplyAction::classify() via ReplyIntake.
 *   2. "Apply routing"   → ReplyRouter::route() on the stored verdict.
 *
 * Authentication: \App\Auth::requirePageAuth() (redirects to login when not
 * authenticated). Every state-changing POST is CSRF-validated.
 */
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

// Classification runs inside the action's own timeout; the page additionally
// guards total execution so a stuck classifier can't hang the request.
if (function_exists('set_time_limit')) {
    @set_time_limit(90);
}

$esc = function (mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$errors = [];
$verdict = null;          // verdict from the latest classify step
$stored  = null;          // session copy used by the "Apply routing" step
$routingOutcome = null;   // result of the latest route step

// Re-render inputs on validation failure.
$input = [
    'subject'        => '',
    'body'           => '',
    'from'           => '',
    'thread_context' => '',
    'lead_id'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if (!\App\Auth::validateCsrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid or missing CSRF token. Reload the page and try again.';
    } elseif ($action === 'classify') {
        $input['subject']        = trim((string)($_POST['subject'] ?? ''));
        $input['body']           = trim((string)($_POST['body'] ?? ''));
        $input['from']           = trim((string)($_POST['from'] ?? ''));
        $input['thread_context'] = trim((string)($_POST['thread_context'] ?? ''));
        $input['lead_id']        = trim((string)($_POST['lead_id'] ?? ''));

        $leadId = null;
        if ($input['lead_id'] !== '') {
            if (!ctype_digit($input['lead_id'])) {
                $errors[] = 'Lead ID must be a positive integer.';
            } else {
                $leadId = (int)$input['lead_id'];
            }
        }
        if ($input['from'] !== '' && !filter_var($input['from'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'From email is not a valid email address.';
        }
        if ($input['subject'] === '') {
            $errors[] = 'Subject is required.';
        }
        if ($input['body'] === '') {
            $errors[] = 'Body is required.';
        }
        if (strlen($input['subject']) > 500) {
            $errors[] = 'Subject is too long (max 500 characters).';
        }
        if (strlen($input['body']) > 20000) {
            $errors[] = 'Body is too long (max 20,000 characters).';
        }

        if (!$errors) {
            try {
                $verdict = \App\ReplyIntake::classifyReply(
                    $input['subject'],
                    $input['body'],
                    $input['thread_context']
                );
                // Keep only what the routing step needs — not the full body.
                $_SESSION['reply_lab_verdict'] = [
                    'verdict'        => $verdict,
                    'lead_id'        => $leadId,
                    'email'          => $input['from'] !== '' ? $input['from'] : null,
                    'subject'        => mb_substr($input['subject'], 0, 120),
                    'classified_at'  => date('c'),
                ];
                $stored = $_SESSION['reply_lab_verdict'];
                \App\ReplyIntake::logIntake([
                    'source'  => 'reply_lab',
                    'subject' => mb_substr($input['subject'], 0, 200),
                    'from'    => $input['from'] !== '' ? $input['from'] : null,
                    'lead_id' => $leadId,
                    'verdict' => $verdict,
                ]);
            } catch (\Throwable $e) {
                $errors[] = 'Classification failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'route') {
        $stored = $_SESSION['reply_lab_verdict'] ?? null;
        if (!$stored || !is_array($stored['verdict'] ?? null)) {
            $errors[] = 'No classification available — classify a reply first.';
        } else {
            try {
                $routingOutcome = \App\ReplyIntake::applyRouting(
                    $stored['verdict'],
                    $stored['lead_id'] ?? null,
                    $stored['email'] ?? null
                );
                \App\ReplyIntake::logIntake([
                    'source'         => 'reply_lab',
                    'action'         => 'route',
                    'subject'        => $stored['subject'] ?? null,
                    'lead_id'        => $stored['lead_id'] ?? null,
                    'email'          => $stored['email'] ?? null,
                    'verdict'        => $stored['verdict'],
                    'routing_outcome'=> $routingOutcome,
                ]);
                // The verdict is consumed by the routing step.
                unset($_SESSION['reply_lab_verdict']);
                $stored = null;
            } catch (\Throwable $e) {
                $errors[] = 'Routing failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset') {
        unset($_SESSION['reply_lab_verdict']);
        $input = ['subject' => '', 'body' => '', 'from' => '', 'thread_context' => '', 'lead_id' => ''];
    }
} else {
    $stored = $_SESSION['reply_lab_verdict'] ?? null;
}

$csrf = \App\Auth::csrfToken();

$verdictFields = [
    'intent'      => 'Intent',
    'urgency'     => 'Urgency',
    'needs_human' => 'Needs human',
    'confidence'  => 'Confidence',
    'source'      => 'Source',
    'latency_ms'  => 'Latency (ms)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reply Lab — Smarketer Pro</title>
    <meta name="csrf-token" content="<?= $esc($csrf) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0b0f1a; }
    </style>
</head>
<body class="bg-[#0b0f1a] text-slate-200 font-sans">
<div class="max-w-4xl mx-auto px-4 py-8">
    <header class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">📨 Reply Lab</h1>
            <p class="text-slate-400 text-sm mt-1">Manually classify a reply, then explicitly apply routing. Nothing routes automatically.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-400 hover:text-blue-300">← Back to dashboard</a>
    </header>

    <?php if ($errors): ?>
        <div class="bg-red-900/40 border border-red-700 rounded-xl p-4 mb-6">
            <p class="font-bold text-red-300 mb-1">Something went wrong</p>
            <ul class="list-disc list-inside text-sm text-red-200">
                <?php foreach ($errors as $err): ?>
                    <li><?= $esc($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Step 1: manual entry + classify -->
    <div class="bg-slate-800 rounded-xl p-6 shadow-lg border border-slate-700 mb-6">
        <h2 class="text-lg font-bold text-white mb-4">Step 1 — Enter reply &amp; classify</h2>
        <form method="post" action="reply_lab.php">
            <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
            <input type="hidden" name="action" value="classify">

            <label class="block text-sm font-bold text-slate-300 mb-2" for="rl-subject">Subject *</label>
            <input id="rl-subject" name="subject" type="text" maxlength="500" required
                   value="<?= $esc($input['subject']) ?>"
                   class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 mb-4 outline-none focus:ring-2 focus:ring-blue-500 text-white">

            <div class="grid md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-bold text-slate-300 mb-2" for="rl-from">From email (optional)</label>
                    <input id="rl-from" name="from" type="email"
                           value="<?= $esc($input['from']) ?>"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500 text-white">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-300 mb-2" for="rl-lead-id">Lead ID (optional)</label>
                    <input id="rl-lead-id" name="lead_id" type="text" inputmode="numeric" pattern="[0-9]*"
                           value="<?= $esc($input['lead_id']) ?>"
                           class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 outline-none focus:ring-2 focus:ring-blue-500 text-white">
                </div>
            </div>

            <label class="block text-sm font-bold text-slate-300 mb-2" for="rl-body">Body *</label>
            <textarea id="rl-body" name="body" rows="8" required maxlength="20000"
                      class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 mb-4 outline-none focus:ring-2 focus:ring-blue-500 text-white"><?= $esc($input['body']) ?></textarea>

            <label class="block text-sm font-bold text-slate-300 mb-2" for="rl-thread">Thread context (optional)</label>
            <textarea id="rl-thread" name="thread_context" rows="4" maxlength="20000"
                      placeholder="Prior messages / context the classifier should see…"
                      class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-3 mb-4 outline-none focus:ring-2 focus:ring-blue-500 text-white"><?= $esc($input['thread_context']) ?></textarea>

            <button type="submit"
                    class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-6 py-3 rounded-lg transition">
                🔍 Classify reply
            </button>
        </form>
    </div>

    <!-- Verdict display + Step 2: explicit routing -->
    <?php $display = $verdict ?? $stored['verdict'] ?? null; ?>
    <?php if ($display): ?>
        <div class="bg-slate-800 rounded-xl p-6 shadow-lg border border-slate-700 mb-6">
            <h2 class="text-lg font-bold text-white mb-1">Classification verdict</h2>
            <?php if ($stored && isset($stored['subject'])): ?>
                <p class="text-xs text-slate-500 mb-4">Subject: <?= $esc($stored['subject']) ?> · <?= $esc($stored['classified_at'] ?? '') ?></p>
            <?php endif; ?>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-5">
                <?php foreach ($verdictFields as $key => $label): ?>
                    <?php
                    $val = $display[$key] ?? null;
                    if ($key === 'needs_human') {
                        $shown = $val ? 'Yes ⚠️' : 'No';
                    } elseif ($key === 'confidence') {
                        $shown = is_numeric($val) ? round((float)$val, 3) : $val;
                    } else {
                        $shown = $val;
                    }
                    ?>
                    <div class="bg-slate-900 rounded-lg px-4 py-3 border border-slate-700">
                        <div class="text-xs uppercase tracking-wide text-slate-500"><?= $esc($label) ?></div>
                        <div class="text-lg font-bold text-white"><?= $esc(is_array($shown) ? json_encode($shown) : $shown) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($stored): ?>
                <h3 class="text-md font-bold text-white mb-2">Step 2 — Apply routing</h3>
                <p class="text-sm text-slate-400 mb-4">Routing runs only when you press the button. This executes <code class="text-slate-300">ReplyRouter::route()</code>.</p>
                <div class="flex gap-3">
                    <form method="post" action="reply_lab.php">
                        <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
                        <input type="hidden" name="action" value="route">
                        <button type="submit"
                                class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-6 py-3 rounded-lg transition">
                            ✅ Apply routing
                        </button>
                    </form>
                    <form method="post" action="reply_lab.php">
                        <input type="hidden" name="csrf_token" value="<?= $esc($csrf) ?>">
                        <input type="hidden" name="action" value="reset">
                        <button type="submit"
                                class="bg-slate-700 hover:bg-slate-600 text-slate-200 font-bold px-6 py-3 rounded-lg transition">
                            Discard
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Routing outcome -->
    <?php if ($routingOutcome !== null): ?>
        <div class="bg-slate-800 rounded-xl p-6 shadow-lg border border-emerald-700 mb-6">
            <h2 class="text-lg font-bold text-white mb-3">Routing outcome</h2>
            <pre class="bg-slate-900 border border-slate-700 rounded-lg p-4 text-sm text-emerald-200 overflow-x-auto"><?= $esc(json_encode($routingOutcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
