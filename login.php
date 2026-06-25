<?php
require_once __DIR__ . '/scripts/auth.php';
startSession();

if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    $now = time();
    if (!empty($_SESSION['lockout_until']) && $now < $_SESSION['lockout_until']) {
        $error = 'Too many failed attempts. Try again later.';
    } elseif (login($user, $pass)) {
        header('Location: /');
        exit;
    } else {
        $error = 'Invalid credentials.';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AIINF — Login</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #0f0f0f;
            color: #eaeaea;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .box {
            width: 320px;
            background: #141414;
            border: 1px solid #1e1e1e;
            border-radius: 10px;
            padding: 36px 32px;
        }
        h1 { font-size: 16px; font-weight: 700; margin-bottom: 24px; color: #fff; }
        label { display: block; font-size: 12px; color: #666; margin-bottom: 5px; }
        input {
            display: block;
            width: 100%;
            padding: 9px 12px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            color: #eee;
            font-size: 14px;
            margin-bottom: 14px;
        }
        input:focus { outline: none; border-color: #4da3ff; }
        button {
            width: 100%;
            padding: 10px;
            background: #1e3a5f;
            color: #93c5fd;
            border: 0;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 4px;
        }
        button:hover { background: #1d4ed8; }
        .err {
            background: #1c0a0a;
            border: 1px solid #3a1010;
            color: #f87171;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
<div class="box">
    <h1>AIINF Control Center</h1>
    <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="/login.php">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
