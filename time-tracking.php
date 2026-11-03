<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

function escapeHtml(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function formatDuration(int $seconds): string
{
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return sprintf(
        '%d:%02d:%02d',
        $hours,
        $minutes,
        $seconds % 60
    );
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

$pdo = getDatabaseConnection();
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT p.id, p.name, p.hourly_rate,
            c.name AS client_name
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
    'SELECT id, title
     FROM tasks
     WHERE project_id = ?
     ORDER BY title, id'
);
$stmt->execute([$projectId]);
$tasks = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT te.id, te.project_id, te.task_id,
            te.description, te.started_at,
            te.ended_at, te.duration_seconds,
            te.is_billable,
            t.title AS task_title
     FROM time_entries te
     LEFT JOIN tasks t ON t.id = te.task_id
     WHERE te.project_id = ?
     ORDER BY te.started_at DESC, te.id DESC
     LIMIT 100'
);
$stmt->execute([$projectId]);
$entries = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT te.id, te.project_id, te.started_at,
            p.name AS project_name
     FROM time_entries te
     INNER JOIN projects p ON p.id = te.project_id
     INNER JOIN clients c ON c.id = p.client_id
     WHERE c.user_id = ?
       AND te.ended_at IS NULL
     ORDER BY te.id DESC
     LIMIT 1'
);
$stmt->execute([$userId]);
$activeTimer = $stmt->fetch() ?: null;

$stmt = $pdo->prepare(
    'SELECT
        COALESCE(SUM(duration_seconds), 0) AS total_seconds,
        COALESCE(
            SUM(
                CASE
                    WHEN is_billable = 1
                    THEN duration_seconds
                    ELSE 0
                END
            ), 0
        ) AS billable_seconds
     FROM time_entries
     WHERE project_id = ?
       AND ended_at IS NOT NULL'
);
$stmt->execute([$projectId]);

$summary = $stmt->fetch();

$totalSeconds = (int) $summary['total_seconds'];
$billableSeconds = (int) $summary['billable_seconds'];

$hourlyRate = $project['hourly_rate'] === null
    ? null
    : (float) $project['hourly_rate'];

$estimatedAmount = $hourlyRate === null
    ? null
    : ($billableSeconds / 3600) * $hourlyRate;

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Time Tracking | ClientHub</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="dashboard.php">
            ClientHub
        </a>

        <a class="btn btn-outline-light btn-sm" href="projects.php">
            Projects
        </a>
    </div>
</nav>

