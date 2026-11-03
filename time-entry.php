<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

function isAjaxRequest(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
}

function sendError(int $code, string $message): never
{
    http_response_code($code);

    if (isAjaxRequest()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }

    exit;
}

function positiveId(mixed $value): ?int
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }

    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return is_int($id) ? $id : null;
}

function parseLocalDateTime(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false ||
        (
            $errors !== false &&
            ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
        )
    ) {
        return null;
    }

    return $date->format('Y-m-d\TH:i') === $value ? $date : null;
}

final class TimeEntryValidationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode
    ) {
        parent::__construct($message);
    }
}

function failRequest(int $code, string $message): never
{
    throw new TimeEntryValidationException($message, $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendError(405, 'Method not allowed.');
}

if (!validCsrfToken()) {
    sendError(403, 'Invalid CSRF token.');
}

$action = $_POST['action'] ?? null;
$projectId = positiveId($_POST['project_id'] ?? null);
$userId = positiveId($_SESSION['user_id'] ?? null);

if (
    !is_string($action) ||
    !in_array($action, ['start', 'stop', 'manual'], true) ||
    $projectId === null ||
    $userId === null
) {
    sendError(400, 'Invalid request.');
}

$pdo = getDatabaseConnection();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT id FROM users WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$userId]);

    if (!$stmt->fetch()) {
        failRequest(403, 'User not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT p.id
         FROM projects p
         INNER JOIN clients c ON c.id = p.client_id
         WHERE p.id = ? AND c.user_id = ?'
    );
    $stmt->execute([$projectId, $userId]);

    if (!$stmt->fetch()) {
        failRequest(404, 'Project not found.');
    }

    if ($action === 'stop') {
        $entryId = positiveId($_POST['entry_id'] ?? null);

        if ($entryId === null) {
            failRequest(400, 'Invalid time entry ID.');
        }

        $stmt = $pdo->prepare(
            'UPDATE time_entries
             SET ended_at = NOW(),
                 duration_seconds = TIMESTAMPDIFF(
                     SECOND,
                     started_at,
                     NOW()
                 )
             WHERE id = ?
               AND project_id = ?
               AND ended_at IS NULL
               AND started_at <= NOW()'
        );
        $stmt->execute([$entryId, $projectId]);

        if ($stmt->rowCount() !== 1) {
            failRequest(404, 'Active time entry not found.');
        }
    } else {
        $taskId = null;

        if (isset($_POST['task_id']) && $_POST['task_id'] !== '') {
            $taskId = positiveId($_POST['task_id']);

            if ($taskId === null) {
                failRequest(400, 'Invalid task ID.');
            }

            $stmt = $pdo->prepare(
                'SELECT id
                 FROM tasks
                 WHERE id = ? AND project_id = ?'
            );
            $stmt->execute([$taskId, $projectId]);

            if (!$stmt->fetch()) {
                failRequest(
                    400,
                    'Task does not belong to this project.'
                );
            }
        }

        $description = $_POST['description'] ?? '';

        if (
            !is_string($description) ||
            mb_strlen($description, 'UTF-8') > 255
        ) {
            failRequest(
                400,
                'Description must contain at most 255 characters.'
            );
        }

        $description = trim($description);
        $description = $description === '' ? null : $description;

        $billable = $_POST['is_billable'] ?? '0';

        if (!in_array($billable, ['0', '1'], true)) {
            failRequest(400, 'Invalid billable value.');
        }

        if ($action === 'start') {
            $stmt = $pdo->prepare(
                'SELECT te.id
                 FROM time_entries te
                 INNER JOIN projects p ON p.id = te.project_id
                 INNER JOIN clients c ON c.id = p.client_id
                 WHERE c.user_id = ?
                   AND te.ended_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([$userId]);

            if ($stmt->fetch()) {
                failRequest(
                    409,
                    'Stop your active timer before starting another.'
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO time_entries
                 (
                     project_id,
                     task_id,
                     description,
                     started_at,
                     is_billable
                 )
                 VALUES (?, ?, ?, NOW(), ?)'
            );
            $stmt->execute([
                $projectId,
                $taskId,
                $description,
                (int) $billable
            ]);
        } else {
            $start = parseLocalDateTime(
                $_POST['started_at'] ?? null
            );
            $end = parseLocalDateTime(
                $_POST['ended_at'] ?? null
            );

            if ($start === null || $end === null) {
                failRequest(400, 'Invalid date or time.');
            }

            $duration = $end->getTimestamp() - $start->getTimestamp();

            if (
                $duration <= 0 ||
                $duration > 86400 ||
                $end > new DateTimeImmutable('now')
            ) {
                failRequest(
                    400,
                    'Time interval must be in the past and between 1 second and 24 hours.'
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO time_entries
                 (
                     project_id,
                     task_id,
                     description,
                     started_at,
                     ended_at,
                     duration_seconds,
                     is_billable
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $projectId,
                $taskId,
                $description,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $duration,
                (int) $billable
            ]);
        }
    }

    $pdo->commit();

} catch (TimeEntryValidationException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    sendError(
        $exception->statusCode,
        $exception->getMessage()
    );

} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($exception->getMessage());
    sendError(500, 'Could not save time entry.');
}

if (isAjaxRequest()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
}

header(
    'Location: time-tracking.php?id=' . $projectId,
    true,
    303
);
exit;
