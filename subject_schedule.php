<?php
/**
 * Active class schedule for the signed-in student's section.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Student');

$userId = current_user_id();

$stmt = $conn->prepare('SELECT student_id FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$studentId = (string) ($stmt->get_result()->fetch_assoc()['student_id'] ?? '');
$stmt->close();

$section = '';
$studentStatus = '';

if ($studentId !== '') {
    $stmt = $conn->prepare('SELECT section, status FROM students WHERE student_id = ? LIMIT 1');
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row !== null) {
        $section = trim((string) ($row['section'] ?? ''));
        $studentStatus = (string) ($row['status'] ?? '');
    }
}

$schedules = [];

if ($section !== '' && $studentStatus === 'Active') {
    $stmt = $conn->prepare(
        "SELECT s.course_code, c.course_name, s.professor_id, p.fullname AS professor_name,
                s.section, s.room, s.day, s.start_time, s.end_time
         FROM schedules s
         LEFT JOIN courses c ON c.course_code = s.course_code
         LEFT JOIN professors p ON p.professor_id = s.professor_id
         WHERE s.status = 'Active' AND s.section = ?
         ORDER BY FIELD(s.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), s.start_time"
    );
    $stmt->bind_param('s', $section);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $schedules[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'Subject Schedule';
$pageSubtitle = $section !== '' ? 'Active classes for section ' . $section : 'Your section timetable';
$active = 'subject_schedule';
$pageActions = '<a class="btn btn-outline-secondary btn-sm" href="my_courses.php">'
    . '<i class="fa-solid fa-book-open me-1" aria-hidden="true"></i>My courses</a>';
require __DIR__ . '/inc/header.php';
?>

<?php if ($studentId === ''): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Your account is not linked to a Student ID.</strong>
        Your schedule will appear once an administrator links your record.
    </div>
<?php elseif ($section === ''): ?>
    <div class="alert alert-warning" role="alert">
        <strong>No section is assigned to your student record.</strong>
        Ask the registrar to assign your section.
    </div>
<?php elseif ($studentStatus !== 'Active'): ?>
    <div class="alert alert-danger" role="alert">
        <strong>Your student record is inactive.</strong>
        Contact the registrar if you believe this is a mistake.
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <p class="text-muted">
                Section <strong><?= e($section) ?></strong>
                &middot;
                <?= count($schedules) ?> active class<?= count($schedules) === 1 ? '' : 'es' ?>
            </p>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Day</th>
                            <th scope="col">Time</th>
                            <th scope="col">Course</th>
                            <th scope="col">Professor</th>
                            <th scope="col">Room</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($schedules === []): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                        <h5>No schedule</h5>
                                        <p>No active classes are scheduled for your section yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <span class="badge text-bg-light border"><?= e($schedule['day']) ?></span>
                                </td>
                                <td class="nowrap">
                                    <?= e(format_time($schedule['start_time'])) ?>
                                    &ndash;
                                    <?= e(format_time($schedule['end_time'])) ?>
                                </td>
                                <td>
                                    <strong><?= e($schedule['course_code']) ?></strong>
                                    <div class="text-muted small"><?= e($schedule['course_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <?= ($schedule['professor_name'] ?? '') !== ''
                                        ? e($schedule['professor_name'])
                                        : '<span class="text-muted">—</span>' ?>
                                    <div class="text-muted small mono"><?= e($schedule['professor_id']) ?></div>
                                </td>
                                <td>
                                    <?= ($schedule['room'] ?? '') !== ''
                                        ? e($schedule['room'])
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
