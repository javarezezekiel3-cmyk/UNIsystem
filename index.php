<?php
/**
 * Sign in.
 *
 * Failed attempts are tracked per username + IP. Five failures lock that
 * pair for fifteen minutes. Legacy MD5 hashes are upgraded on first
 * successful sign-in.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCK_MINUTES = 15;

$errors = [];
$username = '';
$selectedRole = '';

if (is_post()) {
    csrf_verify();

    $username = substr(trim((string) ($_POST['username'] ?? '')), 0, 50);
    $password = (string) ($_POST['password'] ?? '');
    $selectedRole = (string) ($_POST['role'] ?? '');
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    if ($username === '' || $password === '' || !in_list($selectedRole, ['Admin', 'Professor', 'Student'])) {
        $errors[] = 'Enter your username or email, your password, and your account type.';
    } else {
        $stmt = $conn->prepare(
            'SELECT id, attempts, locked_until
             FROM login_attempts
             WHERE username = ? AND ip_address = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->bind_param('ss', $username, $ipAddress);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $lockedUntil = $attempt['locked_until'] ?? null;
        $lockExpires = $lockedUntil ? strtotime((string) $lockedUntil) : null;

        if ($lockExpires !== null && $lockExpires > time()) {
            $minutes = max(1, (int) ceil(($lockExpires - time()) / 60));
            $errors[] = 'Too many failed attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
        } else {
            // An expired lock resets the counter.
            if ($attempt !== null && $lockExpires !== null) {
                $stmt = $conn->prepare('UPDATE login_attempts SET attempts = 0, locked_until = NULL WHERE id = ?');
                $stmt->bind_param('i', $attempt['id']);
                $stmt->execute();
                $stmt->close();
                $attempt['attempts'] = 0;
            }

            $stmt = $conn->prepare(
                'SELECT id, fullname, password, role, status
                 FROM users
                 WHERE (username = ? OR email = ?) AND role = ?
                 LIMIT 1'
            );
            $stmt->bind_param('sss', $username, $username, $selectedRole);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $passwordOk = false;
            $legacyMd5 = false;

            if ($user !== null) {
                $stored = (string) $user['password'];

                if (password_verify($password, $stored)) {
                    $passwordOk = true;
                } elseif (preg_match('/^[a-f0-9]{32}$/i', $stored)
                    && hash_equals(strtolower($stored), md5($password))) {
                    $passwordOk = true;
                    $legacyMd5 = true;
                }
            }

            if ($passwordOk && $user !== null) {
                if ($user['status'] === 'Pending') {
                    $errors[] = 'Your account is waiting for administrator approval. Please try again later.';
                } elseif ($user['status'] !== 'Active') {
                    $errors[] = 'This account is not active. Please contact the administrator.';
                } else {
                    if ($legacyMd5) {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                        $stmt->bind_param('si', $newHash, $user['id']);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $stmt = $conn->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?');
                    $stmt->bind_param('ss', $username, $ipAddress);
                    $stmt->execute();
                    $stmt->close();

                    login_user($user);
                    flash('success', 'Welcome back, ' . $user['fullname'] . '.');
                    redirect('dashboard.php');
                }
            } else {
                $attempts = (int) ($attempt['attempts'] ?? 0) + 1;
                $lockedUntilValue = $attempts >= MAX_LOGIN_ATTEMPTS
                    ? date('Y-m-d H:i:s', time() + (LOGIN_LOCK_MINUTES * 60))
                    : null;

                if ($attempt !== null) {
                    $stmt = $conn->prepare(
                        'UPDATE login_attempts
                         SET attempts = ?, last_attempt = NOW(), locked_until = ?
                         WHERE id = ?'
                    );
                    $stmt->bind_param('isi', $attempts, $lockedUntilValue, $attempt['id']);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO login_attempts (username, ip_address, attempts, last_attempt, locked_until)
                         VALUES (?, ?, ?, NOW(), ?)'
                    );
                    $stmt->bind_param('ssis', $username, $ipAddress, $attempts, $lockedUntilValue);
                    $stmt->execute();
                    $stmt->close();
                }

                $errors[] = $attempts >= MAX_LOGIN_ATTEMPTS
                    ? 'Too many failed attempts. This account and device are locked for ' . LOGIN_LOCK_MINUTES . ' minutes.'
                    : 'Invalid username, password, or account type.';
            }
        }
    }
}

$pageTitle = 'Sign in';
require __DIR__ . '/inc/auth-header.php';
?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endforeach; ?>

<form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="mb-3">
        <label class="form-label" for="role">Account type</label>
        <select class="form-select" id="role" name="role" required>
            <option value="">Select account type</option>
            <option value="Admin" <?= selected($selectedRole, 'Admin') ?>>Administrator</option>
            <option value="Professor" <?= selected($selectedRole, 'Professor') ?>>Professor</option>
            <option value="Student" <?= selected($selectedRole, 'Student') ?>>Student</option>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label" for="username">Username or email</label>
        <input type="text" class="form-control" id="username" name="username"
               value="<?= e($username) ?>" maxlength="50"
               autocomplete="username" autocapitalize="none" required autofocus>
    </div>

    <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input type="password" class="form-control" id="password" name="password"
               autocomplete="current-password" required>
    </div>

    <button type="submit" class="btn btn-primary w-100">
        <i class="fa-solid fa-right-to-bracket me-1" aria-hidden="true"></i>
        Sign in
    </button>
</form>

<p class="auth-footer">
    New here? <a href="register.php">Create an account</a>
</p>

<?php require __DIR__ . '/inc/auth-footer.php'; ?>
