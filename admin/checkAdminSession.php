<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$timeoutSeconds = (int)($_SESSION['admin_timeout_seconds'] ?? 300);
if ($timeoutSeconds < 300) {
    $timeoutSeconds = 300;
}
$authenticated = isset($_SESSION['admin_id'], $_SESSION['admin_correo']);

if ($authenticated) {
    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > $timeoutSeconds) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        $authenticated = false;
    } else {
        $_SESSION['admin_last_activity'] = time();
    }
}

echo json_encode([
    'authenticated' => $authenticated,
    'email' => $authenticated ? $_SESSION['admin_correo'] : null,
    'role' => $authenticated ? ($_SESSION['admin_rol'] ?? 'ADMIN') : null,
    'timeoutSeconds' => $authenticated ? $timeoutSeconds : null,
], JSON_UNESCAPED_UNICODE);
