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

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM clients WHERE user_id = ?'
);
$stmt->execute([$userId]);
$totalClients = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total,
        COALESCE(SUM(p.status = "planned"), 0) AS planned,
        COALESCE(SUM(p.status = "in_progress"), 0) AS in_progress,
        COALESCE(SUM(p.status = "completed"), 0) AS completed
     FROM projects p
     INNER JOIN clients c ON c.id = p.client_id
     WHERE c.user_id = ?'
);
$stmt->execute([$userId]);
$stats = $stmt->fetch();

$totalProjects = (int) $stats['total'];
$planned = (int) $stats['planned'];
$inProgress = (int) $stats['in_progress'];
$completed = (int) $stats['completed'];

$stmt = $pdo->prepare(
    'SELECT
        p.id,
        p.name,
        p.status,
        p.created_at,
        c.name AS client_name
     FROM projects p
     INNER JOIN clients c ON c.id = p.client_id
     WHERE c.user_id = ?
     ORDER BY p.created_at DESC, p.id DESC
     LIMIT 5'
);
$stmt->execute([$userId]);
$recentProjects = $stmt->fetchAll();

$statusLabels = [
    'planned' => 'Planned',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
];

$statusColors = [
    'planned' => 'secondary',
    'in_progress' => 'primary',
    'completed' => 'success',
];

$statusCounts = [
    'planned' => $planned,
    'in_progress' => $inProgress,
    'completed' => $completed,
];

$userName = (string) ($_SESSION['user_name'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | ClientHub</title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <style>
        body {
            background: #f5f7fb;
            color: #1e293b;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .stat-card {
            border: 1px solid #e9edf3;
            border-radius: 14px;
            transition: transform 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }

        .section-card {
            border: 1px solid #e9edf3;
            border-radius: 14px;
        }

        .progress {
            height: 10px;
            border-radius: 10px;
        }

        .table td {
            padding-top: 15px;
            padding-bottom: 15px;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand text-primary" href="dashboard.php">
            ClientHub
        </a>

        <div class="d-flex align-items-center gap-3">
            <span class="text-muted small d-none d-sm-inline">
                <?= e($userName) ?>
            </span>

            <form method="post" action="logout.php" class="m-0">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >
                <button class="btn btn-outline-danger btn-sm">
                    Logout
                </button>
            </form>
        </div>
    </div>
</nav>

<main class="container py-5">

    <div class="mb-4">
        <h1 class="fw-bold mb-1">Dashboard</h1>
        <p class="text-muted">
            Welcome back, <?= e($userName) ?>!
            Here's an overview of your business.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="clients.php" class="btn btn-primary">
            Manage Clients
        </a>

        <a href="projects.php" class="btn btn-outline-primary">
            Manage Projects
        </a>
    </div>

    <div class="row g-4 mb-4">

        <div class="col-md-6 col-xl-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-2">Total Clients</p>
                    <div class="stat-value"><?= $totalClients ?></div>
                    <span class="text-primary small">
                        Registered clients
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-2">Total Projects</p>
                    <div class="stat-value"><?= $totalProjects ?></div>
                    <span class="text-primary small">
                        All client projects
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-2">In Progress</p>
                    <div class="stat-value text-primary">
                        <?= $inProgress ?>
                    </div>
                    <span class="text-muted small">
                        Active projects
                    </span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-xl-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-4">
                    <p class="text-muted mb-2">Completed</p>
                    <div class="stat-value text-success">
                        <?= $completed ?>
                    </div>
                    <span class="text-muted small">
                        Finished projects
                    </span>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card section-card shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold mb-4">
                        Project Status Overview
                    </h2>

                    <?php foreach ($statusCounts as $status => $count): ?>
                        <?php
                        $percentage = $totalProjects > 0
                            ? ($count / $totalProjects) * 100
                            : 0;
                        ?>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>
                                    <?= e($statusLabels[$status]) ?>
                                </span>
                                <span class="fw-semibold">
                                    <?= $count ?>
                                    (<?= round($percentage) ?>%)
                                </span>
                            </div>

                            <div
                                class="progress"
                                role="progressbar"
                                aria-label="<?= e($statusLabels[$status]) ?>"
                                aria-valuenow="<?= round($percentage) ?>"
                                aria-valuemin="0"
                                aria-valuemax="100"
                            >
                                <div
                                    class="progress-bar bg-<?= e($statusColors[$status]) ?>"
                                    style="width: <?= $percentage ?>%"
                                ></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($totalProjects === 0): ?>
                        <p class="text-muted small">
                            No project data available yet.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card section-card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2 class="h5 fw-bold mb-0">
                            Recent Projects
                        </h2>

                        <a href="projects.php" class="small text-decoration-none">
                            View all
                        </a>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Client</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentProjects as $project): ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <?= e((string) $project['name']) ?>
                                        </td>
                                        <td>
                                            <?= e((string) $project['client_name']) ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= e($statusColors[$project['status']]) ?>">
                                                <?= e($statusLabels[$project['status']]) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!$recentProjects): ?>
                        <p class="text-muted text-center py-3">
                            No projects yet.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

</main>

</body>
</html>
