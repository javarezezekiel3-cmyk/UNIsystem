<?php
/**
 * Student records (Admin only).
 *
 * Student IDs are immutable once created because attendance and grade rows
 * reference them as text. Deactivating a student also deactivates the linked
 * login account, and reactivating restores it.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

$yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

/** Fetch one student row by primary key. */
function find_student(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_student') {
        $studentId = post_string('student_id');
        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $gender = post_string('gender');
        $course = mb_substr(post_string('course'), 0, 100);
        $yearLevel = post_string('year_level');
        $section = mb_substr(post_string('section'), 0, 50);
        $email = strtolower(post_string('email'));
        $contactNo = mb_substr(post_string('contact_no'), 0, 20);

        $errors = [];

        if ($studentId !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $studentId)) {
            $errors[] = 'Student ID may only contain letters, numbers, and hyphens (maximum 20 characters).';
        }
        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the student name.';
        }
        if (!in_list($gender, ['', 'Male', 'Female'])) {
            $errors[] = 'Choose a valid gender.';
        }
        if ($course === '') {
            $errors[] = 'Enter the course.';
        }
        if (!in_list($yearLevel, $yearLevels)) {
            $errors[] = 'Choose a valid year level.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+()\s-]{5,20}$/', $contactNo)) {
            $errors[] = 'Enter a valid contact number.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('students.php');
        }

        try {
            $createdId = db_transaction($conn, static function () use (
                $conn,
                $studentId,
                $fullname,
                $gender,
                $course,
                $yearLevel,
                $section,
                $email,
                $contactNo
            ): string {
                $studentId = $studentId !== '' ? $studentId : next_student_id($conn);

                $stmt = $conn->prepare(
                    "INSERT INTO students
                        (student_id, fullname, gender, course, year_level, section, email, contact_no, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
                );
                $stmt->bind_param(
                    'ssssssss',
                    $studentId,
                    $fullname,
                    $gender,
                    $course,
                    $yearLevel,
                    $section,
                    $email,
                    $contactNo
                );
                $stmt->execute();
                $stmt->close();

                return $studentId;
            });

            flash('success', 'Student added successfully with ID ' . $createdId . '.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add student failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That Student ID already exists.'
                : 'Unable to add the student. Please try again.');
        } catch (Throwable $e) {
            error_log('Add student failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the student. Please try again.');
        }

        redirect('students.php');
    }

    if ($action === 'update_student') {
        $id = post_int('id');
        $student = find_student($conn, $id);

        if ($student === null) {
            flash('danger', 'Student not found.');
            redirect('students.php');
        }

        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $gender = post_string('gender');
        $course = mb_substr(post_string('course'), 0, 100);
        $yearLevel = post_string('year_level');
        $section = mb_substr(post_string('section'), 0, 50);
        $email = strtolower(post_string('email'));
        $contactNo = mb_substr(post_string('contact_no'), 0, 20);

        $errors = [];

        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the student name.';
        }
        if (!in_list($gender, ['', 'Male', 'Female'])) {
            $errors[] = 'Choose a valid gender.';
        }
        if ($course === '') {
            $errors[] = 'Enter the course.';
        }
        if (!in_list($yearLevel, $yearLevels)) {
            $errors[] = 'Choose a valid year level.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+()\s-]{5,20}$/', $contactNo)) {
            $errors[] = 'Enter a valid contact number.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('students.php');
        }

        try {
            db_transaction($conn, static function () use (
                $conn,
                $student,
                $fullname,
                $gender,
                $course,
                $yearLevel,
                $section,
                $email,
                $contactNo
            ): void {
                $stmt = $conn->prepare(
                    'UPDATE students
                     SET fullname = ?, gender = ?, course = ?, year_level = ?, section = ?, email = ?, contact_no = ?
                     WHERE id = ?'
                );
                $stmt->bind_param(
                    'sssssssi',
                    $fullname,
                    $gender,
                    $course,
                    $yearLevel,
                    $section,
                    $email,
                    $contactNo,
                    $student['id']
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    'UPDATE users SET fullname = ?, email = ? WHERE student_id = ?'
                );
                $stmt->bind_param('sss', $fullname, $email, $student['student_id']);
                $stmt->execute();
                $stmt->close();
            });

            flash('success', 'Student updated successfully.');
        } catch (Throwable $e) {
            error_log('Update student failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the student. Please try again.');
        }

        redirect('students.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $student = find_student($conn, $id);

        if ($student === null) {
            flash('danger', 'Student not found.');
            redirect('students.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('students.php');
        }

        try {
            if ($status === 'Inactive') {
                deactivate_student($conn, $id);
            } else {
                db_transaction($conn, static function () use ($conn, $student): void {
                    $stmt = $conn->prepare("UPDATE students SET status = 'Active' WHERE id = ?");
                    $stmt->bind_param('i', $student['id']);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE student_id = ?");
                    $stmt->bind_param('s', $student['student_id']);
                    $stmt->execute();
                    $stmt->close();
                });
            }

            flash('success', $status === 'Active'
                ? 'Student and linked account activated.'
                : 'Student and linked account deactivated.');
        } catch (Throwable $e) {
            error_log('Student status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the student status. Please try again.');
        }

        redirect('students.php');
    }

    flash('danger', 'Unknown action.');
    redirect('students.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');

if (!in_list($statusFilter, ['All', 'Active', 'Inactive'])) {
    $statusFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'All') {
    $where[] = 's.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(s.student_id LIKE ? OR s.fullname LIKE ? OR s.course LIKE ? OR s.section LIKE ? OR s.email LIKE ?)';
    $types .= 'sssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$sql = "SELECT s.*,
               (SELECT u.username FROM users u WHERE u.student_id = s.student_id LIMIT 1) AS account_username
        FROM students s";

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY s.id DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}
$stmt->close();

$courseCodes = [];
$courseResult = $conn->query("SELECT course_code FROM courses WHERE status = 'Active' ORDER BY course_code");
while ($row = $courseResult->fetch_assoc()) {
    $courseCodes[] = $row['course_code'];
}

$pageTitle = 'Students';
$pageSubtitle = 'Student records, sections, and account links';
$active = 'students';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStudent">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add student</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="students.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="student-search">Search students</label>
        <input type="search" class="form-control" id="student-search" name="search"
               value="<?= e($search) ?>" placeholder="Search ID, name, course, section, or email">
    </div>

    <label class="visually-hidden" for="student-status">Filter by status</label>
    <select class="form-select" id="student-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="students.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Student ID</th>
                    <th scope="col">Name</th>
                    <th scope="col">Course / Year</th>
                    <th scope="col">Section</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($students === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
                                <h5>No students found</h5>
                                <p>Try a different search or filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($students as $student): ?>
                    <tr>
                        <td class="mono"><?= e($student['student_id']) ?></td>
                        <td>
                            <strong><?= e($student['fullname']) ?></strong>
                            <?php if (($student['account_username'] ?? '') !== ''): ?>
                                <div class="text-muted small">Account: <?= e($student['account_username']) ?></div>
                            <?php else: ?>
                                <div class="text-muted small">No login linked</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($student['course']) ?>
                            <div class="text-muted small"><?= e($student['year_level']) ?></div>
                        </td>
                        <td><?= e($student['section']) !== '' ? e($student['section']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (($student['contact_no'] ?? '') !== ''): ?>
                                <?= e($student['contact_no']) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($student['status'])) ?>">
                                <?= e($student['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewStudent<?= (int) $student['id'] ?>">
                                    View
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editStudent<?= (int) $student['id'] ?>">
                                    Edit
                                </button>

                                <?php if ($student['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate <?= e($student['fullname']) ?>? Their login will also be deactivated.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $student['id'] ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add student -->
<div class="modal fade" id="addStudent" tabindex="-1" aria-labelledby="addStudentLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_student">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addStudentLabel">Add student</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-student-id">Student ID</label>
                            <input type="text" class="form-control" id="add-student-id" name="student_id" maxlength="20">
                            <p class="form-hint">Leave blank to generate the next ID automatically.</p>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-name">Full name</label>
                            <input type="text" class="form-control" id="add-student-name" name="fullname" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-gender">Gender</label>
                            <select class="form-select" id="add-student-gender" name="gender">
                                <option value="">Not specified</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-course">Course</label>
                            <input type="text" class="form-control" id="add-student-course" name="course"
                                   list="course-options" maxlength="100" placeholder="e.g. BSIT" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-year">Year level</label>
                            <select class="form-select" id="add-student-year" name="year_level" required>
                                <option value="">Select year level</option>
                                <?php foreach ($yearLevels as $yearLevel): ?>
                                    <option value="<?= e($yearLevel) ?>"><?= e($yearLevel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-section">Section</label>
                            <input type="text" class="form-control" id="add-student-section" name="section"
                                   maxlength="50" placeholder="e.g. A">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-email">Email</label>
                            <input type="email" class="form-control" id="add-student-email" name="email" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-student-contact">Contact number</label>
                            <input type="text" class="form-control" id="add-student-contact" name="contact_no" maxlength="20">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($students as $student): ?>
    <?php $studentId = (int) $student['id']; ?>

    <!-- View student -->
    <div class="modal fade" id="viewStudent<?= $studentId ?>" tabindex="-1"
         aria-labelledby="viewStudentLabel<?= $studentId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="viewStudentLabel<?= $studentId ?>">
                        <?= e($student['fullname']) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <?php
                        $details = [
                            'Student ID' => $student['student_id'],
                            'Full name' => $student['fullname'],
                            'Gender' => $student['gender'],
                            'Course' => $student['course'],
                            'Year level' => $student['year_level'],
                            'Section' => $student['section'],
                            'Email' => $student['email'],
                            'Contact number' => $student['contact_no'],
                            'Linked account' => $student['account_username'] ?? '',
                        ];
                        ?>
                        <?php foreach ($details as $label => $value): ?>
                            <div class="col-md-6">
                                <span class="form-label d-block"><?= e($label) ?></span>
                                <p class="mb-0">
                                    <?= ($value ?? '') !== '' ? e($value) : '<span class="text-muted">—</span>' ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit student -->
    <div class="modal fade" id="editStudent<?= $studentId ?>" tabindex="-1"
         aria-labelledby="editStudentLabel<?= $studentId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_student">
                    <input type="hidden" name="id" value="<?= $studentId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editStudentLabel<?= $studentId ?>">
                            Edit <?= e($student['fullname']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="form-label d-block">Student ID</span>
                                <p class="mono mb-0"><?= e($student['student_id']) ?></p>
                                <p class="form-hint">Student IDs are permanent because they are referenced by grades and attendance.</p>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-name<?= $studentId ?>">Full name</label>
                                <input type="text" class="form-control" id="edit-student-name<?= $studentId ?>"
                                       name="fullname" value="<?= e($student['fullname']) ?>" maxlength="100" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-gender<?= $studentId ?>">Gender</label>
                                <select class="form-select" id="edit-student-gender<?= $studentId ?>" name="gender">
                                    <option value="">Not specified</option>
                                    <option value="Male" <?= selected($student['gender'], 'Male') ?>>Male</option>
                                    <option value="Female" <?= selected($student['gender'], 'Female') ?>>Female</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-course<?= $studentId ?>">Course</label>
                                <input type="text" class="form-control" id="edit-student-course<?= $studentId ?>"
                                       name="course" list="course-options" value="<?= e($student['course']) ?>"
                                       maxlength="100" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-year<?= $studentId ?>">Year level</label>
                                <select class="form-select" id="edit-student-year<?= $studentId ?>" name="year_level" required>
                                    <?php foreach ($yearLevels as $yearLevel): ?>
                                        <option value="<?= e($yearLevel) ?>" <?= selected($student['year_level'], $yearLevel) ?>>
                                            <?= e($yearLevel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-section<?= $studentId ?>">Section</label>
                                <input type="text" class="form-control" id="edit-student-section<?= $studentId ?>"
                                       name="section" value="<?= e($student['section'] ?? '') ?>" maxlength="50">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-email<?= $studentId ?>">Email</label>
                                <input type="email" class="form-control" id="edit-student-email<?= $studentId ?>"
                                       name="email" value="<?= e($student['email'] ?? '') ?>" maxlength="100">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-student-contact<?= $studentId ?>">Contact number</label>
                                <input type="text" class="form-control" id="edit-student-contact<?= $studentId ?>"
                                       name="contact_no" value="<?= e($student['contact_no'] ?? '') ?>" maxlength="20">
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

<datalist id="course-options">
    <?php foreach ($courseCodes as $code): ?>
        <option value="<?= e($code) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php require __DIR__ . '/inc/footer.php'; ?>
