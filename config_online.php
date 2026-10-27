<?php
/**
 * Legacy database shim.
 *
 * Older copies of the project included config_online.php directly. It used to
 * contain a hosted database name, user, and password in plain text. Those
 * credentials are intentionally gone: credentials now come from
 * inc/db.local.php, environment variables, or the local XAMPP defaults (see
 * inc/db.php). Keep this file only so old includes resolve to the same,
 * hardened connection as config.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$conn = db();
