<?php
// Shared setup for tests: points the app at a separate test database, never the real one.
declare(strict_types=1);
define('ROOT', dirname(__DIR__));
putenv('DB_NAME=loyalbox_test');
putenv('CODE_PEPPER=' . str_repeat('ab12', 16));
require ROOT . '/src/config.php';
require ROOT . '/src/helpers.php';
require ROOT . '/src/db.php';
require ROOT . '/src/security.php';
require ROOT . '/src/services.php';
require ROOT . '/src/layout.php';
define('AREA', 'customer');
date_default_timezone_set('UTC');

/** Load database.sql into the test database (the CREATE DATABASE / USE header is skipped: tests choose the DB). */
function install_schema(): void
{
    $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', (string)file_get_contents(ROOT . '/database.sql'));
    db()->exec($sql);
}
