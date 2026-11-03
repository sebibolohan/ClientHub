<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$projectId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($projectId)) {
    http_response_code(400);
    exit('Invalid project ID.');
}

$stmt = $pdo->prepare(
    'SELECT p.*, c.name AS client_name
     FROM projects p
     INNER JOIN clients c ON c.id = p.client_id
     WHERE p.id = ? AND c.user_id = ?'
);
$stmt->execute([$projectId, $userId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    exit('Project not found.');
}

$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) AS total_received
     FROM payments
     WHERE project_id = ?'
);
$stmt->execute([$projectId]);

$totalReceived = (float) $stmt->fetchColumn();

$projectBudget = $project['budget'] === null
    ? null
    : (float) $project['budget'];

$remainingBalance = $projectBudget === null
    ? null
    : $projectBudget - $totalReceived;

$stmt = $pdo->prepare(
    'SELECT id, amount, paid_at, notes
     FROM payments
     WHERE project_id = ?
     ORDER BY paid_at DESC, id DESC'
);
$stmt->execute([$projectId]);

$payments = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT id, title, description, status, priority, due_date
     FROM tasks
     WHERE project_id = ?
     ORDER BY position ASC, id ASC'
);
$stmt->execute([$projectId]);
$tasks = $stmt->fetchAll();

$totalTasks = count($tasks);
$completedTasks = count(array_filter(
    $tasks,
    static fn(array $task): bool => $task['status'] === 'completed'
));
$progress = $totalTasks > 0
    ? (int) round(100 * $completedTasks / $totalTasks)
    : 0;

$projectStatuses = [
    'planned' => 'Planned',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];
$taskStatuses = [
    'todo' => 'To Do',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];
$priorities = [
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
];
$priorityColors = [
    'low' => 'secondary',
    'medium' => 'warning',
    'high' => 'danger',
];
$statusColors = [
    'todo' => 'secondary',
    'in_progress' => 'primary',
    'completed' => 'success',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e((string) $project['name']) ?> | ClientHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a href="dashboard.php" class="navbar-brand fw-bold">ClientHub</a>
        <div class="d-flex gap-2">
            <a href="projects.php" class="btn btn-outline-light btn-sm">Projects</a>
            <a href="kanban.php?id=<?= (int) $projectId ?>"class="btn btn-outline-primary ms-3">Kanban Board</a>
            <a href="time-tracking.php?id=<?= (int) $projectId ?>"class="btn btn-outline-success ms-2">Time Tracking</a>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">Dashboard</a>
        </div>
    </div>
</nav>

<main class="container py-5">
    <a href="projects.php" class="text-decoration-none">&larr; Back to Projects</a>

    <div class="d-flex justify-content-between align-items-start gap-3 mt-3 mb-4">
        <div>
            <h1 class="fw-bold mb-2"><?= e((string) $project['name']) ?></h1>
            <p class="text-muted mb-0">Client: <?= e((string) $project['client_name']) ?></p>
        </div>
        <span class="badge bg-primary">
            <?= e($projectStatuses[$project['status']]) ?>
        </span>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Project Budget</p>
                    <h2 class="h4 mb-0">
                        <?= $project['budget'] !== null
                            ? number_format((float) $project['budget'], 2) . ' RON'
                            : 'Not set' ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Hourly Rate</p>
                    <h2 class="h4 mb-0">
                        <?= $project['hourly_rate'] !== null
                            ? number_format((float) $project['hourly_rate'], 2) . ' RON'
                            : 'Not set' ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Deadline</p>
                    <h2 class="h4 mb-0">
                        <?= $project['deadline'] !== null
                            ? e((string) $project['deadline'])
                            : 'Not set' ?>
                    </h2>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Total Received</p>
                    <h2 class="h4 mb-0 text-success">
                        <?= number_format($totalReceived, 2) ?> RON
                    </h2>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <p class="text-muted mb-2">Remaining Balance</p>
                    <h2 class="h4 mb-0">
                        <?= $remainingBalance === null
                            ? 'Budget not set'
                            : number_format($remainingBalance, 2) . ' RON' ?>
                    </h2>
                </div>
            </div>
        </div>
    </div>
    
<section class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Add Payment</h2>

        <form action="payments.php" method="post" class="row g-3">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrfToken()) ?>"
            >
            <input
                type="hidden"
                name="project_id"
                value="<?= (int) $projectId ?>"
            >

            <div class="col-md-4">
                <label for="paymentAmount" class="form-label">
                    Amount (RON) *
                </label>
                <input
                    id="paymentAmount"
                    type="number"
                    name="amount"
                    class="form-control"
                    min="0.01"
                    max="9999999999.99"
                    step="0.01"
                    required
                >
            </div>

            <div class="col-md-4">
                <label for="paymentDate" class="form-label">
                    Payment Date *
                </label>
                <input
                    id="paymentDate"
                    type="date"
                    name="paid_at"
                    class="form-control"
                    max="<?= date('Y-m-d') ?>"
                    value="<?= date('Y-m-d') ?>"
                    required
                >
            </div>

            <div class="col-md-4">
                <label for="paymentNotes" class="form-label">
                    Notes (optional)
                </label>
                <input
                    id="paymentNotes"
                    type="text"
                    name="notes"
                    class="form-control"
                    maxlength="255"
                >
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-success">
                    Add Payment
                </button>
            </div>
        </form>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body p-4">
        <h2 class="h5 mb-3">Payment History</h2>

        <?php if (empty($payments)): ?>
            <p class="text-muted mb-0">
                No payments recorded yet.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Payment Date</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Notes</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <?= e((string) $payment['paid_at']) ?>
                                </td>
                                <td class="fw-semibold text-success">
                                    <?= number_format(
                                        (float) $payment['amount'],
                                        2
                                    ) ?> RON
                                </td>
                                <td>
                                    <?= $payment['notes'] !== null
                                        ? e((string) $payment['notes'])
                                        : '—' ?>
                                </td>
                                
