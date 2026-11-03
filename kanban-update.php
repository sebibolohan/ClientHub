<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Method not allowed.']);
}

requireLogin();

if (!validCsrfToken()) {
    respond(403, ['error' => 'Invalid CSRF token.']);
}

$projectId = filter_var(
    $_POST['project_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$rawOrder = $_POST['order'] ?? null;
$order = is_string($rawOrder) ? json_decode($rawOrder, true) : null;
$statuses = ['todo', 'in_progress', 'completed'];

if (!is_int($projectId) || !is_array($order) || array_keys($order) !== $statuses) {
    respond(400, ['error' => 'Invalid board information.']);
}

$requestedIds = [];
foreach ($statuses as $status) {
    if (!is_array($order[$status]) || !array_is_list($order[$status])) {
        respond(400, ['error' => 'Invalid task order.']);
    }

    foreach ($order[$status] as $taskId) {
        if (!is_int($taskId) || $taskId < 1) {
            respond(400, ['error' => 'Invalid task ID.']);
        }
        $requestedIds[] = $taskId;
    }
}

if (count($requestedIds) !== count(array_unique($requestedIds))) {
    respond(400, ['error' => 'Duplicate task IDs.']);
}

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT p.id
         FROM projects p
         INNER JOIN clients c ON c.id = p.client_id
         WHERE p.id = ? AND c.user_id = ?
         FOR UPDATE'
    );
    $stmt->execute([$projectId, $userId]);

    if (!$stmt->fetch()) {
        $pdo->rollBack();
        respond(404, ['error' => 'Project not found.']);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM tasks WHERE project_id = ? ORDER BY id FOR UPDATE'
    );
    $stmt->execute([$projectId]);
    $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $sortedRequested = $requestedIds;
    sort($sortedRequested, SORT_NUMERIC);

    if ($sortedRequested !== $existingIds) {
        $pdo->rollBack();
        respond(409, ['error' => 'Board changed. Refresh the page and try again.']);
    }

    $update = $pdo->prepare(
        'UPDATE tasks SET status = ?, position = ? WHERE id = ? AND project_id = ?'
    );

    foreach ($statuses as $status) {
        foreach ($order[$status] as $position => $taskId) {
            $update->execute([$status, $position, $taskId, $projectId]);
        }
    }

    $pdo->commit();
    respond(200, ['success' => true]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Kanban update failed: ' . $error->getMessage());
    respond(500, ['error' => 'Unable to save the board.']);
}
