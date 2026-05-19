<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=33306;dbname=lookoverhere_wp947', 'root', '');
$pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS summary TEXT AFTER status");
echo "Column 'summary' added (or already exists).\n";
