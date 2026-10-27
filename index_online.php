<?php
/**
 * Legacy entry point.
 *
 * The online login page duplicated index.php and pointed at a hosted database.
 * Everything now lives in index.php and resolves its credentials from the
 * environment, so this file exists only to keep old links/bookmarks working.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

redirect('index.php');
