<?php
/**
 * Role-aware dashboard.
 *
 * Admin: live counts plus a pending-approval callout.
 * Professor: workload for the linked professor profile.
 * Student: courses, attendance, and average grade for the linked profile.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_login();

$role = current_role();
$userId = current_user_id();

$stats = [];
$modules = [];
$notices = [];
$pendingUsers = [];

if ($role === 'Admin') {
    $stats = [
        [
            'label' => 'Active students',
            'value' => db_count($conn, "SELECT COUNT(*) FROM students WHERE status = 'Active'"),
            'icon' => 'fa-user-graduate',
            'href' => 'students.php',
        ],
        [
            'label' => 'Active professors',
            'value' => db_count($conn, "SELECT COUNT(*) FROM professors WHERE status = 'Active'"),
            'icon' => 'fa-chalkboard-user',
            'href' => 'professors.php',
        ],
        [
            'label' => 'Active courses',
            'value' => db_count($conn, "SELECT COUNT(*) FROM courses WHERE status = 'Active'"),
            'icon' => 'fa-book-open',
            'href' => 'courses.php',
        ],
        [
            'label' => 'Active schedules',
            'value' => db_count($conn, "SELECT COUNT(*) FROM schedules WHERE status = 'Active'"),
            'icon' => 'fa-calendar-days',
            'href' => 'schedules.php',
        ],
    ];

    $pendingCount = db_count($conn, "SELECT COUNT(*) FROM users WHERE status = 'Pending'");

    if ($pendingCount > 0) {
        $stmt = $conn->prepare(
            "SELECT fullname, username, role
             FROM users
             WHERE status = 'Pending'
             ORDER BY id ASC
             LIMIT 5"
        );
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pendingUsers[] = $row;
        }
        $stmt->close();

        $notices[] = [
            'type' => 'warning',
            'title' => $pendingCount . ' account' . ($pendingCount === 1 ? '' : 's') . ' awaiting approval',
            'body' => 'Self-registered professors cannot sign in until you approve them.',
            'list' => $pendingUsers,
            'href' => 'users.php?status=Pending',
            'action' => 'Review accounts',
        ];
    }

    // The four entities above already have stat cards, so Modules lists the
    // remaining tools instead of repeating them.
    $modules = [
        ['subjects.php', 'Subjects', 'Subject codes, titles, and units.', 'fa-book', 'Open subjects'],
        ['sections.php', 'Sections', 'Class sections, year levels, and advisers.', 'fa-layer-group', 'Open sections'],
        ['users.php', 'Users', 'Accounts, roles, and approvals.', 'fa-users-gear', 'Manage users'],
        ['grades.php', 'Grades', 'Student grade records.', 'fa-graduation-cap', 'Open grades'],
        ['attendance.php', 'Attendance', 'Class attendance records.', 'fa-clipboard-check', 'Open attendance'],
        ['reports.php', 'Reports', 'Printable academic summaries.', 'fa-chart-column', 'Open reports'],
    ];

    $pageActions = $pendingCount > 0
        ? '<a class="btn btn-primary btn-sm" href="users.php?status=Pending"><i class="fa-solid fa-user-check me-1" aria-hidden="true"></i>Review pending</a>'
        : '';
} elseif ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT professor_id
         FROM users
         WHERE id = ? AND role = 'Professor'
         LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $professorRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $professorId = (string) ($professorRow['professor_id'] ?? '');

    if ($professorId === '') {
        $notices[] = [
            'type' => 'danger',
            'title' => 'Your account is not linked to a professor profile',
            'body' => 'Ask an administrator to link your account before recording attendance or grades.',
        ];
    } else {
        $stats = [
            [
                'label' => 'Assigned courses',
                'value' => db_count(
                    $conn,
                    "SELECT COUNT(DISTINCT course_code) FROM schedules WHERE professor_id = ? AND status = 'Active'",
                    's',
                    [$professorId]
                ),
                'icon' => 'fa-book-open',
                'href' => 'schedules.php',
            ],
            [
                'label' => 'Sections handled',
                'value' => db_count(
                    $conn,
                    "SELECT COUNT(DISTINCT section) FROM schedules WHERE professor_id = ? AND status = 'Active'",
                    's',
                    [$professorId]
                ),
                'icon' => 'fa-people-group',
                'href' => 'attendance.php',
            ],
            [
                'label' => 'Attendance recorded',
                'value' => db_count(
                    $conn,
                    'SELECT COUNT(*) FROM attendance WHERE professor_id = ?',
                    's',
                    [$professorId]
                ),
                'icon' => 'fa-clipboard-check',
                'href' => 'attendance.php',
            ],
            [
                'label' => 'Active grades',
                'value' => db_count(
                    $conn,
                    "SELECT COUNT(*) FROM grades WHERE professor_id = ? AND status = 'Active'",
                    's',
                    [$professorId]
                ),
                'icon' => 'fa-graduation-cap',
                'href' => 'grades.php',
            ],
        ];
    }

    $modules = [
        ['attendance.php', 'Attendance', 'Record and review student attendance.', 'fa-clipboard-check', 'Open attendance'],
        ['grades.php', 'Grades', 'Record and update grades for your courses.', 'fa-graduation-cap', 'Open grades'],
        ['reports.php', 'Reports', 'View summaries for your classes.', 'fa-chart-column', 'Open reports'],
    ];

    $pageActions = '<a class="btn btn-outline-secondary btn-sm" href="profile.php"><i class="fa-solid fa-user me-1" aria-hidden="true"></i>My profile</a>';
} else {
    $stmt = $conn->prepare('SELECT student_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $studentId = (string) ($userRow['student_id'] ?? '');
    $section = '';

    if ($studentId === '') {
        $notices[] = [
            'type' => 'warning',
            'title' => 'Your account is not linked to a student record',
            'body' => 'Course, schedule, and grade information will appear once an administrator links your Student ID.',
        ];
    } else {
        $stmt = $conn->prepare('SELECT fullname, section, status FROM students WHERE student_id = ? LIMIT 1');
        $stmt->bind_param('s', $studentId);
        $stmt->execute();
        $studentRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($studentRow === null) {
            $notices[] = [
                'type' => 'warning',
                'title' => 'Student profile not found',
                'body' => 'Your Student ID does not match a record. Please contact the administrator.',
            ];
        } else {
            $section = trim((string) ($studentRow['section'] ?? ''));

            if (($studentRow['status'] ?? '') !== 'Active') {
                $notices[] = [
                    'type' => 'danger',
                    'title' => 'Your student record is inactive',
                    'body' => 'Contact the registrar if you believe this is a mistake.',
                ];
            }

            $attendanceTotal = db_count(
                $conn,
                'SELECT COUNT(*) FROM attendance WHERE student_id = ?',
                's',
                [$studentId]
            );
            $presentTotal = db_count(
                $conn,
                "SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'Present'",
                's',
                [$studentId]
            );
            $average = db_scalar(
                $conn,
                "SELECT AVG(grade) FROM grades WHERE student_id = ? AND status = 'Active'",
                's',
                [$studentId]
            );

            $stats = [
                [
                    'label' => 'Enrolled courses',
                    'value' => $section === ''
                        ? 0
                        : db_count(
                            $conn,
                            "SELECT COUNT(DISTINCT s.course_code)
                             FROM schedules s
                             INNER JOIN courses c ON c.course_code = s.course_code
                             WHERE s.status = 'Active'
                               AND c.status = 'Active'
                               AND s.section = ?",
                            's',
                            [$section]
                        ),
                    'icon' => 'fa-book-open',
                    'href' => 'my_courses.php',
                ],
                [
                    'label' => 'Attendance records',
                    'value' => $attendanceTotal,
                    'icon' => 'fa-clipboard-check',
                    'href' => 'student_portal.php',
                ],
                [
                    'label' => 'Days present',
                    'value' => $presentTotal,
                    'icon' => 'fa-calendar-check',
                    'href' => 'student_portal.php',
                ],
                [
                    'label' => 'Average grade',
                    'value' => $average === null ? '—' : number_format((float) $average, 2),
                    'icon' => 'fa-graduation-cap',
                    'href' => 'student_portal.php',
                ],
            ];
        }
    }

    $modules = [
        ['my_courses.php', 'My Courses', 'The courses attached to your section.', 'fa-book-open', 'Open my courses'],
        ['subject_schedule.php', 'Subject Schedule', 'Class times, rooms, and professors.', 'fa-calendar-days', 'Open schedule'],
        ['student_portal.php', 'Student Portal', 'Your profile, grades, and attendance.', 'fa-id-card', 'Open portal'],
    ];

    $pageActions = '<a class="btn btn-outline-secondary btn-sm" href="profile.php"><i class="fa-solid fa-user me-1" aria-hidden="true"></i>My profile</a>';
}

$moduleGridClass = count($modules) % 4 === 0 ? ' module-grid--quad' : '';

$pageTitle = 'Dashboard';
$pageSubtitle = 'Signed in as ' . $role;
$active = 'dashboard';
$bodyClass = 'app-page';
require __DIR__ . '/inc/header.php';
?>

<div class="welcome-panel">
    <div>
        <h2>Welcome back, <?= e(current_fullname()) ?></h2>
        <p>Here is what is happening in your account today.</p>
    </div>
    <div class="welcome-meta">
        <div>
            <span>Role</span>
            <strong><?= e($role) ?></strong>
        </div>
        <div>
            <span>Today</span>
            <strong><?= e(date('M j, Y')) ?></strong>
        </div>
    </div>
</div>

<?php foreach ($notices as $notice): ?>
    <div class="alert alert-<?= e($notice['type']) ?>" role="alert">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <strong><?= e($notice['title']) ?></strong>
                <p class="mb-0"><?= e($notice['body']) ?></p>
            </div>
            <?php if (!empty($notice['href'])): ?>
                <a class="btn btn-sm btn-<?= e($notice['type']) ?>" href="<?= e($notice['href']) ?>">
                    <?= e($notice['action'] ?? 'Open') ?>
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($notice['list'])): ?>
            <ul class="mb-0 mt-2">
                <?php foreach ($notice['list'] as $pending): ?>
                    <li>
                        <?= e($pending['fullname']) ?>
                        <span class="text-muted">(<?= e($pending['username']) ?> &middot; <?= e($pending['role']) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php if ($stats !== []): ?>
    <div class="stat-grid">
        <?php foreach ($stats as $stat): ?>
            <a class="stat-card" href="<?= e($stat['href']) ?>">
                <span class="stat-icon"><i class="fa-solid <?= e($stat['icon']) ?>" aria-hidden="true"></i></span>
                <span class="stat-value"><?= e((string) $stat['value']) ?></span>
                <span class="stat-label"><?= e($stat['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="h5 mb-3">Modules</h2>

<div class="module-grid<?= $moduleGridClass ?>">
    <?php foreach ($modules as [$href, $title, $description, $icon, $cta]): ?>
        <a class="module-card" href="<?= e($href) ?>">
            <span class="module-icon"><i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i></span>
            <h3><?= e($title) ?></h3>
            <p><?= e($description) ?></p>
            <span class="module-link"><?= e($cta) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
        </a>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
