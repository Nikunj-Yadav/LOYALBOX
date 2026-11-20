<?php
// Child process used by the concurrency tests: waits for a start time, then performs one action.
declare(strict_types=1);
require __DIR__ . '/bootstrap_test.php';
[, $kind, $userId, $arg, $startAt] = $argv;
$_SERVER['REMOTE_ADDR'] = '10.9.' . random_int(1, 250) . '.' . random_int(1, 250);   // distinct IPs so the IP limiter is not what we test
while (microtime(true) < (float)$startAt) { usleep(200); }
$r = $kind === 'claim' ? claim_code((int)$userId, $arg) : redeem_reward((int)$userId, (int)$arg);
echo json_encode($r);
