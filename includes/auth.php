<?php
declare(strict_types=1);

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

function requireLogin(): void
{
    startAppSession();

    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function csrfToken(): string
{
    startAppSession();

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validCsrfToken(): bool
{
    startAppSession();

    return isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals(csrfToken(), $_POST['csrf_token']);
}
