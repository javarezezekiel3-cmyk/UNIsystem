<?php
/**
 * Student portal.
 *
 * Shows the linked student profile plus that student's active grades and
 * attendance history. Accounts without a linked Student ID get a clear notice
 * instead of an error.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Student');

$userId = current_user_id();

$stmt = $conn->prepare('SELECT id, fullname, username, student_id FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$userRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

$studentId = (string) ($userRow['student_id'] ?? '');
$student = null;
$grades = [];
$attendance = [];
$average = null;
$gradeCount = 0;
$presentCount = 0;

if ($studentId !== '') {
    $stmt = $conn->prepare('SELECT * FROM students WHERE student_id = ? LIMIT 1');
    $stmt->bind_param('s', $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($student !== null) {
        $stmt = $conn->prepare(
            "SELECT g.*, c.course_name, p.fullname AS professor_name
             FROM grades g
             LEFT JOIN courses c ON c.course_code = g.course_code
             LEFT JOIN professors p ON p.professor_id = g.professor_id
             WHERE g.student_id = ? AND g.status = 'Active'
             ORDER BY g.school_year DESC, g.semester, g.id DESC"
        );
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $grades[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT a.*, c.course_name, p.fullname AS professor_name
             FROM attendance a
             LEFT JOIN courses c ON c.course_code = a.course_code
             LEFT JOIN professors p ON p.professor_id = a.professor_id
             WHERE a.student_id = ?
             ORDER BY a.attendance_date DESC, a.id DESC"
        );
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance[] = $row;
        }
        $stmt->close();

        $gradeCount = count($grades);
        $average = $gradeCount > 0
            ? array_sum(array_map(static fn (array $grade): float => (float) $grade['grade'], $grades)) / $gradeCount
            : null;
        $presentCount = count(array_filter(
            $attendance,
            static fn (array $record): bool => $record['status'] === 'Present'
        ));
    }
}

$pageTitle = 'Student Portal';
$pageSubtitle = 'Your profile, grades, and attendance';
$active = 'student_portal';
$pageActions = '<a class="btn btn-outline-secondary btn-sm" href="profile.php">'
    . '<i class="fa-solid fa-user me-1" aria-hidden="true"></i>My profile</a>';
require __DIR__ . '/inc/header.php';
?>

<?php if ($studentId === ''): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Your account is not linked to a Student ID.</strong>
        Your grades and attendance will appear once an administrator links your record.
    </div>
<?php elseif ($student === null): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Student profile not found.</strong>
        The Student ID on your account does not match a record. Please contact the administrator.
    </div>
<?php else: ?>

    <?php if (($student['status'] ?? '') !== 'Active'): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Your student record is inactive.</strong>
            Contact the registrar if you believe this is a mistake.
        </div>
    <?php endif; ?>

    <div class="stat-grid">
        <a class="stat-card" href="#grades">
            <span class="stat-icon"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span>
            <span class="stat-value"><?= $gradeCount ?></span>
            <span class="stat-label">Active grades</span>
        </a>
        <a class="stat-card" href="#grades">
            <span class="stat-icon"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
            <span class="stat-value"><?= $average === null ? '—' : e(number_format($average, 2)) ?></span>
            <span class="stat-label">Average grade</span>
        </a>
        <a class="stat-card" href="#attendance">
            <span class="stat-icon"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i></span>
            <span class="stat-value"><?= count($attendance) ?></span>
            <span class="stat-label">Attendance records</span>
        </a>
        <a class="stat-card" href="#attendance">
            <span class="stat-icon"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i></span>
            <span class="stat-value"><?= $presentCount ?></span>
            <span class="stat-label">Days present</span>
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-3">My profile</h2>

            <div class="row g-3">
                <?php
                $profile = [
                    'Student ID' => $student['student_id'],
                    'Full name' => $student['fullname'],
                    'Gender' => $student['gender'],
                    'Course' => $student['course'],
                    'Year level' => $student['year_level'],
                    'Section' => $student['section'],
                    'Email' => $student['email'],
                    'Contact number' => $student['contact_no'],
                    'Status' => $student['status'],
                ];
                ?>
                <?php foreach ($profile as $label => $value): ?>
                    <div class="col-sm-6 col-lg-4">
                        <span class="form-label d-block"><?= e($label) ?></span>
                        <?php if ($label === 'Status'): ?>
                            <span class="badge text-bg-<?= e(status_badge_class((string) $value)) ?>">
                                <?= e($value) ?>
                            </span>
                        <?php else: ?>
                            <p class="mb-0"><?= ($value ?? '') !== '' ? e($value) : '<span class="text-muted">—</span>' ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="grades">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h5 mb-0">My grades</h2>
                <a class="btn btn-sm btn-outline-secondary" href="my_courses.php">My courses</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Course</th>
                            <th scope="col">Professor</th>
                            <th scope="col">Grade</th>
                            <th scope="col">Semester</th>
                            <th scope="col">School year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($grades === []): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                        <h5>No grades yet</h5>
                                        <p>Grades appear here after your professors record them.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($grades as $grade): ?>
                            <?php
                            $value = (float) $grade['grade'];
                            $gradeClass = $value <= 3.00 ? 'success' : 'danger';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($grade['course_code']) ?></strong>
                                    <div class="text-muted small"><?= e($grade['course_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <?= e($grade['professor_name'] ?? '') !== '' ? e($grade['professor_name']) : '<span class="text-muted">—</span>' ?>
                                    <div class="text-muted small mono"><?= e($grade['professor_id']) ?></div>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= e($gradeClass) ?>">
                                        <?= e(number_format($value, 2)) ?>
                                    </span>
                                </td>
                                <td><?= e($grade['semester']) ?></td>
                                <td class="mono"><?= e($grade['school_year']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card" id="attendance">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <h2 class="h5 mb-0">My attendance</h2>
                <a class="btn btn-sm btn-outline-secondary" href="subject_schedule.php">My schedule</a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Course</th>
                            <th scope="col">Professor</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($attendance === []): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                                        <h5>No attendance records</h5>
                                        <p>Attendance appears here after your professors record it.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($attendance as $record): ?>
                            <tr>
                                <td class="nowrap"><?= e(format_date($record['attendance_date'])) ?></td>
                                <td>
                                    <strong><?= e($record['course_code']) ?></strong>
                                    <div class="text-muted small"><?= e($record['course_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <?= e($record['professor_name'] ?? '') !== '' ? e($record['professor_name']) : '<span class="text-muted">—</span>' ?>
                                    <div class="text-muted small mono"><?= e($record['professor_id']) ?></div>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= e(status_badge_class($record['status'])) ?>">
                                        <?= e($record['status']) ?>
                                    </span>
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
