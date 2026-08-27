<?php

declare(strict_types=1);

require __DIR__ . '/config/bootstrap.php';

$page = (string)($_GET['page'] ?? 'dashboard');
$allowed = ['dashboard', 'vms', 'patterns', 'puppet', 'api', 'settings', 'login'];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$message = null;
$error = null;
$newApiToken = null;

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (login_user($db, trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
            header('Location: /');
            exit;
        }
        $error = 'Nieprawidłowy login lub hasło.';
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

if (isset($_GET['logout'])) {
    logout_user();
    header('Location: /?page=login');
    exit;
}

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_puppet') {
            $server = trim((string)($_POST['puppet_server'] ?? ''));
            $serverIp = trim((string)($_POST['puppet_server_ip'] ?? ''));
            $port = (int)($_POST['puppet_port'] ?? 8140);
            $environment = trim((string)($_POST['puppet_environment'] ?? 'production'));
            if ($server === '' || !preg_match('/^[A-Za-z0-9.-]+$/', $server)) throw new InvalidArgumentException('Nieprawidłowy Puppet Master.');
            if ($serverIp !== '' && filter_var($serverIp, FILTER_VALIDATE_IP) === false) throw new InvalidArgumentException('Nieprawidłowy adres IP Puppet Master.');
            if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Nieprawidłowy port Puppet.');
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $environment)) throw new InvalidArgumentException('Nieprawidłowy environment.');
            set_setting($db, 'puppet_server', $server); set_setting($db, 'puppet_server_ip', $serverIp); set_setting($db, 'puppet_port', (string)$port); set_setting($db, 'puppet_environment', $environment);
            $message = 'Ustawienia Puppet zapisane.';
        } elseif ($action === 'add_pattern') {
            $name = trim((string)($_POST['name'] ?? '')); $pattern = strtoupper(trim((string)($_POST['pattern'] ?? ''))); $start = max(0, (int)($_POST['start_number'] ?? 1));
            if ($name === '' || strlen($name) > 100 || !validate_hostname_pattern($pattern)) throw new InvalidArgumentException('Nieprawidłowy wzorzec hostname.');
            $stmt = $db->prepare('INSERT INTO hostname_patterns(name, pattern, start_number, current_number, active) VALUES(:name,:pattern,:start,:current,0)');
            $stmt->execute([':name'=>$name, ':pattern'=>$pattern, ':start'=>$start, ':current'=>max(0,$start-1)]); app_log($config, 'hostname_pattern_created', ['pattern'=>$pattern]); $message='Wzorzec dodany.';
        } elseif ($action === 'update_pattern') {
            $id=(int)($_POST['id']??0); $name=trim((string)($_POST['name']??'')); $pattern=strtoupper(trim((string)($_POST['pattern']??''))); $start=max(0,(int)($_POST['start_number']??1));
            if ($name==='' || strlen($name)>100 || !validate_hostname_pattern($pattern)) throw new InvalidArgumentException('Nieprawidłowy wzorzec hostname.');
            $q=$db->prepare('SELECT current_number FROM hostname_patterns WHERE id=:id'); $q->execute([':id'=>$id]); $current=$q->fetchColumn(); if($current===false) throw new RuntimeException('Nie znaleziono wzorca.');
            if((int)$current<$start-1) $current=$start-1; $stmt=$db->prepare('UPDATE hostname_patterns SET name=:name, pattern=:pattern, start_number=:start, current_number=:current, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $stmt->execute([':name'=>$name,':pattern'=>$pattern,':start'=>$start,':current'=>(int)$current,':id'=>$id]); app_log($config,'hostname_pattern_updated',['id'=>$id,'pattern'=>$pattern]); $message='Wzorzec zaktualizowany.';
        } elseif ($action === 'activate_pattern') {
            $id=(int)($_POST['id']??0); $db->beginTransaction(); $db->exec('UPDATE hostname_patterns SET active = 0'); $stmt=$db->prepare('UPDATE hostname_patterns SET active=1, updated_at=CURRENT_TIMESTAMP WHERE id=:id'); $stmt->execute([':id'=>$id]); if($stmt->rowCount()!==1) throw new RuntimeException('Nie znaleziono wzorca.'); $db->commit(); app_log($config,'hostname_pattern_activated',['id'=>$id]); $message='Aktywny wzorzec zmieniony.';
        } elseif ($action === 'delete_pattern') {
            $id=(int)($_POST['id']??0); $stmt=$db->prepare('DELETE FROM hostname_patterns WHERE id=:id AND active=0'); $stmt->execute([':id'=>$id]); if($stmt->rowCount()!==1) throw new RuntimeException('Nie można usunąć aktywnego wzorca.'); $message='Wzorzec usunięty.';
        } elseif ($action === 'delete_vm') {
            $id=(int)($_POST['id']??0); $stmt=$db->prepare('DELETE FROM virtual_machines WHERE id=:id'); $stmt->execute([':id'=>$id]); $message='Wpis VM usunięty.';
        } elseif ($action === 'rotate_api_token') {
            $newApiToken='slab_'.bin2hex(random_bytes(24)); $db->beginTransaction(); $db->exec('UPDATE api_tokens SET active=0'); $stmt=$db->prepare('INSERT INTO api_tokens(name, token_hash, active) VALUES(:name,:hash,1)'); $stmt->execute([':name'=>'ui-rotated',':hash'=>password_hash($newApiToken,PASSWORD_DEFAULT)]); $db->commit(); app_log($config,'api_token_rotated',['user'=>(string)($_SESSION['username']??'')]); $message='Token API został zmieniony. Skopiuj nową wartość teraz.';
        } elseif ($action === 'save_settings') {
            $appName=trim((string)($_POST['application_name']??'HomeLAB SimpleLAB')); $baseUrl=trim((string)($_POST['base_url']??'')); if($appName===''||strlen($appName)>100) throw new InvalidArgumentException('Nieprawidłowa nazwa aplikacji.'); if($baseUrl!==''&&filter_var($baseUrl,FILTER_VALIDATE_URL)===false) throw new InvalidArgumentException('Nieprawidłowy Base URL.'); set_setting($db,'application_name',$appName); set_setting($db,'base_url',rtrim($baseUrl,'/')); $message='Ustawienia zapisane.';
        }
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $error=$e->getMessage(); }
}

$appName = setting($db, 'application_name', 'HomeLAB SimpleLAB');
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($appName) ?></title><link rel="stylesheet" href="/assets/css/style.css"></head><body>
<header class="topbar"><div><strong><?= e($appName) ?></strong></div><div class="user"><?= e((string)($_SESSION['username'] ?? '')) ?> · <a href="/?logout=1">Wyloguj</a></div></header>
<div class="layout"><nav class="sidebar"><a class="<?= $page==='dashboard'?'active':'' ?>" href="/?page=dashboard">Dashboard</a><a class="<?= $page==='vms'?'active':'' ?>" href="/?page=vms">VMs</a><a class="<?= $page==='patterns'?'active':'' ?>" href="/?page=patterns">Hostname Patterns</a><a class="<?= $page==='puppet'?'active':'' ?>" href="/?page=puppet">Puppet</a><a class="<?= $page==='api'?'active':'' ?>" href="/?page=api">API</a><a class="<?= $page==='settings'?'active':'' ?>" href="/?page=settings">Settings</a></nav><main class="content">
<?php if($message): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?><?php if($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?><?php require __DIR__.'/pages/'.$page.'.php'; ?></main></div><script src="/assets/js/app.js"></script></body></html>
