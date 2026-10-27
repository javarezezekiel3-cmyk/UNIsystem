<?php
/**
 * Course catalogue (Admin only).
 *
 * Course codes are immutable because schedules, grades and attendance use the
 * code as their key. Deactivating a course also deactivates its active
 * schedules so students stop seeing classes that no longer run.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

/** Fetch one course row by primary key. */
function find_course(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Validate the shared course form payload. @return array<int,string> */
function course_errors(array $input): array
{
    $errors = [];

    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $input['course_code'])) {
        $errors[] = 'Course code may only contain letters, numbers, and hyphens (maximum 20 characters).';
    }
    if (mb_strlen($input['course_name']) < 2) {
        $errors[] = 'Enter the course name.';
    }
    if ($input['units'] < 0 || $input['units'] > 20) {
        $errors[] = 'Units must be between 0 and 20.';
    }
    if (mb_strlen($input['description']) > 1000) {
        $errors[] = 'The description is too long (maximum 1000 characters).';
    }

    return $errors;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_course') {
        $input = [
            'course_code' => strtoupper(post_string('course_code')),
            'course_name' => mb_substr(post_string('course_name'), 0, 100),
            'department' => mb_substr(post_string('department'), 0, 100),
            'units' => post_int('units'),
            'description' => mb_substr(post_string('description'), 0, 1000),
        ];

        $errors = course_errors($input);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('courses.php');
        }

        try {
            $stmt = $conn->prepare(
                "INSERT INTO courses (course_code, course_name, department, units, description, status)
                 VALUES (?, ?, ?, ?, ?, 'Active')"
            );
            $stmt->bind_param(
                'sssis',
                $input['course_code'],
                $input['course_name'],
                $input['department'],
                $input['units'],
                $input['description']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Course added successfully.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add course failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That course code already exists.'
                : 'Unable to add the course. Please try again.');
        } catch (Throwable $e) {
            error_log('Add course failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the course. Please try again.');
        }

        redirect('courses.php');
    }

    if ($action === 'update_course') {
        $id = post_int('id');
        $course = find_course($conn, $id);

        if ($course === null) {
            flash('danger', 'Course not found.');
            redirect('courses.php');
        }

        $input = [
            'course_code' => $course['course_code'],
            'course_name' => mb_substr(post_string('course_name'), 0, 100),
            'department' => mb_substr(post_string('department'), 0, 100),
            'units' => post_int('units'),
            'description' => mb_substr(post_string('description'), 0, 1000),
        ];

        $errors = course_errors($input);

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('courses.php');
        }

        try {
            $stmt = $conn->prepare(
                'UPDATE courses
                 SET course_name = ?, department = ?, units = ?, description = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssisi',
                $input['course_name'],
                $input['department'],
                $input['units'],
                $input['description'],
                $course['id']
            );
            $stmt->execute();
            $stmt->close();

            flash('success', 'Course updated successfully.');
        } catch (Throwable $e) {
            error_log('Update course failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the course. Please try again.');
        }

        redirect('courses.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $course = find_course($conn, $id);

        if ($course === null) {
            flash('danger', 'Course not found.');
            redirect('courses.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('courses.php');
        }

        try {
            $deactivatedSchedules = 0;

            db_transaction($conn, static function () use ($conn, $course, $status, &$deactivatedSchedules): void {
                $stmt = $conn->prepare('UPDATE courses SET status = ? WHERE id = ?');
                $stmt->bind_param('si', $status, $course['id']);
                $stmt->execute();
                $stmt->close();

                if ($status === 'Inactive') {
                    $stmt = $conn->prepare(
                        "UPDATE schedules SET status = 'Inactive'
                         WHERE course_code = ? AND status = 'Active'"
                    );
                    $stmt->bind_param('s', $course['course_code']);
                    $stmt->execute();
                    $deactivatedSchedules = $stmt->affected_rows;
                    $stmt->close();
                }
            });

            $message = $status === 'Active'
                ? 'Course activated. Reactivate its schedules individually if needed.'
                : 'Course deactivated.';

            if ($deactivatedSchedules > 0) {
                $message .= ' ' . $deactivatedSchedules . ' active schedule'
                    . ($deactivatedSchedules === 1 ? ' was' : 's were') . ' also deactivated.';
            }

            flash('success', $message);
        } catch (Throwable $e) {
            error_log('Course status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the course status. Please try again.');
        }

        redirect('courses.php');
    }

    flash('danger', 'Unknown action.');
    redirect('courses.php');
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
    $where[] = 'c.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(c.course_code LIKE ? OR c.course_name LIKE ? OR c.department LIKE ?)';
    $types .= 'sss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

$sql = 'SELECT c.*,
               (SELECT COUNT(*) FROM schedules s WHERE s.course_code = c.course_code AND s.status = \'Active\') AS active_schedules
        FROM courses c';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY c.course_code ASC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$courses = [];
while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}
$stmt->close();

$departments = [];
$departmentResult = $conn->query(
    "SELECT DISTINCT department FROM courses WHERE department <> '' ORDER BY department"
);
while ($row = $departmentResult->fetch_assoc()) {
    $departments[] = $row['department'];
}

$pageTitle = 'Courses';
$pageSubtitle = 'Course catalogue, units, and schedule counts';
$active = 'courses';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCourse">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add course</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="courses.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="course-search">Search courses</label>
        <input type="search" class="form-control" id="course-search" name="search"
               value="<?= e($search) ?>" placeholder="Search code, name, or department">
    </div>

    <label class="visually-hidden" for="course-status">Filter by status</label>
    <select class="form-select" id="course-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="courses.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Code</th>
                    <th scope="col">Course</th>
                    <th scope="col">Department</th>
                    <th scope="col">Units</th>
                    <th scope="col">Schedules</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($courses === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                                <h5>No courses found</h5>
                                <p>Try a different search or filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($courses as $course): ?>
                    <tr>
                        <td class="mono"><?= e($course['course_code']) ?></td>
                        <td>
                            <strong><?= e($course['course_name']) ?></strong>
                            <?php if (($course['description'] ?? '') !== ''): ?>
                                <div class="text-muted small text-truncate" style="max-width: 320px;">
                                    <?= e($course['description']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($course['department'] ?? '') !== '' ? e($course['department']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= (int) $course['units'] ?></td>
                        <td>
                            <span class="badge text-bg-secondary"><?= (int) $course['active_schedules'] ?></span>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($course['status'])) ?>">
                                <?= e($course['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editCourse<?= (int) $course['id'] ?>">
                                    Edit
                                </button>

                                <?php if ($course['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate <?= e($course['course_code']) ?>? Its active schedules will also be deactivated.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $course['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $course['id'] ?>">
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

<!-- Add course -->
<div class="modal fade" id="addCourse" tabindex="-1" aria-labelledby="addCourseLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_course">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addCourseLabel">Add course</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="add-course-code">Course code</label>
                            <input type="text" class="form-control" id="add-course-code" name="course_code"
                                   maxlength="20" placeholder="e.g. BSIT" required>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label" for="add-course-name">Course name</label>
                            <input type="text" class="form-control" id="add-course-name" name="course_name"
                                   maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-course-department">Department</label>
                            <input type="text" class="form-control" id="add-course-department" name="department"
                                   list="department-options" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-course-units">Units</label>
                            <input type="number" class="form-control" id="add-course-units" name="units"
                                   min="0" max="20" step="1" value="3" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="add-course-description">Description</label>
                            <textarea class="form-control" id="add-course-description" name="description"
                                      maxlength="1000" rows="3"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add course</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($courses as $course): ?>
    <?php $courseId = (int) $course['id']; ?>

    <div class="modal fade" id="editCourse<?= $courseId ?>" tabindex="-1"
         aria-labelledby="editCourseLabel<?= $courseId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_course">
                    <input type="hidden" name="id" value="<?= $courseId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editCourseLabel<?= $courseId ?>">
                            Edit <?= e($course['course_code']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <span class="form-label d-block">Course code</span>
                                <p class="mono mb-0"><?= e($course['course_code']) ?></p>
                                <p class="form-hint">Course codes are permanent because schedules and grades reference them.</p>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label" for="edit-course-name<?= $courseId ?>">Course name</label>
                                <input type="text" class="form-control" id="edit-course-name<?= $courseId ?>"
                                       name="course_name" value="<?= e($course['course_name']) ?>"
                                       maxlength="100" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-course-department<?= $courseId ?>">Department</label>
                                <input type="text" class="form-control" id="edit-course-department<?= $courseId ?>"
                                       name="department" list="department-options"
                                       value="<?= e($course['department'] ?? '') ?>" maxlength="100">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-course-units<?= $courseId ?>">Units</label>
                                <input type="number" class="form-control" id="edit-course-units<?= $courseId ?>"
                                       name="units" min="0" max="20" step="1"
                                       value="<?= (int) $course['units'] ?>" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label" for="edit-course-description<?= $courseId ?>">Description</label>
                                <textarea class="form-control" id="edit-course-description<?= $courseId ?>"
                                          name="description" maxlength="1000" rows="3"><?= e($course['description'] ?? '') ?></textarea>
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

<datalist id="department-options">
    <?php foreach ($departments as $department): ?>
        <option value="<?= e($department) ?>"></option>
    <?php endforeach; ?>
</datalist>

<?php require __DIR__ . '/inc/footer.php'; ?>
