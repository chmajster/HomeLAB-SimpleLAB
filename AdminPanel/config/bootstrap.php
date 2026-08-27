<?php

declare(strict_types=1);

$configFile = '/etc/homelab-simplelab/config.php';
$config = file_exists($configFile)
    ? require $configFile
    : require __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    session_name((string)($config['session_name'] ?? 'simplelab_session'));
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hostname.php';

$db = Database::connect((string)$config['db_path']);
