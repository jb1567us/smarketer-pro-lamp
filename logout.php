<?php
/**
 * Log out of Smarketer Pro.
 *
 * POST-only with CSRF validation: logging out via GET would let any
 * third-party page log the user out (and, more importantly, keeps logout
 * out of prefetch/crawler reach).
 */
require_once __DIR__ . '/includes/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

\App\Auth::requireCsrf();
\App\Auth::logout();

header('Location: login.php');
exit;
