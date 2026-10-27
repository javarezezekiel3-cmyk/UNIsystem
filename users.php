<?php
/**
 * User management (Admin only).
 *
 * Accounts are never deleted; they move between Active, Pending and Inactive.
 * Approving a self-registered professor also activates the linked professor
 * profile. Admin-created Student/Professor accounts get a profile immediately.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_role('Admin');

$roles = ['Admin', 'Professor', 'Student'];
$statuses = ['Active', 'Pending', 'Inactive'];

/** Fetch one user row by id. */
function find_user(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'add_user') {
        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $username = post_string('username');
        $email = strtolower(post_string('email'));
        $password = (string) ($_POST['password'] ?? '');
        $role = post_string('role');

        $errors = [];

        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the full name.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3 to 50 characters and may only contain letters, numbers, dots, hyphens, and underscores.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        $passwordProblem = password_problem($password);
        if ($passwordProblem !== null) {
            $errors[] = $passwordProblem;
        }
        if (!in_list($role, $roles)) {
            $errors[] = 'Choose a valid role.';
        }

        if ($errors === []) {
            $stmt = $conn->prepare(
                "SELECT id FROM users
                 WHERE username = ? OR (? <> '' AND email = ?)
                 LIMIT 1"
            );
            $stmt->bind_param('sss', $username, $email, $email);
            $stmt->execute();
            $duplicate = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($duplicate) {
                $errors[] = 'That username or email address is already in use.';
            }
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('users.php');
        }

        try {
            db_transaction($conn, static function () use ($conn, $fullname, $username, $email, $password, $role): void {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "INSERT INTO users (fullname, username, email, password, role, status)
                     VALUES (?, ?, ?, ?, ?, 'Active')"
                );
                $stmt->bind_param('sssss', $fullname, $username, $email, $hash, $role);
                $stmt->execute();
                $userId = (int) $conn->insert_id;
                $stmt->close();

                if ($role === 'Student') {
                    $studentId = create_student_profile($conn, $fullname, $email);
                    $stmt = $conn->prepare('UPDATE users SET student_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $studentId, $userId);
                    $stmt->execute();
                    $stmt->close();
                } elseif ($role === 'Professor') {
                    $professorId = create_professor_profile($conn, $fullname, $email, $userId, 'Active');
                    $stmt = $conn->prepare('UPDATE users SET professor_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $professorId, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            });

            flash('success', 'User added successfully.');
        } catch (mysqli_sql_exception $e) {
            error_log('Add user failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That username or email address is already in use.'
                : 'Unable to add the user. Please try again.');
        } catch (Throwable $e) {
            error_log('Add user failed: ' . $e->getMessage());
            flash('danger', 'Unable to add the user. Please try again.');
        }

        redirect('users.php');
    }

    if ($action === 'update_user') {
        $id = post_int('id');
        $fullname = mb_substr(post_string('fullname'), 0, 100);
        $username = post_string('username');
        $email = strtolower(post_string('email'));
        $password = (string) ($_POST['password'] ?? '');

        $user = find_user($conn, $id);

        if ($user === null) {
            flash('danger', 'User not found.');
            redirect('users.php');
        }

        $errors = [];

        if (mb_strlen($fullname) < 2) {
            $errors[] = 'Enter the full name.';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3 to 50 characters and may only contain letters, numbers, dots, hyphens, and underscores.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($password !== '') {
            $passwordProblem = password_problem($password);
            if ($passwordProblem !== null) {
                $errors[] = $passwordProblem;
            }
        }

        if ($errors === []) {
            $stmt = $conn->prepare(
                "SELECT id FROM users
                 WHERE id <> ? AND (username = ? OR (? <> '' AND email = ?))
                 LIMIT 1"
            );
            $stmt->bind_param('isss', $id, $username, $email, $email);
            $stmt->execute();
            $duplicate = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($duplicate) {
                $errors[] = 'That username or email address is already in use.';
            }
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('users.php');
        }

        try {
            db_transaction($conn, static function () use ($conn, $user, $fullname, $username, $email, $password): void {
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        'UPDATE users SET fullname = ?, username = ?, email = ?, password = ? WHERE id = ?'
                    );
                    $stmt->bind_param('ssssi', $fullname, $username, $email, $hash, $user['id']);
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE users SET fullname = ?, username = ?, email = ? WHERE id = ?'
                    );
                    $stmt->bind_param('sssi', $fullname, $username, $email, $user['id']);
                }
                $stmt->execute();
                $stmt->close();

                if (($user['student_id'] ?? '') !== '') {
                    $stmt = $conn->prepare(
                        'UPDATE students SET fullname = ?, email = ? WHERE student_id = ?'
                    );
                    $stmt->bind_param('sss', $fullname, $email, $user['student_id']);
                    $stmt->execute();
                    $stmt->close();
                }

                if (($user['professor_id'] ?? '') !== '') {
                    $stmt = $conn->prepare(
                        'UPDATE professors SET fullname = ?, email = ? WHERE professor_id = ?'
                    );
                    $stmt->bind_param('sss', $fullname, $email, $user['professor_id']);
                    $stmt->execute();
                    $stmt->close();
                }
            });

            if ($id === current_user_id()) {
                $_SESSION['fullname'] = $fullname;
            }

            flash('success', 'User updated successfully.');
        } catch (mysqli_sql_exception $e) {
            error_log('Update user failed: ' . $e->getMessage());
            flash('danger', is_unique_violation($e)
                ? 'That username or email address is already in use.'
                : 'Unable to update the user. Please try again.');
        } catch (Throwable $e) {
            error_log('Update user failed: ' . $e->getMessage());
            flash('danger', 'Unable to update the user. Please try again.');
        }

        redirect('users.php');
    }

    if ($action === 'set_status') {
        $id = post_int('id');
        $status = post_string('status');
        $user = find_user($conn, $id);

        if ($user === null) {
            flash('danger', 'User not found.');
            redirect('users.php');
        }

        if (!in_list($status, ['Active', 'Inactive'])) {
            flash('danger', 'Invalid status.');
            redirect('users.php');
        }

        if ($id === current_user_id() && $status === 'Inactive') {
            flash('danger', 'You cannot deactivate your own account.');
            redirect('users.php');
        }

        if ($user['role'] === 'Admin' && $status === 'Inactive') {
            $otherAdmins = db_count(
                $conn,
                "SELECT COUNT(*) FROM users WHERE role = 'Admin' AND status = 'Active' AND id <> ?",
                'i',
                [$id]
            );

            if ($otherAdmins === 0) {
                flash('danger', 'At least one active administrator must remain.');
                redirect('users.php');
            }
        }

        try {
            db_transaction($conn, static function () use ($conn, $user, $status): void {
                $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
                $stmt->bind_param('si', $status, $user['id']);
                $stmt->execute();
                $stmt->close();

                if (($user['student_id'] ?? '') !== '') {
                    $stmt = $conn->prepare('UPDATE students SET status = ? WHERE student_id = ?');
                    $stmt->bind_param('ss', $status, $user['student_id']);
                    $stmt->execute();
                    $stmt->close();
                }

                if (($user['professor_id'] ?? '') !== '') {
                    $stmt = $conn->prepare('UPDATE professors SET status = ? WHERE professor_id = ?');
                    $stmt->bind_param('ss', $status, $user['professor_id']);
                    $stmt->execute();
                    $stmt->close();
                }
            });

            if ($user['status'] === 'Pending' && $status === 'Active') {
                flash('success', 'Account approved and activated.');
            } else {
                flash('success', $status === 'Active' ? 'Account activated.' : 'Account deactivated.');
            }
        } catch (Throwable $e) {
            error_log('Status change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change the account status. Please try again.');
        }

        redirect('users.php');
    }

    if ($action === 'create_profile') {
        $id = post_int('id');
        $user = find_user($conn, $id);

        if ($user === null) {
            flash('danger', 'User not found.');
            redirect('users.php');
        }

        if (!in_list($user['role'], ['Student', 'Professor'])) {
            flash('danger', 'Only student and professor accounts need profiles.');
            redirect('users.php');
        }

        $alreadyLinked = $user['role'] === 'Student'
            ? ($user['student_id'] ?? '') !== ''
            : ($user['professor_id'] ?? '') !== '';

        if ($alreadyLinked) {
            flash('warning', 'That account is already linked to a profile.');
            redirect('users.php');
        }

        try {
            $profileStatus = normalize_status((string) $user['status']);

            db_transaction($conn, static function () use ($conn, $user, $profileStatus): void {
                if ($user['role'] === 'Student') {
                    $studentId = create_student_profile($conn, $user['fullname'], (string) ($user['email'] ?? ''));
                    $stmt = $conn->prepare('UPDATE users SET student_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $studentId, $user['id']);
                } else {
                    $professorId = create_professor_profile(
                        $conn,
                        $user['fullname'],
                        (string) ($user['email'] ?? ''),
                        (int) $user['id'],
                        $profileStatus
                    );
                    $stmt = $conn->prepare('UPDATE users SET professor_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $professorId, $user['id']);
                }
                $stmt->execute();
                $stmt->close();
            });

            flash('success', 'Profile created and linked.');
        } catch (Throwable $e) {
            error_log('Create profile failed: ' . $e->getMessage());
            flash('danger', 'Unable to create the profile. Please try again.');
        }

        redirect('users.php');
    }

    flash('danger', 'Unknown action.');
    redirect('users.php');
}

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

$search = mb_substr(query_string('search'), 0, 100);
$roleFilter = query_string('role', 'All');
$statusFilter = query_string('status', 'All');

if (!in_list($roleFilter, array_merge(['All'], $roles))) {
    $roleFilter = 'All';
}
if (!in_list($statusFilter, array_merge(['All'], $statuses))) {
    $statusFilter = 'All';
}

$where = [];
$types = '';
$params = [];

if ($statusFilter !== 'All') {
    $where[] = 'status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($roleFilter !== 'All') {
    $where[] = 'role = ?';
    $types .= 's';
    $params[] = $roleFilter;
}

if ($search !== '') {
    $where[] = '(fullname LIKE ? OR username LIKE ? OR email LIKE ?)';
    $types .= 'sss';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

$sql = 'SELECT id, fullname, username, email, role, status, student_id, professor_id FROM users';

if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= " ORDER BY (status = 'Pending') DESC, id DESC";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

$roleBadge = static function (string $role): string {
    return match ($role) {
        'Admin' => 'text-bg-dark',
        'Professor' => 'text-bg-primary',
        default => 'text-bg-info',
    };
};

$pageTitle = 'Users';
$pageSubtitle = 'Accounts, roles, and pending approvals';
$active = 'users';
$pageActions = '<button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUser">'
    . '<i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add user</button>';
require __DIR__ . '/inc/header.php';
?>

<form class="toolbar" method="get" action="users.php">
    <div class="search-box">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="user-search">Search users</label>
        <input type="search" class="form-control" id="user-search" name="search"
               value="<?= e($search) ?>" placeholder="Search name, username, or email">
    </div>

    <label class="visually-hidden" for="filter-role">Filter by role</label>
    <select class="form-select" id="filter-role" name="role">
        <option value="All" <?= selected($roleFilter, 'All') ?>>All roles</option>
        <?php foreach ($roles as $role): ?>
            <option value="<?= e($role) ?>" <?= selected($roleFilter, $role) ?>><?= e($role) ?></option>
        <?php endforeach; ?>
    </select>

    <label class="visually-hidden" for="filter-status">Filter by status</label>
    <select class="form-select" id="filter-status" name="status">
        <option value="All" <?= selected($statusFilter, 'All') ?>>All statuses</option>
        <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>" <?= selected($statusFilter, $status) ?>><?= e($status) ?></option>
        <?php endforeach; ?>
    </select>

    <button class="btn btn-primary" type="submit">
        <i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Filter
    </button>

    <?php if ($search !== '' || $roleFilter !== 'All' || $statusFilter !== 'All'): ?>
        <a class="btn btn-outline-secondary" href="users.php">Reset</a>
    <?php endif; ?>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Username</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <i class="fa-solid fa-users-slash" aria-hidden="true"></i>
                                <h5>No users found</h5>
                                <p>Try a different search or filter.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($users as $user): ?>
                    <?php
                    $isSelf = (int) $user['id'] === current_user_id();
                    $linkedId = $user['role'] === 'Student'
                        ? (string) ($user['student_id'] ?? '')
                        : (string) ($user['professor_id'] ?? '');
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($user['fullname']) ?></strong>
                            <?php if ($isSelf): ?>
                                <span class="badge text-bg-secondary ms-1">You</span>
                            <?php endif; ?>
                            <?php if ($linkedId !== ''): ?>
                                <div class="text-muted mono"><?= e($linkedId) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($user['username']) ?></td>
                        <td><?= e($user['email'] ?? '') !== '' ? e($user['email']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span class="badge <?= e($roleBadge($user['role'])) ?>"><?= e($user['role']) ?></span>
                        </td>
                        <td>
                            <span class="badge text-bg-<?= e(status_badge_class($user['status'])) ?>">
                                <?= e($user['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions justify-content-end">
                                <?php if ($user['status'] === 'Pending'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                    </form>
                                <?php elseif ($user['status'] === 'Inactive'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="status" value="Active">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" data-confirm="Deactivate <?= e($user['fullname']) ?>? They will not be able to sign in until reactivated.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="status" value="Inactive">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" <?= $isSelf ? 'disabled' : '' ?>>Deactivate</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($user['role'] !== 'Admin' && $linkedId === ''): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="create_profile">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Create profile</button>
                                    </form>
                                <?php endif; ?>

                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editUser<?= (int) $user['id'] ?>">
                                    Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add user -->
<div class="modal fade" id="addUser" tabindex="-1" aria-labelledby="addUserLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_user">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="addUserLabel">Add user</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="add-fullname">Full name</label>
                        <input type="text" class="form-control" id="add-fullname" name="fullname" maxlength="100" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="add-username">Username</label>
                        <input type="text" class="form-control" id="add-username" name="username" maxlength="50" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="add-email">Email <span class="text-muted">(optional)</span></label>
                        <input type="email" class="form-control" id="add-email" name="email" maxlength="255">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="add-password">Password</label>
                        <input type="password" class="form-control" id="add-password" name="password"
                               autocomplete="new-password" required>
                        <p class="form-hint">At least 8 characters with an uppercase letter, a lowercase letter, and a number.</p>
                    </div>

                    <div>
                        <label class="form-label" for="add-role">Role</label>
                        <select class="form-select" id="add-role" name="role" required>
                            <option value="Student">Student</option>
                            <option value="Professor">Professor</option>
                            <option value="Admin">Admin</option>
                        </select>
                        <p class="form-hint">Student and Professor accounts get a linked profile automatically.</p>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit modals -->
<?php foreach ($users as $user): ?>
    <div class="modal fade" id="editUser<?= (int) $user['id'] ?>" tabindex="-1"
         aria-labelledby="editUserLabel<?= (int) $user['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">

                    <div class="modal-header">
                        <h2 class="modal-title h5" id="editUserLabel<?= (int) $user['id'] ?>">
                            Edit <?= e($user['fullname']) ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="edit-fullname<?= (int) $user['id'] ?>">Full name</label>
                            <input type="text" class="form-control" id="edit-fullname<?= (int) $user['id'] ?>"
                                   name="fullname" value="<?= e($user['fullname']) ?>" maxlength="100" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="edit-username<?= (int) $user['id'] ?>">Username</label>
                            <input type="text" class="form-control" id="edit-username<?= (int) $user['id'] ?>"
                                   name="username" value="<?= e($user['username']) ?>" maxlength="50" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="edit-email<?= (int) $user['id'] ?>">Email</label>
                            <input type="email" class="form-control" id="edit-email<?= (int) $user['id'] ?>"
                                   name="email" value="<?= e($user['email'] ?? '') ?>" maxlength="255">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="edit-password<?= (int) $user['id'] ?>">New password</label>
                            <input type="password" class="form-control" id="edit-password<?= (int) $user['id'] ?>"
                                   name="password" autocomplete="new-password">
                            <p class="form-hint">Leave blank to keep the current password.</p>
                        </div>

                        <div>
                            <span class="form-label d-block">Role</span>
                            <span class="badge <?= e($roleBadge($user['role'])) ?>"><?= e($user['role']) ?></span>
                            <p class="form-hint mb-0">Roles cannot be changed after the account is created.</p>
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
