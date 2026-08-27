<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HomeLAB SimpleLAB - Login</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="login-body">
<form method="post" class="login-card">
    <h1>HomeLAB SimpleLAB</h1>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label>Login<input name="username" value="admin" required></label>
    <label>Hasło<input type="password" name="password" required autofocus></label>
    <button type="submit">Zaloguj</button>
</form>
</body>
</html>
