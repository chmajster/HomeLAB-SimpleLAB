<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setting(PDO $db, string $key, ?string $default = null): ?string
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_setting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO settings(key, value, updated_at) VALUES(:key, :value, CURRENT_TIMESTAMP)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':key' => $key, ':value' => $value]);
}

function app_log(array $config, string $event, array $context = []): void
{
    $path = (string)($config['log_path'] ?? '');
    if ($path === '') {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    foreach ($context as $key => $value) {
        if (stripos((string)$key, 'token') !== false) {
            $context[$key] = '[redacted]';
        }
    }
    $line = sprintf("%s event=%s context=%s\n", gmdate('c'), $event, json_encode($context, JSON_UNESCAPED_SLASHES));
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['success' => false, 'error' => 'Invalid JSON body'], 400);
    }
    if (!is_array($data)) {
        json_response(['success' => false, 'error' => 'JSON body must be an object'], 400);
    }
    return $data;
}

function get_bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return null;
    }
    return trim($m[1]);
}

function require_api_auth(PDO $db): void
{
    $token = get_bearer_token();
    if ($token === null || $token === '') {
        json_response(['success' => false, 'error' => 'Missing bearer token'], 401);
    }

    $stmt = $db->query('SELECT token_hash FROM api_tokens WHERE active = 1');
    foreach ($stmt->fetchAll() as $row) {
        if (password_verify($token, (string)$row['token_hash'])) {
            return;
        }
    }
    json_response(['success' => false, 'error' => 'Invalid bearer token'], 401);
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function validate_machine_id(string $machineId): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $machineId);
}

function validate_ip_or_empty(string $value): bool
{
    return $value === '' || filter_var($value, FILTER_VALIDATE_IP) !== false;
}

function validate_mac_or_empty(string $value): bool
{
    return $value === '' || (bool)preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $value);
}
