<?php
/**
 * Application entry point shared by every page.
 *
 * Loads configuration, starts the session, connects to the database and
 * refreshes the signed-in user's cached role/name. Pages should require this
 * file first and then call require_login()/require_role().
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$conn = db();

if (isset($_SESSION['user_id'])) {
    refresh_identity($conn);
}
