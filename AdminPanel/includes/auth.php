<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $provided = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    if ($stored === '' || !hash_equals($stored, $provided)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /?page=login');
        exit;
    }
}

function login_user(PDO $db, string $username, string $password): bool
{
    $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = :username AND active = 1');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $username;
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
