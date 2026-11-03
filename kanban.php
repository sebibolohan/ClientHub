<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$projectId = filter_var(
    $_GET['id'] ?? null,
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
    'SELECT p.id, p.name, c.name AS client_name
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
    'SELECT id, title, description, status, priority, due_date
     FROM tasks
     WHERE project_id = ?
     ORDER BY position ASC, id ASC'
);
$stmt->execute([$projectId]);

$tasks = $stmt->fetchAll();

$columns = [
    'todo' => 'To Do',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];

$priorityColors = [
    'low' => 'secondary',
    'medium' => 'warning',
    'high' => 'danger',
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <meta name="project-id" content="<?= (int) $projectId ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Kanban Board | ClientHub</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        .kanban-column {
            background: #e9ecef;
            border-radius: 12px;
            min-height: 400px;
            padding: 16px;
            transition: background-color 0.15s ease;
        }

        .task-card {
            background: #fff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.06);
            overflow-wrap: anywhere;
            cursor: grab;
        }

        .task-card.dragging {
            opacity: 0.45;
            cursor: grabbing;
        }

        .task-card.saving {
            opacity: 0.6;
            pointer-events: none;
        }

        .kanban-column.drag-over {
            background: #dbeafe;
            outline: 2px dashed #2563eb;
            outline-offset: -2px;
        }

        .task-placeholder {
            height: 48px;
            border: 2px dashed #2563eb;
            border-radius: 8px;
            margin-bottom: 12px;
            background: #dbeafe;
        }

        .task-description {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
    </style>
</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a href="dashboard.php" class="navbar-brand fw-bold">
            ClientHub
        </a>

        <div class="d-flex gap-2">
            <a href="projects.php" class="btn btn-outline-light btn-sm">
                Projects
            </a>
            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                Dashboard
            </a>
        </div>
    </div>
</nav>

<main class="container py-5">

    <a
        href="project.php?id=<?= $projectId ?>"
        class="text-decoration-none"
    >
        &larr; Back to Project
    </a>

    <div class="mt-3 mb-4">
        <h1 class="fw-bold mb-2">
            <?= e((string) $project['name']) ?> – Kanban Board
        </h1>

        <p class="text-muted mb-0">
            Client: <?= e((string) $project['client_name']) ?>
        </p>
    </div>

    <div
        id="kanbanError"
        class="alert alert-danger d-none"
        role="alert"
        aria-live="polite"
    ></div>

    <div class="row g-4">

        <?php foreach ($columns as $status => $label): ?>
            <?php
            $columnTasks = array_filter(
                $tasks,
                static fn(array $task): bool => $task['status'] === $status
            );
            ?>

            <div class="col-lg-4">
                <section
                    class="kanban-column h-100"
                    data-status="<?= e($status) ?>"
                    aria-label="<?= e($label) ?>"
                >

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">
                            <?= e($label) ?>
                        </h2>

                        <span class="badge bg-dark task-count">
                            <?= count($columnTasks) ?>
                        </span>
                    </div>

                    <p
                        class="text-muted small empty-column-message"
                        <?= $columnTasks ? 'hidden' : '' ?>
                    >
                        No tasks in this column.
                    </p>

                    <?php foreach ($columnTasks as $task): ?>
                        <article
                            class="task-card"
                            draggable="true"
                            data-task-id="<?= (int) $task['id'] ?>"
                        >
                            <div class="d-flex justify-content-between gap-2 mb-2">
                                <h3 class="h6 mb-0">
                                    <?= e((string) $task['title']) ?>
                                </h3>

                                <span class="badge bg-<?= $priorityColors[$task['priority']] ?>">
                                    <?= e(ucfirst($task['priority'])) ?>
                                </span>
                            </div>

                            <?php if ($task['description']): ?>
                                <p class="text-muted small task-description mb-2"><?= e((string) $task['description']) ?></p>
                            <?php endif; ?>

                            <?php if ($task['due_date'] !== null): ?>
                                <p class="small mb-0">
                                    Due: <?= e((string) $task['due_date']) ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>

                </section>
            </div>
        <?php endforeach; ?>

    </div>

</main>

<script>
'use strict';

const columns = [...document.querySelectorAll('.kanban-column')];
const cards = [...document.querySelectorAll('.task-card')];
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const projectId = document.querySelector('meta[name="project-id"]').content;
const errorBox = document.getElementById('kanbanError');
const placeholder = document.createElement('div');
placeholder.className = 'task-placeholder';
placeholder.setAttribute('aria-hidden', 'true');

let draggedCard = null;
let saving = false;

function showError(message) {
    errorBox.textContent = message;
    errorBox.classList.remove('d-none');
}

function clearError() {
    errorBox.textContent = '';
    errorBox.classList.add('d-none');
}

function updateColumnCounts() {
    for (const column of columns) {
        const count = column.querySelectorAll('.task-card').length;
        column.querySelector('.task-count').textContent = count;
        column.querySelector('.empty-column-message').hidden = count !== 0;
    }
}

function clearHighlights() {
    for (const column of columns) {
        column.classList.remove('drag-over');
    }
}

function boardOrder() {
    const order = {};
    for (const column of columns) {
        order[column.dataset.status] = [...column.querySelectorAll('.task-card')]
            .map(card => Number(card.dataset.taskId));
    }
    return order;
}

function insertionPoint(column, mouseY) {
    const candidates = [...column.querySelectorAll('.task-card:not(.dragging)')];
    for (const card of candidates) {
        const bounds = card.getBoundingClientRect();
        if (mouseY < bounds.top + bounds.height / 2) {
            return card;
        }
    }
    return null;
}

for (const card of cards) {
    card.addEventListener('dragstart', event => {
        if (saving || !event.dataTransfer) {
            event.preventDefault();
            return;
        }

        clearError();
        draggedCard = card;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.taskId);
        card.classList.add('dragging');
    });

    card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        placeholder.remove();
        clearHighlights();
        draggedCard = null;
    });
}

for (const column of columns) {
    column.addEventListener('dragover', event => {
        if (!draggedCard || saving) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

        clearHighlights();
        column.classList.add('drag-over');
        const before = insertionPoint(column, event.clientY);
        column.insertBefore(placeholder, before);
    });

    column.addEventListener('drop', async event => {
        if (!draggedCard || saving) return;
        event.preventDefault();
        clearHighlights();

        const card = draggedCard;
        const originalParent = card.parentElement;
        const originalNext = card.nextSibling;
        const target = placeholder.parentElement === column ? placeholder : null;
        if (!target) return;

        column.insertBefore(card, target);
        placeholder.remove();
        card.classList.remove('dragging');
        draggedCard = null;
        updateColumnCounts();

        const order = boardOrder();
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('project_id', projectId);
        formData.append('order', JSON.stringify(order));

        saving = true;
        card.classList.add('saving');
        try {
            const response = await fetch('kanban-update.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                throw new Error(result.error || 'Failed to save task order.');
            }
        } catch (error) {
            originalParent.insertBefore(card, originalNext);
            updateColumnCounts();
            showError(error instanceof Error ? error.message : 'Failed to save task order.');
        } finally {
            card.classList.remove('saving');
            saving = false;
        }
    });
}
</script>

</body>
</html>