<td class="text-end">
    <form
        action="payments.php"
        method="post"
        onsubmit="return confirm('Are you sure you want to delete this payment?');"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrfToken()) ?>"
        >
        <input
            type="hidden"
            name="action"
            value="delete"
        >
        <input
            type="hidden"
            name="project_id"
            value="<?= (int) $projectId ?>"
        >
        <input
            type="hidden"
            name="payment_id"
            value="<?= (int) $payment['id'] ?>"
        >

        <button type="submit" class="btn btn-outline-danger btn-sm">
            Delete
        </button>
    </form>
</td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

    <section class="card shadow-sm border-0 mb-4" aria-labelledby="progressHeading">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between mb-2">
                <h2 class="h5" id="progressHeading">Project Progress</h2>
                <strong><?= $progress ?>%</strong>
            </div>
            <div class="progress mb-2" role="progressbar" aria-label="Project progress"
                 aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-success" style="width: <?= $progress ?>%"></div>
            </div>
            <p class="text-muted small mb-0">
                <?= $completedTasks ?> of <?= $totalTasks ?> tasks completed
            </p>
        </div>
    </section>

    <section class="card shadow-sm border-0" aria-labelledby="tasksHeading">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h5 mb-0" id="tasksHeading">Project Tasks</h2>
                <span class="badge bg-secondary"><?= $totalTasks ?> tasks</span>
            </div>

            <h3 class="h6 mb-3">Add New Task</h3>
            <form action="tasks.php" method="post" class="row g-3 mb-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="project_id" value="<?= $projectId ?>">

                <div class="col-md-6">
                    <label for="taskTitle" class="form-label">Task title *</label>
                    <input id="taskTitle" type="text" name="title" class="form-control"
                           maxlength="200" required>
                </div>
                <div class="col-md-3">
                    <label for="taskStatus" class="form-label">Status</label>
                    <select id="taskStatus" name="status" class="form-select">
                        <?php foreach ($taskStatuses as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="taskPriority" class="form-label">Priority</label>
                    <select id="taskPriority" name="priority" class="form-select">
                        <?php foreach ($priorities as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $value === 'medium' ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="taskDueDate" class="form-label">Due date</label>
                    <input id="taskDueDate" type="date" name="due_date" class="form-control">
                </div>
                <div class="col-12">
                    <label for="taskDescription" class="form-label">Description</label>
                    <textarea id="taskDescription" name="description" class="form-control"
                              rows="2" maxlength="5000"></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Add Task</button>
                </div>
            </form>

            <hr class="my-4">
            <h3 class="h6 mb-3">Task List</h3>
            <?php if (!$tasks): ?>
                <p class="text-muted text-center py-4">
                    No tasks yet. Add your first task to get started.
                </p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th scope="col">Task</th>
                                <th scope="col">Priority</th>
                                <th scope="col">Status</th>
                                <th scope="col">Due Date</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td>
                                        <strong><?= e((string) $task['title']) ?></strong>
                                        <?php if ($task['description']): ?>
                                            <div class="small text-muted">
                                                <?= nl2br(e((string) $task['description'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $priorityColors[$task['priority']] ?>">
                                            <?= e($priorities[$task['priority']]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $statusColors[$task['status']] ?>">
                                            <?= e($taskStatuses[$task['status']]) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= $task['due_date'] !== null
                                            ? e((string) $task['due_date'])
                                            : 'No deadline' ?>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editTaskModal<?= (int) $task['id'] ?>">
                                                Edit
                                            </button>
                                            <form action="tasks.php" method="post" class="d-inline"
                                                  onsubmit="return confirm('Are you sure you want to delete this task?');">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="project_id" value="<?= $projectId ?>">
                                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
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
            <?php endif; ?>
        </div>
    </section>

    <?php foreach ($tasks as $task): ?>
        <div class="modal fade" id="editTaskModal<?= (int) $task['id'] ?>"
             tabindex="-1" aria-labelledby="editTaskTitle<?= (int) $task['id'] ?>"
             aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form action="tasks.php" method="post">
                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="editTaskTitle<?= (int) $task['id'] ?>">
                                Edit Task
                            </h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="project_id" value="<?= $projectId ?>">
                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">

                            <div class="mb-3">
                                <label for="editTitle<?= (int) $task['id'] ?>" class="form-label">
                                    Task title *
                                </label>
                                <input id="editTitle<?= (int) $task['id'] ?>" type="text"
                                       name="title" class="form-control" maxlength="200"
                                       value="<?= e((string) $task['title']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="editDescription<?= (int) $task['id'] ?>" class="form-label">
                                    Description
                                </label>
                                <textarea id="editDescription<?= (int) $task['id'] ?>"
                                          name="description" class="form-control" rows="3"
                                          maxlength="5000"><?= e((string) ($task['description'] ?? '')) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="editStatus<?= (int) $task['id'] ?>" class="form-label">Status</label>
                                <select id="editStatus<?= (int) $task['id'] ?>" name="status" class="form-select">
                                    <?php foreach ($taskStatuses as $value => $label): ?>
                                        <option value="<?= e($value) ?>"
                                            <?= $task['status'] === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="editPriority<?= (int) $task['id'] ?>" class="form-label">
                                    Priority
                                </label>
                                <select id="editPriority<?= (int) $task['id'] ?>" name="priority" class="form-select">
                                    <?php foreach ($priorities as $value => $label): ?>
                                        <option value="<?= e($value) ?>"
                                            <?= $task['priority'] === $value ? 'selected' : '' ?>>
                                            <?= e($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="editDueDate<?= (int) $task['id'] ?>" class="form-label">
                                    Due date
                                </label>
                                <input id="editDueDate<?= (int) $task['id'] ?>" type="date"
                                       name="due_date" class="form-control"
                                       value="<?= e((string) ($task['due_date'] ?? '')) ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary"
                                    data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
