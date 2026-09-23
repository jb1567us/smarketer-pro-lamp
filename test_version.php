<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
echo phpversion();
