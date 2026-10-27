<?php
/**
 * Courses attached to the signed-in student's section.
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

$courses = [];

if ($section !== '' && $studentStatus === 'Active') {
    $stmt = $conn->prepare(
        "SELECT c.course_code, c.course_name, c.department, c.units,
                GROUP_CONCAT(DISTINCT p.fullname ORDER BY p.fullname SEPARATOR ', ') AS professors
         FROM schedules s
         INNER JOIN courses c ON c.course_code = s.course_code
         LEFT JOIN professors p ON p.professor_id = s.professor_id
         WHERE s.status = 'Active'
           AND c.status = 'Active'
           AND s.section = ?
         GROUP BY c.course_code, c.course_name, c.department, c.units
         ORDER BY c.course_code"
    );
    $stmt->bind_param('s', $section);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    $stmt->close();
}

$pageTitle = 'My Courses';
$pageSubtitle = $section !== '' ? 'Courses for section ' . $section : 'Courses attached to your section';
$active = 'my_courses';
$pageActions = '<a class="btn btn-outline-secondary btn-sm" href="subject_schedule.php">'
    . '<i class="fa-solid fa-calendar-days me-1" aria-hidden="true"></i>My schedule</a>';
require __DIR__ . '/inc/header.php';
?>

<?php if ($studentId === ''): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Your account is not linked to a Student ID.</strong>
        Courses will appear once an administrator links your record.
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
                <?= count($courses) ?> course<?= count($courses) === 1 ? '' : 's' ?>
                for section <strong><?= e($section) ?></strong>.
            </p>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Course code</th>
                            <th scope="col">Course name</th>
                            <th scope="col">Department</th>
                            <th scope="col">Units</th>
                            <th scope="col">Professor(s)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($courses === []): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                                        <h5>No courses</h5>
                                        <p>No active courses are currently assigned to your section.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($courses as $course): ?>
                            <tr>
                                <td class="mono"><?= e($course['course_code']) ?></td>
                                <td><strong><?= e($course['course_name']) ?></strong></td>
                                <td>
                                    <?= ($course['department'] ?? '') !== ''
                                        ? e($course['department'])
                                        : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td><?= (int) $course['units'] ?></td>
                                <td>
                                    <?= ($course['professors'] ?? '') !== ''
                                        ? e($course['professors'])
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
