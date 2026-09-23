<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
header('Content-Type: application/json');

$res = [
    'success' => true,
    'current_file' => __FILE__,
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
    'opcache_reset_exists' => function_exists('opcache_reset'),
];

if (function_exists('opcache_reset')) {
    $res['opcache_reset_status'] = opcache_reset();
} else {
    $res['opcache_reset_status'] = null;
}

echo json_encode($res, JSON_PRETTY_PRINT);
