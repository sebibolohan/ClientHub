<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$error = '';
$editingClient = null;

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var(
        $_POST['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($action === 'delete') {
        if ($id === false || $id === null) {
            http_response_code(400);
            exit('Invalid client ID.');
        }

        $stmt = $pdo->prepare(
            'DELETE FROM clients WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$id, $userId]);

        header('Location: clients.php');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $company = trim((string) ($_POST['company'] ?? ''));

        if (
            $name === '' ||
            mb_strlen($name) > 150 ||
            mb_strlen($email) > 255 ||
            mb_strlen($phone) > 30 ||
            mb_strlen($company) > 150 ||
            ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
        ) {
            $error = 'Please provide valid client information.';
        } elseif ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO clients
                 (user_id, name, email, phone, company)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $userId,
                $name,
                $email ?: null,
                $phone ?: null,
                $company ?: null,
            ]);

            header('Location: clients.php');
            exit;
        } elseif ($id === false || $id === null) {
            $error = 'Invalid client ID.';
        } else {
            $stmt = $pdo->prepare(
                'UPDATE clients
                 SET name = ?, email = ?, phone = ?, company = ?
                 WHERE id = ? AND user_id = ?'
            );

            $stmt->execute([
                $name,
                $email ?: null,
                $phone ?: null,
                $company ?: null,
                $id,
                $userId,
            ]);

            header('Location: clients.php');
            exit;
        }
    }
}

$editId = filter_var(
    $_GET['edit'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($editId !== false && $editId !== null) {
    $stmt = $pdo->prepare(
        'SELECT * FROM clients WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$editId, $userId]);
    $editingClient = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare(
    'SELECT * FROM clients
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC'
);
$stmt->execute([$userId]);
$clients = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clients | ClientHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">ClientHub</a>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
    </div>
</nav>

<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Clients</h1>
            <p class="text-muted mb-0">Manage your customer relationships.</p>
        </div>
        <span class="badge bg-primary fs-6">
            <?= count($clients) ?> clients
        </span>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= escape($error) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            <h2 class="h5 mb-3">
                <?= $editingClient ? 'Edit client' : 'Add new client' ?>
            </h2>

            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token"
                       value="<?= escape(csrfToken()) ?>">
                <input type="hidden" name="action"
                       value="<?= $editingClient ? 'update' : 'create' ?>">

                <?php if ($editingClient): ?>
                    <input type="hidden" name="id"
                           value="<?= (int) $editingClient['id'] ?>">
                <?php endif; ?>

                <div class="col-md-6">
                    <label for="name" class="form-label">Full name *</label>
                    <input id="name" name="name" class="form-control"
                           maxlength="150" required
                           value="<?= escape((string) ($editingClient['name'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label for="email" class="form-label">Email</label>
                    <input id="email" type="email" name="email"
                           class="form-control" maxlength="255"
                           value="<?= escape((string) ($editingClient['email'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input id="phone" name="phone" class="form-control"
                           maxlength="30"
                           value="<?= escape((string) ($editingClient['phone'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label for="company" class="form-label">Company</label>
                    <input id="company" name="company" class="form-control"
                           maxlength="150"
                           value="<?= escape((string) ($editingClient['company'] ?? '')) ?>">
                </div>

                <div class="col-12">
                    <button class="btn btn-primary">
                        <?= $editingClient ? 'Save changes' : 'Add client' ?>
                    </button>

                    <?php if ($editingClient): ?>
                        <a href="clients.php" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">All clients</h2>
                <input type="search" id="clientSearch"
                       class="form-control w-50"
                       placeholder="Search clients...">
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Company</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="clientsTable">
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td class="fw-semibold">
                                    <?= escape((string) $client['name']) ?>
                                </td>
                                <td><?= escape((string) ($client['email'] ?? '')) ?></td>
                                <td><?= escape((string) ($client['phone'] ?? '')) ?></td>
                                <td><?= escape((string) ($client['company'] ?? '')) ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="clients.php?edit=<?= (int) $client['id'] ?>">
                                            Edit
                                        </a>

                                        <form method="post"
                                              onsubmit="return confirm('Delete this client and all associated projects?');">
                                            <input type="hidden" name="csrf_token"
                                                   value="<?= escape(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id"
                                                   value="<?= (int) $client['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$clients): ?>
                <p class="text-muted text-center py-4">
                    No clients yet. Add your first client above.
                </p>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
const searchInput = document.getElementById('clientSearch');
const rows = document.querySelectorAll('#clientsTable tr');

searchInput.addEventListener('input', () => {
    const query = searchInput.value.toLocaleLowerCase();

    rows.forEach(row => {
        const text = row.textContent.toLocaleLowerCase();
        row.hidden = !text.includes(query);
    });
});
</script>
</body>
</html>
