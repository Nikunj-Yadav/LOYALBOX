<?php
declare(strict_types=1);

/* ---------- Session, headers, CSRF ---------- */

function start_secure_session(string $area): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('lb_' . $area);                       // customers and admins never share a session
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; "
         . "font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_rotate(): void { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }

/** Called automatically for every POST request (see bootstrap.php). */
function csrf_verify(): void
{
    if (!is_post()) { return; }
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        render_error_page('Session expired', 'Your form session is no longer valid. Go back, reload the page and try again.');
    }
}

/* ---------- Logging ---------- */

function log_activity(string $actorType, ?int $actorId, string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void
{
    try {
        q('INSERT INTO activity_logs (actor_type, actor_id, action, entity, entity_id, detail, ip) VALUES (?,?,?,?,?,?,?)',
          [$actorType, $actorId, $action, $entity, $entityId, $detail !== null ? mb_substr($detail, 0, 500) : null, client_ip()]);
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());
    }
}

function security_event(string $type, ?int $userId = null, ?string $identifier = null, ?string $detail = null): void
{
    try {
        q('INSERT INTO security_events (event_type, user_id, identifier, ip, detail) VALUES (?,?,?,?,?)',
          [$type, $userId, $identifier !== null ? mb_substr($identifier, 0, 190) : null, client_ip(), $detail !== null ? mb_substr($detail, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('security_event failed: ' . $e->getMessage());
    }
}

/**
 * Rate limiting: have there been at least $max events of $type for this user/ip/identifier
 * in the last $minutes minutes?  $field is a fixed whitelist, never user input.
 */
function too_many(string $type, string $field, string $value, int $max, int $minutes): bool
{
    if (!in_array($field, ['user_id', 'ip', 'identifier'], true)) {
        throw new InvalidArgumentException('Bad rate-limit field');
    }
    $n = (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type = ? AND $field = ? AND created_at > (NOW() - INTERVAL $minutes MINUTE)",
                     [$type, $value]);
    return $n >= $max;
}

/* ---------- Authentication ---------- */

/** Currently signed-in customer row, or null. Blocked users are signed out. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    $id = $_SESSION['user_id'] ?? null;
    $user = $id ? fetch_one('SELECT id, name, email, phone, points_balance, status, created_at FROM users WHERE id = ?', [$id]) : null;
    if ($user && $user['status'] !== 'active') { $user = null; }
    if (!$user && $id) { unset($_SESSION['user_id']); }
    if ($user && isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > 7200) {   // 2 h idle (a brand-new session has no timestamp yet)
        session_unset(); $user = null;
    }
    $_SESSION['last_seen'] = time();
    return $cache = $user;
}

function require_customer(): array
{
    $u = current_user();
    if (!$u) { redirect('login.php'); }
    return $u;
}

function current_admin(): ?array
{
    static $cache = false;
    if ($cache !== false) { return $cache; }
    $id = $_SESSION['admin_id'] ?? null;
    $admin = $id ? fetch_one('SELECT id, name, email FROM admins WHERE id = ?', [$id]) : null;
    if ($admin && isset($_SESSION['last_seen']) && (time() - (int)$_SESSION['last_seen']) > 1800) {  // 30 min idle (a brand-new session has no timestamp yet)
        session_unset(); $admin = null;
    }
    if (!$admin && $id) { unset($_SESSION['admin_id']); }
    $_SESSION['last_seen'] = time();
    return $cache = $admin;
}

function require_admin(): array
{
    $a = current_admin();
    if (!$a) { redirect('login.php'); }
    return $a;
}

/** A real bcrypt hash of a random string, used so unknown e-mails cost the same time as wrong passwords. */
function dummy_hash(): string
{
    static $h = null;
    return $h ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
}

/**
 * Check credentials against $table ('users' or 'admins') with rate limiting.
 * Returns ['ok'=>true,'row'=>...] or ['ok'=>false,'error'=>...].
 */
function check_login(string $table, string $email, string $password): array
{
    $event = $table === 'admins' ? 'admin_login_failed' : 'login_failed';
    $email = mb_strtolower(trim($email));
    if (too_many($event, 'identifier', $email, 5, 15) || too_many($event, 'ip', client_ip(), 20, 15)) {
        security_event('login_rate_limited', null, $email, $table);
        return ['ok' => false, 'error' => 'Too many failed sign-in attempts. Wait 15 minutes and try again.'];
    }
    $row = fetch_one("SELECT * FROM $table WHERE email = ?", [$email]);   // $table is a fixed literal from our own code
    $valid = password_verify($password, $row['password_hash'] ?? dummy_hash()) && $row !== null;
    if (!$valid) {
        security_event($event, null, $email, 'Wrong e-mail or password');
        return ['ok' => false, 'error' => 'E-mail or password is incorrect.'];
    }
    if ($table === 'users' && $row['status'] !== 'active') {
        return ['ok' => false, 'error' => 'This account is suspended. Contact the factory support team.'];
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        q("UPDATE $table SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $row['id']]);
    }
    q("UPDATE $table SET last_login_at = NOW() WHERE id = ?", [$row['id']]);
    session_regenerate_id(true);       // defeats session fixation
    return ['ok' => true, 'row' => $row];
}
