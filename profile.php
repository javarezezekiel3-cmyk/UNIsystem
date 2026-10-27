<?php
/**
 * Account profile.
 *
 * Lets the signed-in user change their display name, email address, and
 * password. Changing a password requires the current password, which also
 * transparently upgrades legacy MD5 hashes to password_hash().
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

require_login();

$userId = current_user_id();

/** Fetch the signed-in account with its linked profile. */
function load_profile(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, fullname, username, password, role, status, student_id, professor_id, email
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/** Check a password against the stored hash, accepting legacy MD5 hashes. */
function password_matches(string $password, string $stored): bool
{
    if ($stored !== '' && password_verify($password, $stored)) {
        return true;
    }

    return preg_match('/^[a-f0-9]{32}$/i', $stored) === 1
        && hash_equals(strtolower($stored), md5($password));
}

$user = load_profile($conn, $userId);

if ($user === null) {
    destroy_session();
    redirect('index.php');
}

if (is_post()) {
    csrf_verify();

    $action = post_string('action');

    if ($action === 'update_profile') {
        $fullname = post_string('fullname');
        $email = post_string('email');
        $errors = [];

        if ($fullname === '') {
            $errors[] = 'Full name cannot be empty.';
        } elseif (mb_strlen($fullname) > 100) {
            $errors[] = 'Full name cannot be longer than 100 characters.';
        }

        if ($email !== '' && (mb_strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('profile.php');
        }

        $emailValue = $email !== '' ? $email : null;

        try {
            db_transaction($conn, static function () use ($conn, $userId, $user, $fullname, $emailValue): void {
                $stmt = $conn->prepare('UPDATE users SET fullname = ?, email = ? WHERE id = ?');
                $stmt->bind_param('ssi', $fullname, $emailValue, $userId);
                $stmt->execute();
                $stmt->close();

                $studentId = (string) ($user['student_id'] ?? '');
                if ($studentId !== '') {
                    $stmt = $conn->prepare('UPDATE students SET fullname = ?, email = ? WHERE student_id = ?');
                    $stmt->bind_param('sss', $fullname, $emailValue, $studentId);
                    $stmt->execute();
                    $stmt->close();
                }

                $professorId = (string) ($user['professor_id'] ?? '');
                if ($professorId !== '') {
                    $stmt = $conn->prepare('UPDATE professors SET fullname = ?, email = ? WHERE professor_id = ?');
                    $stmt->bind_param('sss', $fullname, $emailValue, $professorId);
                    $stmt->execute();
                    $stmt->close();
                }
            });

            $_SESSION['fullname'] = $fullname;
            flash('success', 'Profile updated successfully.');
        } catch (Throwable $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            flash('danger', 'Unable to update your profile. Please try again.');
        }

        redirect('profile.php');
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $errors = [];

        if ($currentPassword === '') {
            $errors[] = 'Enter your current password.';
        } elseif (!password_matches($currentPassword, (string) $user['password'])) {
            $errors[] = 'Your current password is incorrect.';
        }

        if ($newPassword === '') {
            $errors[] = 'Enter a new password.';
        } else {
            $problem = password_problem($newPassword);
            if ($problem !== null) {
                $errors[] = $problem;
            } elseif ($newPassword === $currentPassword) {
                $errors[] = 'Choose a new password that differs from your current one.';
            }
        }

        if ($confirmPassword === '') {
            $errors[] = 'Confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'The new password and confirmation do not match.';
        }

        if ($errors !== []) {
            flash('danger', implode(' ', $errors));
            redirect('profile.php');
        }

        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $userId);
            $stmt->execute();
            $stmt->close();

            flash('success', 'Password changed successfully.');
        } catch (Throwable $e) {
            error_log('Password change failed: ' . $e->getMessage());
            flash('danger', 'Unable to change your password. Please try again.');
        }

        redirect('profile.php');
    }

    flash('danger', 'Unknown action.');
    redirect('profile.php');
}

$pageTitle = 'My Profile';
$pageSubtitle = 'Account details and security';
$active = 'profile';
require __DIR__ . '/inc/header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-body">
                <div class="profile-head">
                    <span class="profile-avatar" aria-hidden="true">
                        <i class="fa-solid fa-user"></i>
                    </span>
                    <div>
                        <h2 class="h4 mb-1"><?= e($user['fullname']) ?></h2>
                        <p class="text-muted mb-1"><?= e($user['role']) ?></p>
                        <span class="badge text-bg-<?= e(status_badge_class($user['status'])) ?>">
                            <?= e($user['status']) ?>
                        </span>
                    </div>
                </div>

                <dl class="profile-facts">
                    <div>
                        <dt>Username</dt>
                        <dd class="mono"><?= e($user['username']) ?></dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd><?= ($user['email'] ?? '') !== '' ? e($user['email']) : '<span class="text-muted">Not set</span>' ?></dd>
                    </div>
                    <div>
                        <dt>Role</dt>
                        <dd><?= e($user['role']) ?></dd>
                    </div>
                    <?php if (($user['student_id'] ?? '') !== ''): ?>
                        <div>
                            <dt>Student ID</dt>
                            <dd class="mono"><?= e($user['student_id']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (($user['professor_id'] ?? '') !== ''): ?>
                        <div>
                            <dt>Professor ID</dt>
                            <dd class="mono"><?= e($user['professor_id']) ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-body">
                <h2 class="h5 mb-1">Update profile</h2>
                <p class="text-muted">Your name is shared with your linked student or professor record.</p>

                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label" for="profile-fullname">Full name</label>
                        <input type="text" class="form-control" id="profile-fullname" name="fullname"
                               value="<?= e($user['fullname']) ?>" maxlength="100" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="profile-email">Email address</label>
                        <input type="email" class="form-control" id="profile-email" name="email"
                               value="<?= e($user['email'] ?? '') ?>" maxlength="255"
                               placeholder="you@example.com">
                        <p class="form-hint">Optional. Used for account contact and verification.</p>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true"></i>Save profile
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="h5 mb-1">Change password</h2>
                <p class="text-muted">Use at least 8 characters with uppercase, lowercase, and a number.</p>

                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label" for="profile-current">Current password</label>
                        <input type="password" class="form-control" id="profile-current" name="current_password"
                               autocomplete="current-password" required>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="profile-new">New password</label>
                            <input type="password" class="form-control" id="profile-new" name="new_password"
                                   autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="profile-confirm">Confirm new password</label>
                            <input type="password" class="form-control" id="profile-confirm" name="confirm_password"
                                   autocomplete="new-password" minlength="8" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-warning mt-3">
                        <i class="fa-solid fa-key me-1" aria-hidden="true"></i>Change password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
