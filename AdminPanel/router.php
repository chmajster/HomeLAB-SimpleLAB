<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
if (preg_match('#^/api/v1/?(.*)$#', $path, $m)) {
    $_GET['route'] = $m[1];
    require __DIR__ . '/api/index.php';
    return true;
}
require __DIR__ . '/index.php';
