<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
echo method_exists('mysqli', 'execute_query') ? 'YES' : 'NO';
