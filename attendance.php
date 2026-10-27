<?php
/**
 * Class attendance (Admin and Professor).
 *
 * Professors can only see and change attendance they recorded, for courses
 * that are currently assigned to them. Administrators can manage everything.
 * A student can only have one record per course per day.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role(['Admin', 'Professor']);

$role = current_role();
$userId = current_user_id();
$statusOptions = ['Present', 'Absent', 'Late'];

$professorId = null;

if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT professor_id FROM users WHERE id = ? AND role = 'Professor' LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $professorId = (string) ($stmt->get_result()->fetch_assoc()['professor_id'] ?? '');
    $stmt->close();

    if ($professorId === '') {
        abort(403, 'Your account is not linked to a professor profile. Ask an administrator to link it.');
    }
}

/** Fetch one attendance row by primary key. */
function find_attendance(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM attendance WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Is the student allowed to be marked for this course by this professor?
 * Administrators may mark any active student.
 */
function student_allowed_for_course(
    mysqli $conn,
    string $studentId,
    string $courseCode,
    string $professorId,
    bool $isAdmin
): bool {
    if ($isAdmin) {
        return active_student_exists($conn, $studentId);
    }

    if (!professor_teaches_course($conn, $professorId, $courseCode)) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM students st
         WHERE st.student_id = ? AND st.status = 'Active'
           AND EXISTS (
               SELECT 1 FROM schedules s
               WHERE s.course_code = ?
                 AND s.professor_id = ?
                 AND s.status = 'Active'
                 AND s.section = st.section
           )
         LIMIT 1"
    );
    $stmt->bind_param('sss', $studentId, $courseCode, $professorId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

/** Does another record already exist for this student, course, and day? */
function attendance_duplicate_exists(
    mysqli $conn,
    string $studentId,
    string $courseCode,
    string $date,
    int $excludeId = 0
): bool {
    $sql = 'SELECT id FROM attendance
            WHERE student_id = ? AND course_code = ? AND attendance_date = ?';
    $types = 'sss';
    $params = [$studentId, $courseCode, $date];

    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $types .= 'i';
        $params[] = $excludeId;
    }

    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_attendance') {
        $studentId = post_string('student_id');
        $courseCode = post_string('course_code');
        $date = post_string('attendance_date');
        $status = post_string('status');
        $recordedBy = $role === 'Professor' ? $professorId : post_string('professor_id');

        $errors = [];

        if (!valid_date($date)) {
            $errors[] = 'Choose a valid attendance date.';
        }
        if (!in_list($status, $statusOptions)) {
            $errors[] = 'Choose a valid attendance status.';
        }
        if (!active_course_exists($conn, $courseCode)) {
            $errors[] = 'Choose an active course.';
        }
        if (!active_professor_exists($conn, $recordedBy)) {
            $errors[] = 'Choose an active professor.';
        }
        if ($role === 'Professor' && !professor_teaches_course($conn, $professorId, $courseCode)) {
            $errors[] = 'You can only record attendance for courses assigned to you.';
        }
        if ($errors === [] && !student_allowed_for_course($conn, $studentId, $courseCode, $recordedBy, $role === 'Admin')) {
            $errors[] = $role === 'Admin'
                ? 'Choose an active student.'
                : 'That student is not in a section you teach for this course.';
        }
        if ($errors === [] && attendance_duplicate_exists($conn, $studentId, $courseCode, $date)) {
            $errors[] = 'Attendance for that student and course already exists on ' . format_date($date) . '.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('attendance.php');
        }

        try {
            $stmt = $conn->prepare(
                'INSERT INTO attendance (student_id, course_code, professor_id, attendance_date, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssss', $studentId, $courseCode, $recordedBy, $date, $status);
            $stmt->execute();
            $stmt->close();

            flash('success', 'Attendance recorded.');
        } catch (Throwable $e) {
            error_log('Add attendance failed: ' . $e->getMessage());
            flash('danger', 'Unable to record attendance. Please try again.');
        }

        redirect('attendance.php');
    }

    if ($action === 'update_attendance') {
        $id = post_int('id');
        $record = find_attendance($conn, $id);

        if ($record === null) {
            flash('danger', 'Attendance record not found.');
            redirect('attendance.php');
        }

        if ($role === 'Professor' && $record['professor_id'] !== $professorId) {
            abort(403, 'You can only edit your own attendance records.');
        }

        $studentId = post_string('student_id');
        $courseCode = post_string('course_code');
        $date = post_string('attendance_date');
        $status = post_string('status');
        $recordedBy = $role === 'Professor' ? $professorId : post_string('professor_id');

        $errors = [];

        if (!valid_date($date)) {
            $errors[] = 'Choose a valid attendance date.';
        }
        if (!in_list($status, $statusOptions)) {
            $errors[] = 'Choose a valid attendance status.';
        }

        $courseUnchanged = $courseCode === $record['course_code'];
        if (!active_course_exists($conn, $courseCode) && !($courseUnchanged && course_exists($conn, $courseCode))) {
            $errors[] = 'Choose an active course.';
        }

        $professorUnchanged = $recordedBy === $record['professor_id'];
        if (!active_professor_exists($conn, $recordedBy) && !($professorUnchanged && professor_exists($conn, $recordedBy))) {
            $errors[] = 'Choose an active professor.';
        }

        if ($role === 'Professor' && !professor_teaches_course($conn, $professorId, $courseCode)) {
            $errors[] = 'You can only manage attendance for courses assigned to you.';
        }
        if ($errors === [] && !student_allowed_for_course($conn, $studentId, $courseCode, $recordedBy, $role === 'Admin')) {
            $errors[] = $role === 'Admin'
                ? 'Choose an active student.'
                : 'That student is not in a section you teach for this course.';
        }
        if ($errors === [] && attendance_duplicate_exists($conn, $studentId, $courseCode, $date, $id)) {
            $errors[] = 'Another attendance record already exists for that student and course on ' . format_date($date) . '.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('attendance.php');
        }

        try {
            $stmt = $conn->prepare(
                'UPDATE attendance
                 SET student_id = ?, course_code = ?, professor_id = ?, attendance_date = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->bind_param('sssssi', $studentId, $courseCode, $recordedBy, $date, $status, $id);
            $stmt->execute();
            $stmt->close();

            flash('success', 'Attendance updated.');
        } catch (Throwable $e) {
            error_log('Update attendance failed: ' . $e->getMessage());
            flash('danger', 'Unable to update attendance. Please try again.');
        }

        redirect('attendance.php');
    }

    if ($action === 'delete_attendance') {
        $id = post_int('id');
        $record = find_attendance($conn, $id);

        if ($record === null) {
            flash('danger', 'Attendance record not found.');
            redirect('attendance.php');
        }

        if ($role === 'Professor' && $record['professor_id'] !== $professorId) {
            abort(403, 'You can only delete your own attendance records.');
        }

        try {
            $stmt = $conn->prepare('DELETE FROM attendance WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            flash('success', 'Attendance record deleted.');
        } catch (Throwable $e) {
            error_log('Delete attendance failed: ' . $e->getMessage());
            flash('danger', 'Unable to delete the record. Please try again.');
        }

        redirect('attendance.php');
    }

    flash('danger', 'Unknown action.');
    redirect('attendance.php');
}

// ---------------------------------------------------------------------------
// Options
// ---------------------------------------------------------------------------

$courses = [];

if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT DISTINCT c.course_code, c.course_name
         FROM schedules s
         INNER JOIN courses c ON c.course_code = s.course_code
         WHERE s.professor_id = ? AND s.status = 'Active' AND c.status = 'Active'
         ORDER BY c.course_code"
    );
    $stmt->bind_param('s', $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query("SELECT course_code, course_name FROM courses WHERE status = 'Active' ORDER BY course_code");
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
}

$professors = [];
if ($role === 'Admin') {
    $result = $conn->query("SELECT professor_id, fullname FROM professors WHERE status = 'Active' ORDER BY fullname");
    while ($row = $result->fetch_assoc()) {
        $professors[] = $row;
    }
}

$students = [];
if ($role === 'Professor') {
    $stmt = $conn->prepare(
        "SELECT DISTINCT st.student_id, st.fullname, st.section
         FROM students st
         WHERE st.status = 'Active'
           AND EXISTS (
               SELECT 1 FROM schedules s
               WHERE s.status = 'Active'
                 AND s.professor_id = ?
                 AND s.section = st.section
           )
         ORDER BY st.fullname"
    );
    $stmt->bind_param('s', $professorId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query(
        "SELECT student_id, fullname, section FROM students WHERE status = 'Active' ORDER BY fullname"
    );
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}

// ---------------------------------------------------------------------------
// Filters and rows
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');
$dateFilter = query_string('date');

if (!in_list($statusFilter, array_merge(['All'], $statusOptions))) {
    $statusFilter = 'All';
}
if ($dateFilter !== '' && !valid_date($dateFilter)) {
    $dateFilter = '';
}

$where = [];
$types = '';
$params = [];

if ($role === 'Professor') {
    $where[] = 'a.professor_id = ?';
    $types .= 's';
    $params[] = $professorId;
}

if ($statusFilter !== 'All') {
    $where[] = 'a.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($dateFilter !== '') {
    $where[] = 'a.attendance_date = ?';
    $types .= 's';
    $params[] = $dateFilter;
}

if ($search !== '') {
    $where[] = '(a.student_id LIKE ? OR st.fullname LIKE ? OR a.course_code LIKE ? OR c.course_name LIKE ? OR p.fullname LIKE ?)';
    $types .= 'sssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$sql = 'SELECT a.*,
               st.fullname AS student_name,
               c.course_name,
               p.fullname AS professor_name
        FROM attendance a
        LEFT JOIN students st ON st.student_id = a.student_id
        LEFT JOIN courses c ON c.course_code = a.course_code
        LEFT JOIN professors p ON p.professor_id = a.professor_id';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY a.attendance_date DESC, a.id DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}
$stmt->close();

$pageTitle = 'Attendance';
$pageSubtitle = $role === 'Professor' ? 'Attendance for the courses you teach' : 'All class attendance records';
$active = 'attendance';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAttendance">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Record attendance</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="attendance.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="attendance-search">Search attendance</label>
        <input type="search" class="form-control" id="attendance-search" name="search"
               value="<?= e($search) ?>" placeholder="Search student, course, or professor">
    </div>

    <label class="visually-hidden" for="attendance-date">Filter by date</label>
    <input type="date" class="form-control" id="attendance-date" name="date" value="<?= e($dateFilter) ?>"
           style="max-width: 180px;">

    <label class="visually-hidden" for="attendance-status">Filter by status</label>
    <select class="form-select" id="attendance-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <?php foreach ($statusOptions as $status): ?>
            <option value="<?= e($status) ?>" <?= selected($statusFilter, $status) ?>><?= e($status) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All' || $dateFilter !== ''): ?>
        <a class="btn btn-outline-secondary" href="attendance.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Student</th>
                    <th scope="col">Course</th>
                    <th scope="col">Professor</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                                <h5>No attendance records</h5>
                                <p>Record attendance or change the filters.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($records as $record): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($record['attendance_date'])) ?></td>
                        <td>
                            <strong><?= e($record['student_name'] ?? $record['student_id']) ?></strong>
                            <div class="text-muted small mono"><?= e($record['student_id']) ?></div>
                        </td>
                        <td>
                            <strong><?= e($record['course_code']) ?></strong>
                            <div class="text-muted small"><?= e($record['course_name'] ?? '') ?></div>
                        </td>
                        <td>
                            <?= e($record['professor_name'] ?? '') !== '' ? e($record['professor_name']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($record['status'])) ?>">
                                <?= e($record['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editAttendance<?= (int) $record['id'] ?>">
                                    Edit
                                </button>

                                <form method="post" data-confirm="Delete this attendance record?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_attendance">
                                    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record attendance -->
<div class="modal fade" id="addAttendance" tabindex="-1" aria-labelledby="addAttendanceLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_attendance">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addAttendanceLabel">Record attendance</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-attendance-student">Student</label>
                            <select class="form-select" id="add-attendance-student" name="student_id" required>
                                <option value="">Select student</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= e($student['student_id']) ?>">
                                        <?= e($student['fullname']) ?> (<?= e($student['student_id']) ?>)
                                        <?= ($student['section'] ?? '') !== '' ? ' — ' . e($student['section']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-attendance-course">Course</label>
                            <select class="form-select" id="add-attendance-course" name="course_code" required>
                                <option value="">Select course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= e($course['course_code']) ?>">
                                        <?= e($course['course_code']) ?> &middot; <?= e($course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($role === 'Admin'): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="add-attendance-professor">Professor</label>
                                <select class="form-select" id="add-attendance-professor" name="professor_id" required>
                                    <option value="">Select professor</option>
                                    <?php foreach ($professors as $professor): ?>
                                        <option value="<?= e($professor['professor_id']) ?>">
                                            <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label class="form-label" for="add-attendance-date">Date</label>
                            <input type="date" class="form-control" id="add-attendance-date" name="attendance_date"
                                   value="<?= e(date('Y-m-d')) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-attendance-status">Status</label>
                            <select class="form-select" id="add-attendance-status" name="status" required>
                                <?php foreach ($statusOptions as $status): ?>
                                    <option value="<?= e($status) ?>"><?= e($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($records as $record): ?>
    <?php $recordId = (int) $record['id']; ?>

    <div class="modal fade" id="editAttendance<?= $recordId ?>" tabindex="-1"
         aria-labelledby="editAttendanceLabel<?= $recordId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_attendance">
                    <input type="hidden" name="id" value="<?= $recordId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editAttendanceLabel<?= $recordId ?>">
                            Edit attendance
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="edit-attendance-student<?= $recordId ?>">Student</label>
                                <select class="form-select" id="edit-attendance-student<?= $recordId ?>"
                                        name="student_id" required>
                                    <?php
                                    $studentFound = false;
                                    foreach ($students as $student):
                                        if ($student['student_id'] === $record['student_id']) {
                                            $studentFound = true;
                                        }
                                        ?>
                                        <option value="<?= e($student['student_id']) ?>"
                                            <?= selected($record['student_id'], $student['student_id']) ?>>
                                            <?= e($student['fullname']) ?> (<?= e($student['student_id']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$studentFound): ?>
                                        <option value="<?= e($record['student_id']) ?>" selected>
                                            <?= e($record['student_id']) ?> (current)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-attendance-course<?= $recordId ?>">Course</label>
                                <select class="form-select" id="edit-attendance-course<?= $recordId ?>"
                                        name="course_code" required>
                                    <?php
                                    $courseFound = false;
                                    foreach ($courses as $course):
                                        if ($course['course_code'] === $record['course_code']) {
                                            $courseFound = true;
                                        }
                                        ?>
                                        <option value="<?= e($course['course_code']) ?>"
                                            <?= selected($record['course_code'], $course['course_code']) ?>>
                                            <?= e($course['course_code']) ?> &middot; <?= e($course['course_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (!$courseFound): ?>
                                        <option value="<?= e($record['course_code']) ?>" selected>
                                            <?= e($record['course_code']) ?> (current)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <?php if ($role === 'Admin'): ?>
                                <div class="col-md-6">
                                    <label class="form-label" for="edit-attendance-professor<?= $recordId ?>">Professor</label>
                                    <select class="form-select" id="edit-attendance-professor<?= $recordId ?>"
                                            name="professor_id" required>
                                        <?php
                                        $professorFound = false;
                                        foreach ($professors as $professor):
                                            if ($professor['professor_id'] === $record['professor_id']) {
                                                $professorFound = true;
                                            }
                                            ?>
                                            <option value="<?= e($professor['professor_id']) ?>"
                                                <?= selected($record['professor_id'], $professor['professor_id']) ?>>
                                                <?= e($professor['fullname']) ?> (<?= e($professor['professor_id']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (!$professorFound): ?>
                                            <option value="<?= e($record['professor_id']) ?>" selected>
                                                <?= e($record['professor_id']) ?> (current)
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="professor_id" value="<?= e($professorId) ?>">
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-attendance-date<?= $recordId ?>">Date</label>
                                <input type="date" class="form-control" id="edit-attendance-date<?= $recordId ?>"
                                       name="attendance_date" value="<?= e($record['attendance_date']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-attendance-status<?= $recordId ?>">Status</label>
                                <select class="form-select" id="edit-attendance-status<?= $recordId ?>" name="status" required>
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?= e($status) ?>" <?= selected($record['status'], $status) ?>>
                                            <?= e($status) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
