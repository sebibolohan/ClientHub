<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

startAppSession();

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken()) {
        http_response_code(403);
        $error = 'Invalid request. Please reload the page.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email or password.';
        } else {
            $pdo = getDatabaseConnection();

            $stmt = $pdo->prepare(
                'SELECT id, name, password FROM users WHERE email = :email'
            );
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_name'] = $user['name'];

                header('Location: dashboard.php');
                exit;
            }

            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | ClientHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 460px;">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h1 class="h3 mb-4">Welcome back</h1>

            <?php if (isset($_GET['registered'])): ?>
                <div class="alert alert-success">Account created. Please log in.</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <label for="email" class="form-label">Email</label>
                <input id="email" type="email" name="email"
                       class="form-control mb-3" required>

                <label for="password" class="form-label">Password</label>
                <input id="password" type="password" name="password"
                       class="form-control mb-4" required>

                <button class="btn btn-primary w-100">Login</button>
            </form>

            <p class="mt-3 mb-0 text-center">
                No account? <a href="register.php">Register</a>
            </p>
        </div>
    </div>
</main>
</body>
</html>
