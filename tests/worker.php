<?php
// Child process used by the concurrency tests: waits for a start time, then performs one action.
declare(strict_types=1);
require __DIR__ . '/bootstrap_test.php';
[, $kind, $userId, $arg, $startAt] = $argv;
$_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(1, 250) . '.' . random_int(1, 250);   // distinct IPs so the IP limiter is not what we test
while (microtime(true) < (float)$startAt) { usleep(200); }
$r = match ($kind) { 'claim' => claim_code((int)$userId, $arg), 'redeem' => redeem_reward((int)$userId, (int)$arg), 'pepper' => ['ok' => true, 'value' => code_pepper($arg)],
    // session timeout: $arg = "none" (brand-new session) or seconds since last activity
    'admin_session' => (function () use ($arg) { $_SESSION = ['admin_id' => 1]; if ($arg !== 'none') { $_SESSION['last_seen'] = time() - (int)$arg; } return ['ok' => current_admin() !== null]; })() };
echo json_encode($r);
