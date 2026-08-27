<?php

declare(strict_types=1);

require __DIR__ . '/../config/bootstrap.php';

$route = trim((string)($_GET['route'] ?? ''), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($route === 'health' && $method === 'GET') {
        $version = trim((string)@file_get_contents(__DIR__ . '/../../VERSION')) ?: '0.1.0';
        json_response(['status' => 'ok', 'service' => 'HomeLAB-SimpleLAB', 'version' => $version]);
    }

    require_api_auth($db);

    if ($route === 'onboarding' && $method === 'POST') {
        $data = request_json();
        $machineId = trim((string)($data['machine_id'] ?? ''));
        $ip = trim((string)($data['ip'] ?? ''));
        $mac = strtoupper(trim((string)($data['mac'] ?? '')));
        if (!validate_machine_id($machineId)) {
            json_response(['success' => false, 'error' => 'Invalid machine_id'], 422);
        }
        if (!validate_ip_or_empty($ip)) {
            json_response(['success' => false, 'error' => 'Invalid IP address'], 422);
        }
        if (!validate_mac_or_empty($mac)) {
            json_response(['success' => false, 'error' => 'Invalid MAC address'], 422);
        }

        $input = [
            'machine_id' => $machineId,
            'ip_address' => $ip,
            'mac_address' => $mac,
            'os' => substr(trim((string)($data['os'] ?? '')), 0, 64),
            'os_version' => substr(trim((string)($data['os_version'] ?? '')), 0, 32),
            'architecture' => substr(trim((string)($data['architecture'] ?? '')), 0, 32),
        ];
        $puppetServer = (string)setting($db, 'puppet_server', 'puppet.lab.local');
        $puppetServerIp = (string)setting($db, 'puppet_server_ip', '');
        $environment = (string)setting($db, 'puppet_environment', 'production');
        $port = (int)setting($db, 'puppet_port', '8140');
        $row = register_or_get_vm($db, $input, $puppetServer, $environment);

        app_log($config, $row['existing'] ? 'vm_onboarding_existing' : 'vm_onboarding_new', [
            'machine_id' => $machineId,
            'hostname' => $row['hostname'],
            'ip' => $ip,
            'remote_ip' => client_ip(),
        ]);

        json_response([
            'success' => true,
            'existing' => (bool)$row['existing'],
            'hostname' => $row['hostname'],
            'domain' => setting($db, 'domain', ''),
            'puppet' => [
                'server' => $row['puppet_server'],
                'server_ip' => $puppetServerIp,
                'port' => $port,
                'environment' => $row['puppet_environment'],
            ],
        ], $row['existing'] ? 200 : 201);
    }

    if ($route === 'settings' && $method === 'GET') {
        json_response([
            'success' => true,
            'puppet' => [
                'server' => setting($db, 'puppet_server', 'puppet.lab.local'),
                'server_ip' => setting($db, 'puppet_server_ip', ''),
                'port' => (int)setting($db, 'puppet_port', '8140'),
                'environment' => setting($db, 'puppet_environment', 'production'),
            ],
            'hostname' => [
                'next' => preview_next_hostname($db),
            ],
        ]);
    }

    if ($route === 'vms' && $method === 'GET') {
        $rows = $db->query('SELECT * FROM virtual_machines ORDER BY id DESC')->fetchAll();
        json_response(['success' => true, 'data' => $rows]);
    }

    if (preg_match('#^vms/(\d+)$#', $route, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM virtual_machines WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            $row ? json_response(['success' => true, 'data' => $row]) : json_response(['success' => false, 'error' => 'VM not found'], 404);
        }
        if ($method === 'DELETE') {
            $stmt = $db->prepare('DELETE FROM virtual_machines WHERE id = :id');
            $stmt->execute([':id' => $id]);
            if ($stmt->rowCount() === 0) {
                json_response(['success' => false, 'error' => 'VM not found'], 404);
            }
            json_response(['success' => true]);
        }
    }

    if ($route === 'patterns' && $method === 'GET') {
        $rows = $db->query('SELECT * FROM hostname_patterns ORDER BY id')->fetchAll();
        foreach ($rows as &$row) {
            try {
                $row['next_hostname'] = format_hostname((string)$row['pattern'], (int)$row['current_number'] + 1);
            } catch (Throwable) {
                $row['next_hostname'] = null;
            }
        }
        json_response(['success' => true, 'data' => $rows]);
    }

    json_response(['success' => false, 'error' => 'Endpoint not found'], 404);
} catch (PDOException $e) {
    app_log($config, 'api_database_error', ['message' => $e->getMessage(), 'route' => $route]);
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Throwable $e) {
    app_log($config, 'api_error', ['message' => $e->getMessage(), 'route' => $route]);
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
