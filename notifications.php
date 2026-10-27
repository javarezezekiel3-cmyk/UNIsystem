<?php
/**
 * Notifications for the signed-in account.
 *
 * Reading a notification changes state, so both "mark read" actions are POST
 * requests protected by a CSRF token. A user can only ever touch their own
 * notifications; ownership is part of every UPDATE.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_login();

$userId = current_user_id();

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'mark_read') {
        $id = post_int('id');

        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->bind_param('ii', $id, $userId);
            $stmt->execute();
            $stmt->close();
        }

        flash('success', 'Notification marked as read.');
        redirect('notifications.php');
    }

    if ($action === 'mark_all_read') {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();

        flash('success', $changed > 0
            ? $changed . ' notification' . ($changed === 1 ? '' : 's') . ' marked as read.'
            : 'You have no unread notifications.');
        redirect('notifications.php');
    }

    flash('danger', 'Unknown action.');
    redirect('notifications.php');
}

// ---------------------------------------------------------------------------
// Filters, counts, and page
// ---------------------------------------------------------------------------

$filter = query_string('filter', 'all');
if (!in_list($filter, ['all', 'unread'])) {
    $filter = 'all';
}

$page = max(1, (int) query_string('page', '1'));
$perPage = 20;

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total, SUM(is_read = 0) AS unread
     FROM notifications
     WHERE user_id = ?"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total = (int) ($counts['total'] ?? 0);
$unread = (int) ($counts['unread'] ?? 0);
$matching = $filter === 'unread' ? $unread : $total;
$pageCount = max(1, (int) ceil($matching / $perPage));

if ($page > $pageCount) {
    $page = $pageCount;
}
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id, title, message, is_read, created_at
        FROM notifications
        WHERE user_id = ?';

if ($filter === 'unread') {
    $sql .= ' AND is_read = 0';
}

$sql .= ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';

$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $userId, $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

/** Format a stored timestamp for display. */
function notification_when(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false ? '—' : date('M j, Y g:i A', $timestamp);
}

$pageTitle = 'Notifications';
$pageSubtitle = $unread > 0
    ? $unread . ' unread notification' . ($unread === 1 ? '' : 's')
    : 'You are all caught up';
$active = 'notifications';
$pageActions = $unread > 0
    ? '<form method="post" class="d-inline">'
        . csrf_field()
        . '<input type="hidden" name="action" value="mark_all_read">'
        . '<button type="submit" class="btn btn-primary btn-sm">'
        . '<i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>Mark all as read</button>'
        . '</form>'
    : '';
require __DIR__ . '/inc/header.php';
?>

<div class="toolbar">
    <div class="report-tabs mb-0">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="notifications.php?filter=all">
            All <span class="badge text-bg-light ms-1"><?= $total ?></span>
        </a>
        <a class="btn btn-sm <?= $filter === 'unread' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="notifications.php?filter=unread">
            Unread <span class="badge text-bg-light ms-1"><?= $unread ?></span>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if ($notifications === []): ?>
            <div class="empty-state">
                <i class="fa-solid fa-bell-slash" aria-hidden="true"></i>
                <h5><?= $filter === 'unread' ? 'No unread notifications' : 'No notifications yet' ?></h5>
                <p><?= $filter === 'unread'
                    ? 'Everything has been read.'
                    : 'Activity that concerns you will appear here.' ?></p>
            </div>
        <?php else: ?>
            <ul class="notification-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php $isUnread = (int) $notification['is_read'] === 0; ?>
                    <li class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
                        <span class="notification-dot" aria-hidden="true"></span>

                        <div class="notification-body">
                            <div class="notification-title">
                                <?= e($notification['title']) ?>
                                <?php if ($isUnread): ?>
                                    <span class="badge text-bg-primary">New</span>
                                <?php endif; ?>
                            </div>

                            <?php if (trim((string) $notification['message']) !== ''): ?>
                                <p class="notification-message"><?= e($notification['message']) ?></p>
                            <?php endif; ?>

                            <time class="notification-date" datetime="<?= e($notification['created_at']) ?>">
                                <?= e(notification_when($notification['created_at'])) ?>
                            </time>
                        </div>

                        <?php if ($isUnread): ?>
                            <form method="post" class="notification-action">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    Mark read
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge text-bg-light border align-self-start">Read</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($pageCount > 1): ?>
    <nav class="mt-3" aria-label="Notification pages">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="notifications.php?filter=<?= e($filter) ?>&page=<?= $page - 1 ?>">Previous</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link">Page <?= $page ?> of <?= $pageCount ?></span>
            </li>
            <li class="page-item<?= $page >= $pageCount ? ' disabled' : '' ?>">
                <a class="page-link" href="notifications.php?filter=<?= e($filter) ?>&page=<?= $page + 1 ?>">Next</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
