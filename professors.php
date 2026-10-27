<?php
/**
 * Professor records (Admin only).
 *
 * Professor IDs are immutable because schedules, grades and attendance
 * reference them as text. Self-registered professors arrive as Pending and are
 * approved here; deactivation also deactivates the linked login account.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

/** Fetch one professor row by primary key. */
function find_professor(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM professors WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_professor') {
        $professorId = post_string('professor_id');
        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $gender = post_string('gender');
        $department = mb_substr(post_string('department'), 0, 100);
        $specialization = mb_substr(post_string('specialization'), 0, 100);
        $email = strtolower(post_string('email'));
        $contactNo = mb_substr(post_string('contact_no'), 0, 20);

        $errors = [];

        if ($professorId !== '' && !preg_match('/^[A-Za-z0-9-]{1,20}$/', $professorId)) {
            $errors[] = 'Professor ID may only contain letters, numbers, and hyphens (maximum 20 characters).';
        }
        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the professor name.';
        }
        if (!in_list($gender, ['', 'Male', 'Female'])) {
            $errors[] = 'Choose a valid gender.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+()\s-]{5,20}$/', $contactNo)) {
            $errors[] = 'Enter a valid contact number.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('professors.php');
        }

        try {
            $createdId = db_transaction($conn, static function () use (
                $conn,
                $professorId,
                $fullname,
                $gender,
                $department,
                $specialization,
                $email,
                $contactNo
            ): string {
                $professorId = $professorId !== '' ? $professorId : next_professor_id($conn);

                $stmt = $conn->prepare(
                    "INSERT INTO professors
                        (professor_id, fullname, gender, department, specialization, email, contact_no, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
                );
                $stmt->bind_param(
                    'sssssss',
                    $professorId,
                    $fullname,
                    $gender,
                    $department,
                    $specialization,
                    $email,
                    $contactNo
                );
                $stmt->execute();
                $stmt->close();

                return $professorId;
            });

            flash('success', 'Professor added successfully with ID ' . $createdId . '.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add professor failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That Professor ID already exists.'
                : 'Unable to add the professor. Please try again.');
        } catch (Throwable $e) {
            error_log('Add professor failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the professor. Please try again.');
        }

        redirect('professors.php');
    }

    if ($action === 'update_professor') {
        $id = post_int('id');
        $professor = find_professor($conn, $id);

        if ($professor === null) {
            flash('danger', 'Professor not found.');
            redirect('professors.php');
        }

        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $gender = post_string('gender');
        $department = mb_substr(post_string('department'), 0, 100);
        $specialization = mb_substr(post_string('specialization'), 0, 100);
        $email = strtolower(post_string('email'));
        $contactNo = mb_substr(post_string('contact_no'), 0, 20);

        $errors = [];

        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the professor name.';
        }
        if (!in_list($gender, ['', 'Male', 'Female'])) {
            $errors[] = 'Choose a valid gender.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+()\s-]{5,20}$/', $contactNo)) {
            $errors[] = 'Enter a valid contact number.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('professors.php');
        }

        try {
            db_transaction($conn, static function () use (
                $conn,
                $professor,
                $fullname,
                $gender,
                $department,
                $specialization,
                $email,
                $contactNo
            ): void {
                $stmt = $conn->prepare(
                    'UPDATE professors
                     SET fullname = ?, gender = ?, department = ?, specialization = ?, email = ?, contact_no = ?
                     WHERE id = ?'
                );
                $stmt->bind_param(
                    'ssssssi',
                    $fullname,
                    $gender,
                    $department,
                    $specialization,
                    $email,
                    $contactNo,
                    $professor['id']
                );
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare(
                    'UPDATE users SET fullname = ?, email = ? WHERE professor_id = ?'
                );
                $stmt->bind_param('sss', $fullname, $email, $professor['professor_id']);
                $stmt->execute();
                $stmt->close();
            });

            flash('success', 'Professor updated successfully.');
        } catch (Throwable $e) {
            error_log('Update professor failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the professor. Please try again.');
        }

        redirect('professors.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $professor = find_professor($conn, $id);

        if ($professor === null) {
            flash('danger', 'Professor not found.');
            redirect('professors.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('professors.php');
        }

        try {
            if ($status === 'Inactive') {
                deactivate_professor($conn, $id);
            } else {
                db_transaction($conn, static function () use ($conn, $professor): void {
                    $stmt = $conn->prepare("UPDATE professors SET status = 'Active' WHERE id = ?");
                    $stmt->bind_param('i', $professor['id']);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE professor_id = ?");
                    $stmt->bind_param('s', $professor['professor_id']);
                    $stmt->execute();
                    $stmt->close();
                });
            }

            if ($professor['status'] === 'Pending' && $status === 'Active') {
                flash('success', 'Professor approved and activated.');
            } else {
                flash('success', $status === 'Active'
                    ? 'Professor and linked account activated.'
                    : 'Professor and linked account deactivated.');
            }
        } catch (Throwable $e) {
            error_log('Professor status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the professor status. Please try again.');
        }

        redirect('professors.php');
    }

    flash('danger', 'Unknown action.');
    redirect('professors.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$statusFilter = query_string('status', 'All');

if (!in_list($statusFilter, ['All', 'Active', 'Pending', 'Inactive'])) {
    $statusFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'All') {
    $where[] = 'p.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(p.professor_id LIKE ? OR p.fullname LIKE ? OR p.department LIKE ? OR p.specialization LIKE ? OR p.email LIKE ?)';
    $types .= 'sssss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$sql = "SELECT p.*,
               (SELECT u.username FROM users u WHERE u.professor_id = p.professor_id LIMIT 1) AS account_username
        FROM professors p";

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY (p.status = 'Pending') DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$professors = [];
while ($row = $result->fetch_assoc()) {
    $professors[] = $row;
}
$stmt->close();

$pageTitle = 'Professors';
$pageSubtitle = 'Faculty records, departments, and approvals';
$active = 'professors';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProfessor">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add professor</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="professors.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="professor-search">Search professors</label>
        <input type="search" class="form-control" id="professor-search" name="search"
               value="<?= e($search) ?>" placeholder="Search ID, name, department, or specialization">
    </div>

    <label class="visually-hidden" for="professor-status">Filter by status</label>
    <select class="form-select" id="professor-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <option value="Active" <?= selected($statusFilter, 'Active') ?>>Active</option>
        <option value="Pending" <?= selected($statusFilter, 'Pending') ?>>Pending</option>
        <option value="Inactive" <?= selected($statusFilter, 'Inactive') ?>>Inactive</option>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $statusFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="professors.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Professor ID</th>
                    <th scope="col">Name</th>
                    <th scope="col">Department</th>
                    <th scope="col">Specialization</th>
                    <th scope="col">Contact</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($professors === []): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-chalkboard-user" aria-hidden="true"></i>
                                <h5>No professors found</h5>
                                <p>Try a different search or filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($professors as $professor): ?>
                    <tr>
                        <td class="mono"><?= e($professor['professor_id']) ?></td>
                        <td>
                            <strong><?= e($professor['fullname']) ?></strong>
                            <?php if (($professor['account_username'] ?? '') !== ''): ?>
                                <div class="text-muted small">Account: <?= e($professor['account_username']) ?></div>
                            <?php else: ?>
                                <div class="text-muted small">No login linked</div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($professor['department'] ?? '') !== '' ? e($professor['department']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= e($professor['specialization'] ?? '') !== '' ? e($professor['specialization']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (($professor['contact_no'] ?? '') !== ''): ?>
                                <?= e($professor['contact_no']) ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($professor['status'])) ?>">
                                <?= e($professor['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#viewProfessor<?= (int) $professor['id'] ?>">
                                    View
                                </button>

                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editProfessor<?= (int) $professor['id'] ?>">
                                    Edit
                                </button>

                                <?php if ($professor['status'] === 'Active'): ?>
                                    <form method="post" data-confirm="Deactivate <?= e($professor['fullname']) ?>? Their login will also be deactivated.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $professor['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $professor['id'] ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                            <?= $professor['status'] === 'Pending' ? 'Approve' : 'Activate' ?>
                                        </button>
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

<!-- Add professor -->
<div class="modal fade" id="addProfessor" tabindex="-1" aria-labelledby="addProfessorLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_professor">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addProfessorLabel">Add professor</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-id">Professor ID</label>
                            <input type="text" class="form-control" id="add-professor-id" name="professor_id" maxlength="20">
                            <p class="form-hint">Leave blank to generate the next ID automatically.</p>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-name">Full name</label>
                            <input type="text" class="form-control" id="add-professor-name" name="fullname" maxlength="100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-gender">Gender</label>
                            <select class="form-select" id="add-professor-gender" name="gender">
                                <option value="">Not specified</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-department">Department</label>
                            <input type="text" class="form-control" id="add-professor-department" name="department" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-specialization">Specialization</label>
                            <input type="text" class="form-control" id="add-professor-specialization" name="specialization" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-email">Email</label>
                            <input type="email" class="form-control" id="add-professor-email" name="email" maxlength="100">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="add-professor-contact">Contact number</label>
                            <input type="text" class="form-control" id="add-professor-contact" name="contact_no" maxlength="20">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add professor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($professors as $professor): ?>
    <?php $professorId = (int) $professor['id']; ?>

    <!-- View professor -->
    <div class="modal fade" id="viewProfessor<?= $professorId ?>" tabindex="-1"
         aria-labelledby="viewProfessorLabel<?= $professorId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="viewProfessorLabel<?= $professorId ?>">
                        <?= e($professor['fullname']) ?>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <?php
                        $details = [
                            'Professor ID' => $professor['professor_id'],
                            'Full name' => $professor['fullname'],
                            'Gender' => $professor['gender'],
                            'Department' => $professor['department'],
                            'Specialization' => $professor['specialization'],
                            'Email' => $professor['email'],
                            'Contact number' => $professor['contact_no'],
                            'Linked account' => $professor['account_username'] ?? '',
                            'Status' => $professor['status'],
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

    <!-- Edit professor -->
    <div class="modal fade" id="editProfessor<?= $professorId ?>" tabindex="-1"
         aria-labelledby="editProfessorLabel<?= $professorId ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_professor">
                    <input type="hidden" name="id" value="<?= $professorId ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editProfessorLabel<?= $professorId ?>">
                            Edit <?= e($professor['fullname']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="form-label d-block">Professor ID</span>
                                <p class="mono mb-0"><?= e($professor['professor_id']) ?></p>
                                <p class="form-hint">Professor IDs are permanent because they are referenced by schedules, grades, and attendance.</p>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-name<?= $professorId ?>">Full name</label>
                                <input type="text" class="form-control" id="edit-professor-name<?= $professorId ?>"
                                       name="fullname" value="<?= e($professor['fullname']) ?>" maxlength="100" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-gender<?= $professorId ?>">Gender</label>
                                <select class="form-select" id="edit-professor-gender<?= $professorId ?>" name="gender">
                                    <option value="">Not specified</option>
                                    <option value="Male" <?= selected($professor['gender'], 'Male') ?>>Male</option>
                                    <option value="Female" <?= selected($professor['gender'], 'Female') ?>>Female</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-department<?= $professorId ?>">Department</label>
                                <input type="text" class="form-control" id="edit-professor-department<?= $professorId ?>"
                                       name="department" value="<?= e($professor['department'] ?? '') ?>" maxlength="100">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-specialization<?= $professorId ?>">Specialization</label>
                                <input type="text" class="form-control" id="edit-professor-specialization<?= $professorId ?>"
                                       name="specialization" value="<?= e($professor['specialization'] ?? '') ?>" maxlength="100">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-email<?= $professorId ?>">Email</label>
                                <input type="email" class="form-control" id="edit-professor-email<?= $professorId ?>"
                                       name="email" value="<?= e($professor['email'] ?? '') ?>" maxlength="100">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="edit-professor-contact<?= $professorId ?>">Contact number</label>
                                <input type="text" class="form-control" id="edit-professor-contact<?= $professorId ?>"
                                       name="contact_no" value="<?= e($professor['contact_no'] ?? '') ?>" maxlength="20">
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
