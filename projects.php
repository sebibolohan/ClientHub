<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$error = '';
$editingProject = null;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function positiveId(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : $id;
}

function isDuplicateProject(PDOException $exception): bool
{
    return $exception->getCode() === '23000'
        && (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

function optionalMoney(mixed $value, int $integerDigits): string|false|null
{
    if (!is_string($value)) {
        return false;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^(?:0|[0-9]{1,' . $integerDigits . '})(?:\.[0-9]{1,2})?$/D', $value)) {
        return false;
    }

    $parts = explode('.', $value, 2);
    if (strlen(ltrim($parts[0], '0')) > $integerDigits) {
        return false;
    }

    return $value;
}

function optionalDeadline(mixed $value): string|false|null
{
    if (!is_string($value)) {
        return false;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : false;
}

$statuses = [
    'planned' => 'Planned',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];

$stmt = $pdo->prepare('SELECT id, name FROM clients WHERE user_id = ? ORDER BY name, id');
$stmt->execute([$userId]);
$clients = $stmt->fetchAll();
$clientIds = array_map(static fn(array $client): int => (int) $client['id'], $clients);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken()) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    $action = $_POST['action'] ?? null;
    $id = positiveId($_POST['id'] ?? null);

    if ($action === 'delete') {
        if ($id === null) {
            http_response_code(400);
            exit('Invalid project ID.');
        }

        $stmt = $pdo->prepare(
            'DELETE p FROM projects p
             INNER JOIN clients c ON c.id = p.client_id
             WHERE p.id = ? AND c.user_id = ?'
        );
        $stmt->execute([$id, $userId]);
        header('Location: projects.php');
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $name = $_POST['name'] ?? null;
        $description = $_POST['description'] ?? null;
        $status = $_POST['status'] ?? null;
        $clientId = positiveId($_POST['client_id'] ?? null);
        $budgetInput = $_POST['budget'] ?? null;
        $rateInput = $_POST['hourly_rate'] ?? null;
        $deadlineInput = $_POST['deadline'] ?? null;
        $budget = optionalMoney($budgetInput, 10);
        $hourlyRate = optionalMoney($rateInput, 8);
        $deadline = optionalDeadline($deadlineInput);

        $name = is_string($name) ? trim($name) : '';
        $description = is_string($description) ? trim($description) : '';

        if (
            $name === '' ||
            mb_strlen($name) > 150 ||
            mb_strlen($description) > 5000 ||
            !is_string($status) ||
            !array_key_exists($status, $statuses) ||
            $clientId === null ||
            !in_array($clientId, $clientIds, true) ||
            $budget === false ||
            $hourlyRate === false ||
            $deadline === false
        ) {
            $error = 'Please provide valid project information.';
        } elseif ($action === 'update' && $id === null) {
            $error = 'Invalid project ID.';
        } else {
            try {
                if ($action === 'create') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO projects (client_id, name, description, status, budget, hourly_rate, deadline)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$clientId, $name, $description, $status, $budget, $hourlyRate, $deadline]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE projects p
                         INNER JOIN clients c ON c.id = p.client_id
                         SET p.client_id = ?, p.name = ?, p.description = ?, p.status = ?,
                             p.budget = ?, p.hourly_rate = ?, p.deadline = ?
                         WHERE p.id = ? AND c.user_id = ?'
                    );
                    $stmt->execute([$clientId, $name, $description, $status, $budget, $hourlyRate, $deadline, $id, $userId]);
                }

                header('Location: projects.php');
                exit;
            } catch (PDOException $exception) {
                if (isDuplicateProject($exception)) {
                    $error = 'A project with this name already exists for the selected client.';
                } else {
                    throw $exception;
                }
            }
        }
    } elseif ($action !== 'delete') {
        http_response_code(400);
        exit('Invalid action.');
    }
}

$editId = positiveId($_GET['edit'] ?? null);
if ($editId === null && $error !== '' && ($_POST['action'] ?? null) === 'update') {
    $editId = positiveId($_POST['id'] ?? null);
}
if ($editId !== null) {
    $stmt = $pdo->prepare(
        'SELECT p.* FROM projects p
         INNER JOIN clients c ON c.id = p.client_id
         WHERE p.id = ? AND c.user_id = ?'
    );
    $stmt->execute([$editId, $userId]);
    $editingProject = $stmt->fetch() ?: null;
}

$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS client_name
     FROM projects p
     INNER JOIN clients c ON c.id = p.client_id
     WHERE c.user_id = ?
     ORDER BY p.created_at DESC, p.id DESC'
);
$stmt->execute([$userId]);
$projects = $stmt->fetchAll();

$formName = $editingProject['name'] ?? '';
$formDescription = $editingProject['description'] ?? '';
$formStatus = $editingProject['status'] ?? 'planned';
$formClientId = (int) ($editingProject['client_id'] ?? 0);
$formBudget = (string) ($editingProject['budget'] ?? '');
$formHourlyRate = (string) ($editingProject['hourly_rate'] ?? '');
$formDeadline = (string) ($editingProject['deadline'] ?? '');

