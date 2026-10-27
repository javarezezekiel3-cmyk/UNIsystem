<?php
/**
 * Self-registration.
 *
 * Students are activated immediately and receive a generated Student ID.
 * Professors are created in a Pending state and can only sign in after an
 * administrator approves the account from the Users page.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$old = [
    'fullname' => '',
    'username' => '',
    'email' => '',
    'role' => '',
];

if (is_post()) {
    csrf_verify();

    $old['fullname'] = post_string('fullname');
    $old['username'] = post_string('username');
    $old['email'] = strtolower(post_string('email'));
    $old['role'] = post_string('role');

    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if ($old['fullname'] === '' || mb_strlen($old['fullname']) < 2 || mb_strlen($old['fullname']) > 100) {
        $errors[] = 'Enter your full name (2 to 100 characters).';
    }

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $old['username'])) {
        $errors[] = 'Username must be 3 to 50 characters and may only contain letters, numbers, dots, hyphens, and underscores.';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($old['email']) > 255) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($password) < 8
        || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must be at least 8 characters and include an uppercase letter, a lowercase letter, and a number.';
    } elseif ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!in_list($old['role'], ['Student', 'Professor'])) {
        $errors[] = 'Choose a valid account type.';
    }

    if ($errors === []) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->bind_param('ss', $old['username'], $old['email']);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $errors[] = 'That username or email address is already registered.';
        }
    }

    if ($errors === []) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $status = $old['role'] === 'Professor' ? 'Pending' : 'Active';

        try {
            db_transaction($conn, static function () use ($conn, $old, $passwordHash, $status): void {
                $stmt = $conn->prepare(
                    'INSERT INTO users (fullname, username, email, password, role, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'ssssss',
                    $old['fullname'],
                    $old['username'],
                    $old['email'],
                    $passwordHash,
                    $old['role'],
                    $status
                );
                $stmt->execute();
                $userId = (int) $conn->insert_id;
                $stmt->close();

                if ($old['role'] === 'Student') {
                    $studentId = create_student_profile($conn, $old['fullname'], $old['email']);

                    $stmt = $conn->prepare('UPDATE users SET student_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $studentId, $userId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $professorId = create_professor_profile(
                        $conn,
                        $old['fullname'],
                        $old['email'],
                        $userId,
                        'Pending'
                    );

                    $stmt = $conn->prepare('UPDATE users SET professor_id = ? WHERE id = ?');
                    $stmt->bind_param('si', $professorId, $userId);
                    $stmt->execute();
                    $stmt->close();
                }
            });
        } catch (mysqli_sql_exception $e) {
            error_log('Registration failed: ' . $e->getMessage());

            if (is_unique_violation($e)) {
                $errors[] = 'That username or email address is already registered.';
            } else {
                $errors[] = 'Account creation failed. Please try again.';
            }
        } catch (Throwable $e) {
            error_log('Registration failed: ' . $e->getMessage());
            $errors[] = 'Account creation failed. Please try again.';
        }

        if ($errors === []) {
            if ($old['role'] === 'Professor') {
                flash(
                    'success',
                    'Your professor account was created. An administrator must approve it before you can sign in.'
                );
            } else {
                flash('success', 'Your student account is ready. You can sign in now.');
            }

            redirect('index.php');
        }
    }
}

$pageTitle = 'Create account';
require __DIR__ . '/inc/auth-header.php';
?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="fullname">Full name</label>
        <input type="text" class="form-control" id="fullname" name="fullname"
               value="<?= e($old['fullname']) ?>" maxlength="100"
               autocomplete="name" required autofocus>
    </div>

    <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input type="text" class="form-control" id="username" name="username"
               value="<?= e($old['username']) ?>" maxlength="50"
               autocomplete="username" autocapitalize="none" required>
        <p class="form-hint">Letters, numbers, dots, hyphens, and underscores only.</p>
    </div>

    <div class="mb-3">
        <label class="form-label" for="email">Email address</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= e($old['email']) ?>" maxlength="255"
               autocomplete="email" required>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password"
               autocomplete="new-password" required>
        <p class="form-hint">At least 8 characters with an uppercase letter, a lowercase letter, and a number.</p>
    </div>

    <div class="mb-3">
        <label class="form-label" for="confirm_password">Confirm password</label>
        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
               autocomplete="new-password" required>
    </div>

    <div class="mb-3">
        <label class="form-label" for="role">Account type</label>
        <select class="form-select" id="role" name="role" required>
            <option value="">Select account type</option>
            <option value="Student" <?= selected($old['role'], 'Student') ?>>Student</option>
            <option value="Professor" <?= selected($old['role'], 'Professor') ?>>Professor</option>
        </select>
        <p class="form-hint">Professor accounts require administrator approval.</p>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>
        Create account
    </button>
</form>

<p class="auth-footer">
    Already have an account? <a href="index.php">Sign in</a>
</p>

<?php require __DIR__ . '/inc/auth-footer.php'; ?>
