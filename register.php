
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
        $name = $_POST['name'] ?? null;
        $email = $_POST['email'] ?? null;
        $password = $_POST['password'] ?? null;

        $name = is_string($name) ? trim($name) : '';
        $email = is_string($email) ? trim($email) : '';
        $password = is_string($password) ? $password : '';

        if (
            $name === '' ||
            mb_strlen($name, 'UTF-8') > 100 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 255 ||
            strlen($password) < 8 ||
            strlen($password) > 1024
        ) {
            $error = 'Please check your input. The password must contain between 8 and 1024 characters.';
        } else {
            $pdo = getDatabaseConnection();

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password)
                     VALUES (:name, :email, :password)'
                );

                $stmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password' => password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                ]);

                header('Location: login.php?registered=1', true, 303);
                exit;
            } catch (PDOException $exception) {
                if (
                    $exception->getCode() === '23000' &&
                    (int) ($exception->errorInfo[1] ?? 0) === 1062
                ) {
                    $error = 'This email address is already registered.';
                } else {
                    error_log($exception->getMessage());
                    $error = 'An error occurred. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register | ClientHub</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 460px;">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h1 class="h3 mb-4">Create account</h1>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        csrfToken(),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <label for="name" class="form-label">
                    Full name
                </label>
                <input
                    id="name"
                    type="text"
                    name="name"
                    class="form-control mb-3"
                    maxlength="100"
                    autocomplete="name"
                    required
                >

                <label for="email" class="form-label">
                    Email
                </label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    class="form-control mb-3"
                    maxlength="255"
                    autocomplete="email"
                    required
                >

                <label for="password" class="form-label">
                    Password
                </label>
                <input
                    id="password"
                    type="password"
                    name="password"
                    class="form-control mb-4"
                    minlength="8"
                    maxlength="1024"
                    autocomplete="new-password"
                    required
                >

                <button type="submit" class="btn btn-primary w-100">
                    Register
                </button>
            </form>

            <p class="mt-3 mb-0 text-center">
                Already registered?
                <a href="login.php">Login</a>
            </p>
        </div>
    </div>
</main>
</body>
</html>
