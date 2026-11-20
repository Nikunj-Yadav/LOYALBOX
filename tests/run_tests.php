<?php
/**
 * Automated business-rule tests.   Run:  php tests/run_tests.php
 * Needs a MySQL/MariaDB user that may create the database `loyalbox_test` (see README, "Running tests").
 * It wipes and recreates ONLY loyalbox_test. Results are written to tests/RESULTS.md.
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap_test.php';

// Create the test database using the credentials from .env (needs CREATE privilege) or TEST_ADMIN_* env vars.
$adminDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'));
$root = new PDO($adminDsn, getenv('TEST_ADMIN_USER') ?: env('DB_USER'), getenv('TEST_ADMIN_PASS') ?: env('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS loyalbox_test');
$root->exec('CREATE DATABASE loyalbox_test CHARACTER SET utf8mb4');
install_schema();

$results = []; $failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failed;
    $results[] = [$name, $ok, $detail];
    if (!$ok) { $failed++; }
    echo ($ok ? '  PASS ' : '  FAIL ') . $name . ($ok || !$detail ? '' : "  -> $detail") . "\n";
}
function newUser(string $n, int $points = 0): int
{
    q('INSERT INTO users (name,email,password_hash,points_balance) VALUES (?,?,?,?)', [$n, "$n@test.local", 'x', $points]);
    return (int)db()->lastInsertId();
}
function ip(string $ip): void { $_SERVER['REMOTE_ADDR'] = $ip; }
function balance(int $u): int { return (int)scalar('SELECT points_balance FROM users WHERE id=?', [$u]); }
function ledgerSum(int $u): int { return (int)scalar('SELECT COALESCE(SUM(points),0) FROM point_transactions WHERE user_id=?', [$u]); }

q("INSERT INTO admins (name,email,password_hash) VALUES ('Test Admin','a@test.local','x')");
q("INSERT INTO products (name,sku,points,is_active) VALUES ('Choc','CHOC',50,1),('Tea','TEA',30,1)");
$adminId = 1; $choc = 1; $tea = 2;

echo "\n== Codes ==\n";
$s = array_flip(str_split(CODE_ALPHABET));
$c1 = generate_code(); $c2 = generate_code();
check('Generated code has 16 chars from the safe alphabet', strlen($c1) === 16 && strspn($c1, CODE_ALPHABET) === 16);
check('Two generated codes differ', $c1 !== $c2);
check('normalize accepts lowercase + dashes + spaces', normalize_code(strtolower(format_code($c1)) . ' ') === $c1);
check('normalize maps O/I/L to 0/1/1', normalize_code('OOOO-IIII-LLLL-0000') === '0000111111110000');
check('normalize rejects wrong length', normalize_code('ABC') === null);
check('normalize rejects bad characters', normalize_code('UUUU-UUUU-UUUU-UUUU') === null);
check('normalize rejects SQL-looking input', normalize_code("' OR 1=1 --") === null);

ip('10.0.0.1');
$batch = create_code_batch($choc, 40, 'test', $adminId);
$codes = $batch['codes'];
check('Batch creates requested number of codes', count($codes) === 40 && (int)scalar('SELECT COUNT(*) FROM product_codes') === 40);
check('All codes unique', count(array_unique($codes)) === 40);
$leak = 0; foreach ($codes as $c) {
    $leak += (int)scalar("SELECT COUNT(*) FROM product_codes WHERE code_hash = ? OR code_hash LIKE ?", [$c, "%$c%"]);
}
check('Plaintext codes are NOT stored (hash column holds no plaintext)', $leak === 0);
$row = fetch_one('SELECT code_hash, code_hint FROM product_codes ORDER BY id LIMIT 1');
check('Stored value is a 64-char HMAC and hint is last 4 chars', strlen($row['code_hash']) === 64 && $row['code_hint'] === substr($codes[0], -4));
check('Hash is keyed (differs from plain SHA-256)', code_hash($codes[0]) !== hash('sha256', $codes[0]));
$dump = json_encode(fetch_all('SELECT * FROM product_codes'));
check('No full code appears anywhere in the table dump', !str_contains($dump, $codes[0]) && !str_contains($dump, $codes[5]));

echo "\n== Secret key (pepper) ==\n";
$tmp = sys_get_temp_dir() . '/lb_pepper_' . bin2hex(random_bytes(4)) . '/secret.key';
$k1 = code_pepper($tmp); $k2 = code_pepper($tmp);
check('A secret key file is created automatically (64 hex chars)', is_file($tmp) && preg_match('/^[0-9a-f]{64}$/', $k1) === 1);
check('The same key is returned on later calls (not regenerated)', $k1 === $k2 && trim((string)file_get_contents($tmp)) === $k1);
$tmp2 = sys_get_temp_dir() . '/lb_pepper_' . bin2hex(random_bytes(4)) . '/secret.key';
$startAt = microtime(true) + 1.2; $ps = [];
for ($i = 0; $i < 8; $i++) { $ps[] = proc_open([PHP_BINARY, __DIR__ . '/worker.php', 'pepper', '0', $tmp2, (string)$startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp); $pipesAll[] = [$ps[$i], $pp]; }
$vals = []; foreach ($pipesAll as [$pr, $pp]) { $vals[] = (json_decode(stream_get_contents($pp[1]), true)['value'] ?? 'ERR'); proc_close($pr); }
check('8 processes creating the key at the same moment all get the SAME key', count(array_unique($vals)) === 1 && strlen($vals[0]) === 64, json_encode(array_unique($vals)));

echo "\n== database.sql ==\n";
$before = (int)scalar('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
q("INSERT INTO users (name,email,password_hash) VALUES ('Keep Me','keep@test.local','x')");
install_schema();    // importing a second time must not destroy anything
check('Importing database.sql twice is safe: 11 tables, data kept', $before === 11 && (int)scalar("SELECT COUNT(*) FROM users WHERE email='keep@test.local'") === 1);
q("DELETE FROM users WHERE email='keep@test.local'");
check('database.sql contains no DROP statements', stripos((string)file_get_contents(ROOT . '/database.sql'), 'DROP ') === false);

echo "\n== Claiming ==\n";
$alice = newUser('alice'); $bob = newUser('bob');
$r = claim_code($alice, format_code($codes[0]));
check('Valid claim succeeds and returns product points', $r['ok'] && $r['points'] === 50, json_encode($r));
check('Balance increased by 50', balance($alice) === 50);
check('Ledger row written (type claim, +50, balance_after 50)', (int)scalar("SELECT COUNT(*) FROM point_transactions WHERE user_id=? AND type='claim' AND points=50 AND balance_after=50", [$alice]) === 1);
check('Code marked claimed with owner', fetch_one('SELECT status, claimed_by FROM product_codes WHERE id=1')['status'] === 'claimed');
$r = claim_code($alice, $codes[0]);
check('Same user cannot claim same code twice', !$r['ok'] && balance($alice) === 50, json_encode($r));
$r = claim_code($bob, $codes[0]);
check('Other user cannot claim an already-used code', !$r['ok'] && balance($bob) === 0);
check('Exactly one claim row per code', (int)scalar('SELECT COUNT(*) FROM claims WHERE code_id=1') === 1);

set_code_active(2, false);
$r = claim_code($alice, $codes[1]);
check('Deactivated code cannot be claimed', !$r['ok'] && balance($alice) === 50, json_encode($r));
set_code_active(2, true);
$r = claim_code($alice, $codes[1]);
check('Re-activated code can be claimed', $r['ok'] && balance($alice) === 100);
check('A claimed code cannot be deactivated', set_code_active(1, false) === false);

q('UPDATE products SET is_active=0 WHERE id=?', [$choc]);
$r = claim_code($alice, $codes[2]);
check('Code of an inactive product cannot be claimed', !$r['ok'] && balance($alice) === 100);
q('UPDATE products SET is_active=1 WHERE id=?', [$choc]);

q("UPDATE users SET status='blocked' WHERE id=?", [$bob]);
$r = claim_code($bob, $codes[3]);
check('Blocked customer cannot claim', !$r['ok'] && balance($bob) === 0);
q("UPDATE users SET status='active' WHERE id=?", [$bob]);

$n = set_batch_active($batch['batch_id'], false);
check('Batch deactivate affects only unclaimed codes (38 of 40)', $n === 38, "changed $n");
$r = claim_code($bob, $codes[10]);
check('Claim fails after batch deactivation', !$r['ok']);
set_batch_active($batch['batch_id'], true);

echo "\n== Rate limiting & failure logging ==\n";
$carol = newUser('carol'); ip('10.0.0.50');
claim_code($carol, 'ZZZZ-ZZZZ-ZZZZ-ZZZZ');       // unknown code
claim_code($carol, 'nonsense');                    // malformed
claim_code($carol, $codes[0]);                     // already used
check('Failures are recorded as security events', (int)scalar("SELECT COUNT(*) FROM security_events WHERE user_id=? AND event_type='claim_failed'", [$carol]) === 3);
claim_code($carol, 'AAAA-AAAA-AAAA-AAAA'); claim_code($carol, 'BBBB-BBBB-BBBB-BBBB');
$r = claim_code($carol, $codes[20]);
check('After 5 failures even a VALID code is refused (rate limit)', !$r['ok'] && str_contains($r['error'], 'Too many'), json_encode($r));
check('The refused valid code stays unclaimed', scalar('SELECT status FROM product_codes WHERE code_hash=?', [code_hash($codes[20])]) === 'active');
check('Rate-limit block is logged', (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type='claim_rate_limited' AND user_id=?", [$carol]) >= 1);
$dave = newUser('dave'); ip('10.0.0.51');
$r = claim_code($dave, $codes[20]);
check('A different user on a different IP is unaffected', $r['ok']);

echo "\n== Rewards ==\n";
q("INSERT INTO rewards (name,points_cost,stock,is_active) VALUES ('Bag',100,2,1),('Hamper',400,1,1),('Hidden',10,5,0),('Empty',10,0,1)");
$eve = newUser('eve', 250);
$r = redeem_reward($eve, 1);
check('Redeem with enough points succeeds', $r['ok'] && balance($eve) === 150, json_encode($r));
check('Stock decremented', (int)scalar('SELECT stock FROM rewards WHERE id=1') === 1);
check('Ledger shows -100 with balance_after 150', (int)scalar("SELECT COUNT(*) FROM point_transactions WHERE user_id=? AND type='redeem' AND points=-100 AND balance_after=150", [$eve]) === 1);
$r = redeem_reward($eve, 2);
check('Insufficient points refused, balance unchanged', !$r['ok'] && balance($eve) === 150 && str_contains($r['error'], '250'), json_encode($r));
check('Inactive reward refused', !redeem_reward($eve, 3)['ok']);
check('Out-of-stock reward refused', !redeem_reward($eve, 4)['ok']);
check('Unknown reward refused', !redeem_reward($eve, 999)['ok']);
$red = (int)scalar('SELECT id FROM redemptions WHERE user_id=?', [$eve]);
$r = update_redemption($red, 'cancelled', $adminId);
check('Cancel refunds points and restores stock', $r['ok'] && balance($eve) === 250 && (int)scalar('SELECT stock FROM rewards WHERE id=1') === 2, json_encode($r));
check('Refund appears in ledger', (int)scalar("SELECT COUNT(*) FROM point_transactions WHERE user_id=? AND type='refund' AND points=100", [$eve]) === 1);
check('Cancelling twice is refused (no double refund)', !update_redemption($red, 'cancelled', $adminId)['ok'] && balance($eve) === 250);
redeem_reward($eve, 1); $red2 = (int)scalar('SELECT MAX(id) FROM redemptions');
check('Fulfil works on pending', update_redemption($red2, 'fulfilled', $adminId)['ok']);
check('Fulfilled redemption cannot be cancelled', !update_redemption($red2, 'cancelled', $adminId)['ok']);

echo "\n== Database-level guards ==\n";
function dbRejects(string $sql, array $p = []): bool { try { q($sql, $p); return false; } catch (PDOException $e) { return true; } }
check('DB rejects second claim row for the same code (UNIQUE)', dbRejects('INSERT INTO claims (user_id,code_id,product_id,points_awarded) VALUES (?,?,?,?)', [$bob, 1, $choc, 50]));
check('DB rejects duplicate ledger event (UNIQUE type+ref)', dbRejects("INSERT INTO point_transactions (user_id,type,points,balance_after,ref_type,ref_id) VALUES (?, 'claim', 50, 50, 'claim', 1)", [$bob]));
check('DB rejects a negative balance (UNSIGNED)', dbRejects('UPDATE users SET points_balance = points_balance - 100000 WHERE id=?', [$bob]));
check('DB rejects duplicate code hash', dbRejects("INSERT INTO product_codes (batch_id,product_id,code_hash,code_hint) SELECT batch_id,product_id,code_hash,code_hint FROM product_codes LIMIT 1"));
check('DB rejects duplicate e-mail', dbRejects("INSERT INTO users (name,email,password_hash) VALUES ('x','alice@test.local','x')"));

echo "\n== Concurrency (real parallel PHP processes) ==\n";
function runParallel(array $jobs): array
{
    $startAt = microtime(true) + 1.5; $procs = [];
    foreach ($jobs as $i => [$kind, $uid, $arg]) {
        $p = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $kind, (string)$uid, (string)$arg, (string)$startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[$i] = [$p, $pipes];
    }
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) { $out[$i] = json_decode(stream_get_contents($pipes[1]) ?: '{}', true) ?: ['ok' => false, 'error' => 'worker crashed: ' . stream_get_contents($pipes[2])]; proc_close($p); }
    return $out;
}
$raceCode = $codes[30]; $users = []; for ($i = 0; $i < 12; $i++) { $users[] = newUser("racer$i"); }
$res = runParallel(array_map(fn($u) => ['claim', $u, $raceCode], $users));
$wins = count(array_filter($res, fn($r) => !empty($r['ok'])));
$total = (int)scalar('SELECT COALESCE(SUM(balance),0) FROM (SELECT points_balance balance FROM users WHERE name LIKE "racer%") t');
check('12 simultaneous claims of ONE code: exactly 1 succeeds', $wins === 1, "wins=$wins " . json_encode(array_slice($res, 0, 3)));
check('Only 50 points were awarded in total', $total === 50, "total=$total");
check('Exactly one claim row and one ledger row for that code', (int)scalar('SELECT COUNT(*) FROM claims WHERE code_id=(SELECT id FROM product_codes WHERE code_hash=?)', [code_hash($raceCode)]) === 1);

$same = newUser('multi'); $multi = array_slice($codes, 31, 8);
$res = runParallel(array_map(fn($c) => ['claim', $same, $c], $multi));
check('8 simultaneous claims of 8 DIFFERENT codes by one user: all succeed', count(array_filter($res, fn($r) => !empty($r['ok']))) === 8, json_encode($res));
check('Balance is exactly 8 x 50 = 400 (no lost updates)', balance($same) === 400 && ledgerSum($same) === 400, 'balance=' . balance($same));

q("INSERT INTO rewards (name,points_cost,stock,is_active) VALUES ('Race prize',100,10,1)"); $prize = (int)db()->lastInsertId();
$rich = newUser('rich', 100);
$res = runParallel(array_fill(0, 8, ['redeem', $rich, $prize]));
check('8 simultaneous redemptions with points for ONE: exactly 1 succeeds', count(array_filter($res, fn($r) => !empty($r['ok']))) === 1, json_encode($res));
check('Balance ends at 0 (never negative)', balance($rich) === 0);
check('Stock dropped by exactly 1', (int)scalar('SELECT stock FROM rewards WHERE id=?', [$prize]) === 9);
q("INSERT INTO rewards (name,points_cost,stock,is_active) VALUES ('Last one',10,1,1)"); $last = (int)db()->lastInsertId();
$buyers = []; for ($i = 0; $i < 6; $i++) { $buyers[] = newUser("buyer$i", 10); }
$res = runParallel(array_map(fn($u) => ['redeem', $u, $last], $buyers));
check('6 customers racing for the last item: exactly 1 gets it', count(array_filter($res, fn($r) => !empty($r['ok']))) === 1, json_encode($res));
check('Stock is 0, not negative', (int)scalar('SELECT stock FROM rewards WHERE id=?', [$last]) === 0);

echo "\n== Session timeout ==\n";
$sess = runParallel([['admin_session', 0, 'none'], ['admin_session', 0, '60'], ['admin_session', 0, '4000']]);
check('A brand-new session (no timestamp yet) is NOT treated as expired', $sess[0]['ok'] === true, json_encode($sess[0]));
check('An admin session active 60 s ago is still valid', $sess[1]['ok'] === true);
check('An admin session idle for 4000 s (> 30 min) is rejected', $sess[2]['ok'] === false);

echo "\n== Ledger integrity ==\n";
$bad = fetch_all('SELECT u.id, u.points_balance, COALESCE(SUM(t.points),0) s FROM users u LEFT JOIN point_transactions t ON t.user_id=u.id GROUP BY u.id HAVING u.points_balance <> s AND u.id NOT IN (SELECT id FROM users WHERE name IN ("eve","rich") OR name LIKE "buyer%")');
check('For every claim-only customer, balance == SUM(ledger)', count($bad) === 0, json_encode($bad));
check('Seeded customers: balance == opening balance + ledger', balance($eve) === 250 + ledgerSum($eve) && balance($rich) === 100 + ledgerSum($rich) );

echo "\n" . ($failed ? "$failed TEST(S) FAILED" : 'ALL ' . count($results) . ' TESTS PASSED') . "\n";
$md = "# Automated test results\n\nGenerated by `php tests/run_tests.php` on " . date('Y-m-d H:i') . " UTC (PHP " . PHP_VERSION . ", " . db()->query('SELECT VERSION()')->fetchColumn() . ").\n\n| Result | Test |\n|---|---|\n";
foreach ($results as [$n, $ok, $d]) { $md .= '| ' . ($ok ? 'PASS' : '**FAIL**') . ' | ' . $n . ($ok || !$d ? '' : ' — ' . str_replace('|', '/', $d)) . " |\n"; }
$md .= "\n**" . (count($results) - $failed) . ' of ' . count($results) . " passed.**\n";
file_put_contents(__DIR__ . '/RESULTS.md', $md);
exit($failed ? 1 : 0);
