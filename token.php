<?php
declare(strict_types=1);

/**
 * Выдаёт CSRF-токен для формы заявки (double-submit).
 * Ставит HttpOnly-cookie csrf_token и возвращает его же в JSON —
 * script.js кладёт значение в скрытое поле формы, send.php сверяет.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = bin2hex(random_bytes(16));
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

setcookie('csrf_token', $token, [
    'expires' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);

echo json_encode(['token' => $token]);