<main class="container py-5">

    <a
        href="project.php?id=<?= $projectId ?>"
        class="text-decoration-none"
    >
        &larr; Back to Project
    </a>

    <h1 class="mt-3">Time Tracking</h1>

    <p class="text-muted">
        <?= escapeHtml($project['name']) ?>
        ·
        <?= escapeHtml($project['client_name']) ?>
    </p>

    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted">Total tracked</div>
                    <div class="fs-3 fw-semibold">
                        <?= escapeHtml(formatDuration($totalSeconds)) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted">Billable time</div>
                    <div class="fs-3 fw-semibold">
                        <?= escapeHtml(formatDuration($billableSeconds)) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted">
                        Estimated billable amount
                    </div>

                    <div class="fs-3 fw-semibold">
                        <?php if ($estimatedAmount === null): ?>
                            Rate not set
                        <?php else: ?>
                            <?= escapeHtml(
                                number_format($estimatedAmount, 2, '.', ',')
                            ) ?> RON
                        <?php endif; ?>
                    </div>

                    <small class="text-muted">
                        Based on billable time and the project hourly rate
                    </small>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4 mb-4">

        <div class="col-lg-6">
            <section class="card h-100">
                <div class="card-body">

                    <h2 class="h5">Live Timer</h2>

                    <div
                        class="alert alert-danger d-none form-error"
                        role="alert"
                        aria-live="polite"
                    ></div>

                    <?php if ($activeTimer !== null): ?>

                        <p class="mb-2">
                            Active timer:
                            <strong>
                                <?= escapeHtml(
                                    $activeTimer['project_name']
                                ) ?>
                            </strong>
                        </p>

                        <div
                            class="fs-3 font-monospace mb-3"
                            id="liveTimer"
                            data-started-at="<?= escapeHtml(
                                $activeTimer['started_at']
                            ) ?>"
                        >
                            Running...
                        </div>

                        <?php if (
                            (int) $activeTimer['project_id'] === $projectId
                        ): ?>

                            <form
                                method="post"
                                action="time-entry.php"
                                class="time-entry-form"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= escapeHtml($csrf) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="stop"
                                >

                                <input
                                    type="hidden"
                                    name="project_id"
                                    value="<?= $projectId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="entry_id"
                                    value="<?= (int) $activeTimer['id'] ?>"
                                >

                                <button
                                    class="btn btn-danger"
                                    type="submit"
                                >
                                    Stop Timer
                                </button>
                            </form>

                        <?php else: ?>

                            <p class="text-muted">
                                Stop the timer in that project before
                                starting another.
                            </p>

                            <a
                                class="btn btn-outline-primary"
                                href="time-tracking.php?id=<?= (int) $activeTimer['project_id'] ?>"
                            >
                                Open Active Project
                            </a>

                        <?php endif; ?>

                    <?php else: ?>

                        <form
                            method="post"
                            action="time-entry.php"
                            class="time-entry-form"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= escapeHtml($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="start"
                            >

                            <input
                                type="hidden"
                                name="project_id"
                                value="<?= $projectId ?>"
                            >

                            <div class="mb-3">
                                <label
                                    for="timerTask"
                                    class="form-label"
                                >
                                    Task (optional)
                                </label>

                                <select
                                    class="form-select"
                                    name="task_id"
                                    id="timerTask"
                                >
                                    <option value="">No task</option>

                                    <?php foreach ($tasks as $task): ?>
                                        <option value="<?= (int) $task['id'] ?>">
                                            <?= escapeHtml($task['title']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label
                                    for="timerDescription"
                                    class="form-label"
                                >
                                    Description
                                </label>

                                <input
                                    class="form-control"
                                    id="timerDescription"
                                    name="description"
                                    maxlength="255"
                                >
                            </div>

                            <div class="form-check mb-3">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="is_billable"
                                    value="1"
                                    id="timerBillable"
                                    checked
                                >

                                <label
                                    class="form-check-label"
                                    for="timerBillable"
                                >
                                    Billable
                                </label>
                            </div>

                            <button
                                class="btn btn-primary"
                                type="submit"
                            >
                                Start Timer
                            </button>
                        </form>

                    <?php endif; ?>

                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card h-100">
                <div class="card-body">

                    <h2 class="h5">Manual Time Entry</h2>

                    <div
                        class="alert alert-danger d-none form-error"
                        role="alert"
                        aria-live="polite"
                    ></div>

                    <form
                        method="post"
                        action="time-entry.php"
                        class="time-entry-form"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escapeHtml($csrf) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="manual"
                        >

                        <input
                            type="hidden"
                            name="project_id"
                            value="<?= $projectId ?>"
                        >

                        <div class="mb-3">
                            <label
                                for="manualTask"
                                class="form-label"
                            >
                                Task (optional)
                            </label>

                            <select
                                class="form-select"
                                name="task_id"
                                id="manualTask"
                            >
                                <option value="">No task</option>

                                <?php foreach ($tasks as $task): ?>
                                    <option value="<?= (int) $task['id'] ?>">
                                        <?= escapeHtml($task['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label
                                for="manualDescription"
                                class="form-label"
                            >
                                Description
                            </label>

                            <input
                                class="form-control"
                                id="manualDescription"
                                name="description"
                                maxlength="255"
                            >
                        </div>

                        <div class="row g-2 mb-3">

                            <div class="col-sm-6">
                                <label
                                    for="startedAt"
                                    class="form-label"
                                >
                                    Start
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="startedAt"
                                    name="started_at"
                                    required
                                >
                            </div>

                            <div class="col-sm-6">
                                <label
                                    for="endedAt"
                                    class="form-label"
                                >
                                    End
                                </label>

                                <input
                                    class="form-control"
                                    type="datetime-local"
                                    id="endedAt"
                                    name="ended_at"
                                    required
                                >
                            </div>

                        </div>

                        <div class="form-check mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="is_billable"
                                value="1"
                                id="manualBillable"
                                checked
                            >

                            <label
                                class="form-check-label"
                                for="manualBillable"
                            >
                                Billable
                            </label>
                        </div>

                        <button
                            class="btn btn-primary"
                            type="submit"
                        >
                            Add Time Entry
                        </button>
                    </form>

                </div>
            </section>
        </div>

    </div>

    <section class="card">
        <div class="card-body">

            <h2 class="h5">Time History</h2>

            <p class="text-muted small">
                Showing up to 100 most recent entries.
                Completed entries are included in the totals above.
            </p>

            <div class="table-responsive">
                <table class="table table-striped align-middle">

                    <thead>
                        <tr>
                            <th>Start</th>
                            <th>End</th>
                            <th>Task / Description</th>
                            <th>Duration</th>
                            <th>Billable</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td>
                                    <?= escapeHtml($entry['started_at']) ?>
                                </td>

                                <td>
                                    <?php if ($entry['ended_at'] === null): ?>
                                        Running
                                    <?php else: ?>
                                        <?= escapeHtml($entry['ended_at']) ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= escapeHtml(
                                        $entry['task_title'] ?? 'No task'
                                    ) ?>

                                    <?php if ($entry['description'] !== null): ?>
                                        <div class="text-muted small">
                                            <?= escapeHtml(
                                                $entry['description']
                                            ) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($entry['duration_seconds'] === null): ?>
                                        Running
                                    <?php else: ?>
                                        <?= escapeHtml(
                                            formatDuration(
                                                (int) $entry['duration_seconds']
                                            )
                                        ) ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= (int) $entry['is_billable'] === 1
                                        ? 'Yes'
                                        : 'No' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$entries): ?>
                            <tr>
                                <td
                                    colspan="5"
                                    class="text-center text-muted"
                                >
                                    No time entries yet.
                                </td>
                            </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>
    </section>

</main>

<script>
'use strict';

const forms = document.querySelectorAll('.time-entry-form');

forms.forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();

        const alertBox = form
            .closest('.card-body')
            .querySelector('.form-error');

        const button = form.querySelector('button[type="submit"]');

        alertBox.classList.add('d-none');
        alertBox.textContent = '';

        const action = form.querySelector('[name="action"]').value;

        if (action === 'manual') {
            const startValue = form.querySelector(
                '[name="started_at"]'
            ).value;

            const endValue = form.querySelector(
                '[name="ended_at"]'
            ).value;

            const start = new Date(startValue);
            const end = new Date(endValue);

            const duration = end.getTime() - start.getTime();

            if (
                !startValue ||
                !endValue ||
                !Number.isFinite(duration) ||
                duration <= 0 ||
                duration > 86400000 ||
                end.getTime() > Date.now()
            ) {
                alertBox.textContent =
                    'Please select a past time interval. ' +
                    'End must be after start, and duration ' +
                    'cannot exceed 24 hours.';

                alertBox.classList.remove('d-none');
                return;
            }
        }

        button.disabled = true;

        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            });

            const result = await response.json();

            if (!response.ok || result.success !== true) {
                throw new Error(
                    result.error || 'Could not save time entry.'
                );
            }

            window.location.reload();

        } catch (error) {
            alertBox.textContent = error instanceof Error
                ? error.message
                : 'Could not save time entry.';

            alertBox.classList.remove('d-none');
            button.disabled = false;
        }
    });
});

const timer = document.getElementById('liveTimer');

if (timer) {
    const startedAt = new Date(
        timer.dataset.startedAt.replace(' ', 'T')
    ).getTime();

    const renderTimer = () => {
        if (!Number.isFinite(startedAt)) {
            timer.textContent = 'Running';
            return;
        }

        const elapsed = Math.max(
            0,
            Math.floor((Date.now() - startedAt) / 1000)
        );

        const hours = Math.floor(elapsed / 3600);

        const minutes = String(
            Math.floor((elapsed % 3600) / 60)
        ).padStart(2, '0');

        const seconds = String(
            elapsed % 60
        ).padStart(2, '0');

        timer.textContent = `${hours}:${minutes}:${seconds}`;
    };

    renderTimer();
    setInterval(renderTimer, 1000);
}
</script>

</body>
</html>
