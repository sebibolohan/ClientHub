<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validCsrfToken()) {
    http_response_code(403);
    exit('Forbidden');
}

$_SESSION = [];

session_destroy();

header('Location: login.php');
exit;
