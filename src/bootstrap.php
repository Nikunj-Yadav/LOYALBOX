<?php
/**
 * Loaded first by every page. A page must define AREA ('customer' or 'admin') before including this file.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
if (!defined('ASSETS')) { define('ASSETS', '../assets/'); }                 // relative path from public/customer and public/admin

require ROOT . '/src/config.php';
require ROOT . '/src/helpers.php';
require ROOT . '/src/db.php';
require ROOT . '/src/security.php';
require ROOT . '/src/services.php';
require ROOT . '/src/layout.php';

date_default_timezone_set(env('APP_TIMEZONE', 'UTC') ?: 'UTC');
ini_set('display_errors', '0');                  // never show raw PHP/SQL errors to visitors
error_reporting(E_ALL);

// Any uncaught problem becomes a friendly page; details go to the server log only.
set_exception_handler(function (Throwable $e): void {
    error_log('[LoyalBox] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    $notReady = $e instanceof PDOException && (in_array((int)$e->getCode(), [1044, 1045, 1049, 2002], true) || (int)($e->errorInfo[1] ?? 0) === 1146);
    if ($notReady) {
        render_error_page('The database is not ready', 'Start MySQL, import database.sql in phpMyAdmin, then open check.php (in the public folder) to see exactly what is missing.');
    }
    render_error_page('Something went wrong', 'We could not complete that request. Please try again in a moment.');
});

send_security_headers();
start_secure_session(AREA);
csrf_verify();                                   // every POST must carry a valid token
