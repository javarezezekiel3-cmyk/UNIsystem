<?php
/**
 * Sign out. Only POST with a valid CSRF token ends the session; a GET
 * request shows a confirmation page instead so a link cannot sign you out.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

if (is_post()) {
    csrf_verify();
    destroy_session();
    session_start();
    flash('success', 'You have been signed out.');
    redirect('index.php');
}

$pageTitle = 'Sign out';
require __DIR__ . '/inc/auth-header.php';
?>

<p class="mb-4">Sign out of UNIsystem? Your session will end on this device.</p>

<form method="post">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-danger w-100">
        <i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i>
        Sign out
    </button>
</form>

<p class="auth-footer">
    <a href="dashboard.php">Return to dashboard</a>
</p>

<?php require __DIR__ . '/inc/auth-footer.php'; ?>
