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

$action = $_POST['action'] ?? 'create';

if (!is_string($action) || !in_array($action, ['create', 'delete'], true)) {
    http_response_code(400);
    exit('Invalid payment action.');
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

if ($action === 'delete') {
    $paymentId = filter_var(
        $_POST['payment_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (!is_int($paymentId)) {
        http_response_code(400);
        exit('Invalid payment ID.');
    }

    $stmt = $pdo->prepare(
        'DELETE FROM payments
         WHERE id = ? AND project_id = ?'
    );
    $stmt->execute([$paymentId, $projectId]);

    if ($stmt->rowCount() !== 1) {
        http_response_code(404);
        exit('Payment not found.');
    }
} else {
    $amount = $_POST['amount'] ?? null;
    $paidAt = $_POST['paid_at'] ?? null;
    $notes = $_POST['notes'] ?? '';

    if (
        !is_string($amount) ||
        !preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $amount) ||
        (float) $amount <= 0
    ) {
        http_response_code(400);
        exit('Invalid payment amount.');
    }

    if (!is_string($paidAt)) {
        http_response_code(400);
        exit('Invalid payment date.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt);
    $dateErrors = DateTimeImmutable::getLastErrors();

    if (
        $date === false ||
        ($dateErrors !== false &&
            ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
        $date->format('Y-m-d') !== $paidAt ||
        $date > new DateTimeImmutable('today')
    ) {
        http_response_code(400);
        exit('Invalid payment date.');
    }

    if (!is_string($notes) || mb_strlen($notes, 'UTF-8') > 255) {
        http_response_code(400);
        exit('Notes must contain at most 255 characters.');
    }

    $notes = trim($notes);
    $notes = $notes === '' ? null : $notes;

    $stmt = $pdo->prepare(
        'INSERT INTO payments (project_id, amount, paid_at, notes)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$projectId, $amount, $paidAt, $notes]);
}

header('Location: project.php?id=' . $projectId, true, 303);
exit;
