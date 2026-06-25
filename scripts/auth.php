<?php
require_once __DIR__ . '/env.php';

function startSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn(): bool {
    startSession();
    return !empty($_SESSION['authed']);
}

function login(string $user, string $pass): bool {
    startSession();

    $now = time();

    // Enforce lockout
    if (!empty($_SESSION['lockout_until']) && $now < $_SESSION['lockout_until']) {
        return false;
    }

    loadEnv(__DIR__ . '/../.env');
    $adminUser = $_ENV['ADMIN_USER']          ?? '';
    $adminHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';

    $ok = $adminUser !== '' && $adminHash !== ''
       && hash_equals($adminUser, $user)
       && password_verify($pass, $adminHash);

    if ($ok) {
        $_SESSION['login_fails']   = 0;
        $_SESSION['lockout_until'] = 0;
        $_SESSION['authed']        = true;
        $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
        session_regenerate_id(true);
        return true;
    }

    sleep(1);
    $_SESSION['login_fails'] = ($_SESSION['login_fails'] ?? 0) + 1;
    if ($_SESSION['login_fails'] >= 5) {
        $_SESSION['lockout_until'] = $now + 300; // 5 minutes
    }
    return false;
}

function logout(): void {
    startSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function verifyCsrf(): void {
    startSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? $_POST['csrf_token']
          ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}

function requireAuth(): void {
    startSession();
    if (!isLoggedIn()) {
        $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')
              || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/ajax');
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthenticated']);
            exit;
        }
        header('Location: /login.php');
        exit;
    }
}
