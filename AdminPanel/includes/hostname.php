<?php

declare(strict_types=1);

function validate_hostname_pattern(string $pattern): bool
{
    if (strlen($pattern) < 2 || strlen($pattern) > 63) {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9Xx#-]+$/', $pattern)) {
        return false;
    }
    if (!preg_match('/(?:X+|x+|#+)/', $pattern, $match)) {
        return false;
    }
    $withoutPlaceholder = preg_replace('/(?:X+|x+|#+)/', '0', $pattern, 1);
    if ($withoutPlaceholder === null || preg_match('/[Xx#]/', $withoutPlaceholder)) {
        return false;
    }
    return $pattern[0] !== '-' && $pattern[strlen($pattern) - 1] !== '-';
}

function format_hostname(string $pattern, int $number): string
{
    if (!validate_hostname_pattern($pattern)) {
        throw new InvalidArgumentException('Invalid hostname pattern');
    }
    preg_match('/(X+|x+|#+)/', $pattern, $matches, PREG_OFFSET_CAPTURE);
    $placeholder = $matches[1][0];
    $offset = $matches[1][1];
    $width = strlen($placeholder);
    $digits = (string)$number;
    if (strlen($digits) > $width) {
        throw new OverflowException('Hostname pattern number space exhausted');
    }
    $replacement = str_pad($digits, $width, '0', STR_PAD_LEFT);
    $hostname = substr($pattern, 0, $offset) . $replacement . substr($pattern, $offset + $width);
    if (!validate_hostname($hostname)) {
        throw new RuntimeException('Generated hostname is invalid');
    }
    return $hostname;
}

function validate_hostname(string $hostname): bool
{
    return strlen($hostname) >= 1
        && strlen($hostname) <= 63
        && (bool)preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $hostname);
}

function active_pattern(PDO $db): array
{
    $row = $db->query('SELECT * FROM hostname_patterns WHERE active = 1 ORDER BY id LIMIT 1')->fetch();
    if (!$row) {
        throw new RuntimeException('No active hostname pattern configured');
    }
    return $row;
}

function preview_next_hostname(PDO $db): string
{
    $pattern = active_pattern($db);
    return format_hostname((string)$pattern['pattern'], (int)$pattern['current_number'] + 1);
}

function allocate_hostname(PDO $db): string
{
    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE TRANSACTION');
        $started = true;
        $pattern = active_pattern($db);
        $next = (int)$pattern['current_number'] + 1;
        if ($next < (int)$pattern['start_number']) {
            $next = (int)$pattern['start_number'];
        }
        $hostname = format_hostname((string)$pattern['pattern'], $next);

        $exists = $db->prepare('SELECT 1 FROM virtual_machines WHERE hostname = :hostname');
        $exists->execute([':hostname' => $hostname]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('Generated hostname already exists: ' . $hostname);
        }

        $update = $db->prepare('UPDATE hostname_patterns SET current_number = :current, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute([':current' => $next, ':id' => (int)$pattern['id']]);
        $db->commit();
        return $hostname;
    } catch (Throwable $e) {
        if ($started && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function register_or_get_vm(PDO $db, array $input, string $puppetServer, string $puppetEnvironment): array
{
    $db->exec('BEGIN IMMEDIATE TRANSACTION');
    try {
        $find = $db->prepare('SELECT * FROM virtual_machines WHERE machine_id = :machine_id');
        $find->execute([':machine_id' => $input['machine_id']]);
        $existing = $find->fetch();
        if ($existing) {
            $update = $db->prepare(
                'UPDATE virtual_machines SET ip_address = :ip, mac_address = :mac, os = :os, os_version = :os_version,
                 architecture = :architecture, last_seen = CURRENT_TIMESTAMP WHERE machine_id = :machine_id'
            );
            $update->execute([
                ':ip' => $input['ip_address'], ':mac' => $input['mac_address'], ':os' => $input['os'],
                ':os_version' => $input['os_version'], ':architecture' => $input['architecture'], ':machine_id' => $input['machine_id'],
            ]);
            $find->execute([':machine_id' => $input['machine_id']]);
            $row = $find->fetch();
            $db->commit();
            $row['existing'] = true;
            return $row;
        }

        $pattern = active_pattern($db);
        $next = max((int)$pattern['start_number'], (int)$pattern['current_number'] + 1);
        $hostname = format_hostname((string)$pattern['pattern'], $next);

        $updatePattern = $db->prepare('UPDATE hostname_patterns SET current_number = :current, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $updatePattern->execute([':current' => $next, ':id' => (int)$pattern['id']]);

        $insert = $db->prepare(
            'INSERT INTO virtual_machines(machine_id, hostname, ip_address, mac_address, os, os_version, architecture, puppet_server, puppet_environment)
             VALUES(:machine_id, :hostname, :ip, :mac, :os, :os_version, :architecture, :puppet_server, :puppet_environment)'
        );
        $insert->execute([
            ':machine_id' => $input['machine_id'], ':hostname' => $hostname, ':ip' => $input['ip_address'],
            ':mac' => $input['mac_address'], ':os' => $input['os'], ':os_version' => $input['os_version'],
            ':architecture' => $input['architecture'], ':puppet_server' => $puppetServer, ':puppet_environment' => $puppetEnvironment,
        ]);
        $id = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM virtual_machines WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        $db->commit();
        $row['existing'] = false;
        return $row;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
