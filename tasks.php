<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (!validCsrfToken()) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$action = $_POST['action'] ?? null;

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    http_response_code(400);
    exit('Invalid action.');
}

$projectId = filter_var(
    $_POST['project_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($projectId)) {
    http_response_code(400);
    exit('Invalid project ID.');
}

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT p.id
     FROM projects p
     INNER JOIN clients c ON c.id = p.client_id
     WHERE p.id = ? AND c.user_id = ?'
);

$stmt->execute([$projectId, $userId]);

if (!$stmt->fetch()) {
    http_response_code(404);
    exit('Project not found.');
}

// Handle task deletion before validating create/update fields.
if ($action === 'delete') {
    $taskId = filter_var(
        $_POST['task_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($taskId)) {
        http_response_code(400);
        exit('Invalid task ID.');
    }

    $stmt = $pdo->prepare(
        'DELETE FROM tasks
         WHERE id = ? AND project_id = ?'
    );

    $stmt->execute([$taskId, $projectId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        exit('Task not found.');
    }

    header('Location: project.php?id=' . $projectId, true, 303);
    exit;
}

// Validate fields required for creating or updating a task.
$title = $_POST['title'] ?? null;
$description = $_POST['description'] ?? null;
$status = $_POST['status'] ?? null;
$priority = $_POST['priority'] ?? null;
$dueDate = $_POST['due_date'] ?? null;

$title = is_string($title) ? trim($title) : '';
$description = is_string($description) ? trim($description) : '';

$allowedStatuses = ['todo', 'in_progress', 'completed'];
$allowedPriorities = ['low', 'medium', 'high'];

if (
    $title === '' ||
    mb_strlen($title) > 200 ||
    mb_strlen($description) > 5000 ||
    !is_string($status) ||
    !in_array($status, $allowedStatuses, true) ||
    !is_string($priority) ||
    !in_array($priority, $allowedPriorities, true) ||
    !is_string($dueDate)
) {
    http_response_code(400);
    exit('Invalid task information.');
}

$dueDate = trim($dueDate);

if ($dueDate === '') {
    $dueDate = null;
} else {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);

    if ($date === false || $date->format('Y-m-d') !== $dueDate) {
        http_response_code(400);
        exit('Invalid due date.');
    }
}

// Update an existing task.
if ($action === 'update') {
    $taskId = filter_var(
        $_POST['task_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($taskId)) {
        http_response_code(400);
        exit('Invalid task ID.');
    }

    $stmt = $pdo->prepare(
        'UPDATE tasks
         SET title = ?,
             description = ?,
             status = ?,
             priority = ?,
             due_date = ?
         WHERE id = ? AND project_id = ?'
    );

    $stmt->execute([
        $title,
        $description,
        $status,
        $priority,
        $dueDate,
        $taskId,
        $projectId,
    ]);

    if ($stmt->rowCount() === 0) {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM tasks
             WHERE id = ? AND project_id = ?'
        );

        $stmt->execute([$taskId, $projectId]);

        if (!$stmt->fetch()) {
            http_response_code(404);
            exit('Task not found.');
        }
    }
} else {
    // Create a new task.
    $stmt = $pdo->prepare(
        'INSERT INTO tasks
            (project_id, title, description, status, priority, due_date)
         VALUES (?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $projectId,
        $title,
        $description,
        $status,
        $priority,
        $dueDate,
    ]);
}

header('Location: project.php?id=' . $projectId, true, 303);
exit;