if ($error !== '' && (($_POST['action'] ?? null) === 'create' || $editingProject !== null)) {
    $formName = $name;
    $formDescription = $description;
    $formStatus = is_string($status) && isset($statuses[$status]) ? $status : 'planned';
    $formClientId = $clientId ?? 0;
    $formBudget = is_string($budgetInput) ? $budgetInput : '';
    $formHourlyRate = is_string($rateInput) ? $rateInput : '';
    $formDeadline = is_string($deadlineInput) ? $deadlineInput : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Projects | ClientHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">ClientHub</a>
        <div class="d-flex gap-2">
            <a href="clients.php" class="btn btn-outline-light btn-sm">Clients</a>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-1">Projects</h1>
            <p class="text-muted mb-0">Manage client projects and track their progress.</p>
        </div>
        <span class="badge bg-primary fs-6"><?= count($projects) ?> projects</span>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$clients): ?>
        <div class="alert alert-warning">
            You need at least one client before creating a project.
            <a href="clients.php">Add a client</a>.
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-4">
            <h2 class="h5 mb-3"><?= $editingProject ? 'Edit project' : 'Create project' ?></h2>
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $editingProject ? 'update' : 'create' ?>">
                <?php if ($editingProject): ?>
                    <input type="hidden" name="id" value="<?= (int) $editingProject['id'] ?>">
                <?php endif; ?>

                <div class="col-md-6">
                    <label for="name" class="form-label">Project name *</label>
                    <input id="name" name="name" class="form-control" maxlength="150" required
                           value="<?= e((string) $formName) ?>">
                </div>
                <div class="col-md-6">
                    <label for="client_id" class="form-label">Client *</label>
                    <select id="client_id" name="client_id" class="form-select" required>
                        <option value="">Select client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>"
                                <?= $formClientId === (int) $client['id'] ? 'selected' : '' ?>>
                                <?= e((string) $client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select id="status" name="status" class="form-select">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $formStatus === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="budget" class="form-label">Budget</label>
                    <input id="budget" name="budget" type="number" min="0" max="9999999999.99"
                           step="0.01" class="form-control" value="<?= e($formBudget) ?>">
                </div>
                <div class="col-md-4">
                    <label for="hourly_rate" class="form-label">Hourly rate</label>
                    <input id="hourly_rate" name="hourly_rate" type="number" min="0" max="99999999.99"
                           step="0.01" class="form-control" value="<?= e($formHourlyRate) ?>">
                </div>
                <div class="col-md-4">
                    <label for="deadline" class="form-label">Deadline</label>
                    <input id="deadline" name="deadline" type="date"
                           class="form-control" value="<?= e($formDeadline) ?>">
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Description</label>
                    <textarea id="description" name="description" class="form-control" rows="3"
                              maxlength="5000"><?= e((string) $formDescription) ?></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" <?= !$clients ? 'disabled' : '' ?>>
                        <?= $editingProject ? 'Save changes' : 'Create project' ?>
                    </button>
                    <?php if ($editingProject): ?>
                        <a href="projects.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3 gap-3">
                <h2 class="h5 mb-0">All projects</h2>
                <select id="statusFilter" class="form-select w-auto" aria-label="Filter projects by status">
                    <option value="all">All statuses</option>
                    <?php foreach ($statuses as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Project</th>
                            <th>Client</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="projectsTable">
                        <?php foreach ($projects as $project): ?>
                            <tr data-status="<?= e((string) $project['status']) ?>">
                            <td class="fw-semibold">
                                <a href="project.php?id=<?= (int) $project['id'] ?>"class="text-decoration-none">
                             <?= e((string) $project['name']) ?>
                                 </a>
                            </td>
                            <td><?= e((string) $project['client_name']) ?></td>
                                <td><?= e((string) ($project['description'] ?? '')) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = match ($project['status']) {
                                        'completed' => 'bg-success',
                                        'in_progress' => 'bg-primary',
                                        default => 'bg-secondary',
                                    };
                                    ?>
                                    <span class="badge <?= $badgeClass ?>">
                                        <?= e($statuses[$project['status']]) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="projects.php?edit=<?= (int) $project['id'] ?>"
                                           class="btn btn-sm btn-outline-primary">Edit</a>
                                        <form method="post" onsubmit="return confirm('Delete this project?');">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $project['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$projects): ?>
                <p class="text-muted text-center py-4">No projects yet. Create your first project above.</p>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
const statusFilter = document.getElementById('statusFilter');
const projectRows = document.querySelectorAll('#projectsTable tr');

statusFilter.addEventListener('change', () => {
    const selectedStatus = statusFilter.value;
    projectRows.forEach(row => {
        row.style.display = selectedStatus === 'all' || row.dataset.status === selectedStatus
            ? ''
            : 'none';
    });
});
</script>
</body>
</html>
