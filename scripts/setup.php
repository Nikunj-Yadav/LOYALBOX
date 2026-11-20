<?php
/**
 * CLI setup:  php scripts/setup.php [--schema] [--admin=email] [--demo]
 *   --schema        import database.sql (creates the database and any missing tables; keeps existing data)
 *   --admin=EMAIL   create an admin; the password is read from ADMIN_PASSWORD env var or prompted
 *   --demo          add sample products/rewards and one demo customer (clearly labelled demo data)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
define('ROOT', dirname(__DIR__));
require ROOT . '/src/config.php';
require ROOT . '/src/helpers.php';
require ROOT . '/src/db.php';
date_default_timezone_set(env('APP_TIMEZONE', 'UTC') ?: 'UTC');

$opts = getopt('', ['schema', 'admin::', 'demo']);
if (!$opts) { exit("Usage: php scripts/setup.php [--schema] [--admin=email] [--demo]\n"); }

if (isset($opts['schema'])) {
    db()->exec((string)preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', (string)file_get_contents(ROOT . '/database.sql')));
    echo "Tables are ready.\n";
}
if (isset($opts['admin'])) {
    $email = mb_strtolower(trim((string)$opts['admin']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { exit("Invalid admin e-mail.\n"); }
    $pw = getenv('ADMIN_PASSWORD') ?: '';
    if ($pw === '') { echo 'Admin password (min 10 chars): '; $pw = trim((string)fgets(STDIN)); }
    if ($err = password_error($pw)) { exit("Password rejected: $err\n"); }
    q('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)', ['Factory Admin', $email, password_hash($pw, PASSWORD_DEFAULT)]);
    echo "Admin $email created.\n";
}
if (isset($opts['demo'])) {
    q("INSERT INTO products (name, sku, description, points, is_active) VALUES
       ('Demo Chocolate Box', 'DEMO-CHOC', 'Demo data', 50, 1), ('Demo Tea Selection', 'DEMO-TEA', 'Demo data', 30, 1)");
    q("INSERT INTO rewards (name, description, points_cost, stock, is_active) VALUES
       ('Demo Tote Bag', 'Demo data', 100, 25, 1), ('Demo Gift Hamper', 'Demo data', 400, 5, 1)");
    q('INSERT INTO users (name, email, password_hash) VALUES (?,?,?)',
      ['Demo Customer', 'demo@example.com', password_hash('demo-password-123', PASSWORD_DEFAULT)]);
    echo "Demo data added (customer demo@example.com / demo-password-123). Remove before going live.\n";
}
