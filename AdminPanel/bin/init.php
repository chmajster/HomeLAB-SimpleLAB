<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['admin-user::', 'admin-password:', 'api-token:']);
$adminUser = (string)($options['admin-user'] ?? 'admin');
$adminPassword = (string)($options['admin-password'] ?? '');
$apiToken = (string)($options['api-token'] ?? '');

if ($adminPassword === '' || $apiToken === '') {
    fwrite(STDERR, "Usage: php init.php --admin-password PASSWORD --api-token TOKEN [--admin-user admin]\n");
    exit(2);
}

require __DIR__ . '/../config/bootstrap.php';
$schema = file_get_contents(__DIR__ . '/../data/schema.sql');
if ($schema === false) {
    throw new RuntimeException('Cannot read schema.sql');
}
$db->exec($schema);

$db->beginTransaction();
try {
    $settings = [
        'application_name' => 'HomeLAB SimpleLAB',
        'base_url' => '',
        'puppet_server' => 'puppet.lab.local',
        'puppet_server_ip' => '',
        'puppet_port' => '8140',
        'puppet_environment' => 'production',
    ];
    foreach ($settings as $key => $value) {
        $stmt = $db->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(:key, :value)');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM hostname_patterns')->fetchColumn();
    if ($count === 0) {
        $insert = $db->prepare('INSERT INTO hostname_patterns(name, pattern, start_number, current_number, active) VALUES(?,?,?,?,?)');
        $insert->execute(['Standard Linux', 'SCLXXXXX', 1, 0, 1]);
        $insert->execute(['Server Linux', 'SRLXXXX', 1, 0, 0]);
    }

    $user = $db->prepare(
        'INSERT INTO users(username, password_hash, active) VALUES(:username, :hash, 1)
         ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash, active = 1'
    );
    $user->execute([':username' => $adminUser, ':hash' => password_hash($adminPassword, PASSWORD_DEFAULT)]);

    $db->exec('UPDATE api_tokens SET active = 0');
    $token = $db->prepare('INSERT INTO api_tokens(name, token_hash, active) VALUES(:name, :hash, 1)');
    $token->execute([':name' => 'installer-generated', ':hash' => password_hash($apiToken, PASSWORD_DEFAULT)]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

echo "Database initialized successfully.\n";
