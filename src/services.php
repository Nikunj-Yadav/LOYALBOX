<?php
declare(strict_types=1);

/* =====================================================================
 * Product codes: generation, hashing, claiming
 * ===================================================================== */

const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';   // 32 symbols, no I L O U
const CODE_LENGTH   = 16;                                    // 16 x 5 bits = 80 bits of randomness

/** Clean user input into canonical form, or null if it cannot be a valid code. */
function normalize_code(string $raw): ?string
{
    $c = strtoupper((string)preg_replace('/[\s-]+/', '', $raw));
    $c = strtr($c, ['O' => '0', 'I' => '1', 'L' => '1']);      // forgive common misreads
    if (strlen($c) !== CODE_LENGTH || strspn($c, CODE_ALPHABET) !== CODE_LENGTH) {
        return null;
    }
    return $c;
}

/** Keyed hash stored in the database. Fails closed if the secret is missing. */
function code_hash(string $normalized): string
{
    $pepper = env('CODE_PEPPER');
    if ($pepper === null || strlen($pepper) < 32 || str_starts_with($pepper, 'change_me')) {
        throw new RuntimeException('CODE_PEPPER is missing, too short or still the placeholder.');
    }
    return hash_hmac('sha256', $normalized, $pepper);
}

function generate_code(): string
{
    $s = '';
    for ($i = 0; $i < CODE_LENGTH; $i++) {
        $s .= CODE_ALPHABET[random_int(0, 31)];      // CSPRNG, unbiased because 32 divides 2^n
    }
    return $s;
}
function format_code(string $n): string { return implode('-', str_split($n, 4)); }

/** Base URL for the claim link printed next to each code. */
function claim_url(string $normalized): string
{
    $base = env('APP_URL');
    if (!$base) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/customer';
    }
    return rtrim($base, '/') . '/claim.php?code=' . $normalized;
}

/**
 * Create a batch of unique codes for a product. Returns the plaintext codes.
 * They exist in memory only here: the database receives hashes.
 */
function create_code_batch(int $productId, int $quantity, string $note, int $adminId): array
{
    return transaction(function () use ($productId, $quantity, $note, $adminId) {
        $product = fetch_one('SELECT id FROM products WHERE id = ?', [$productId]);
        if (!$product) { throw new BusinessException('Product not found.'); }
        q('INSERT INTO product_batches (product_id, quantity, note, created_by) VALUES (?,?,?,?)', [$productId, $quantity, $note ?: null, $adminId]);
        $batchId = (int)db()->lastInsertId();
        $ins = db()->prepare('INSERT INTO product_codes (batch_id, product_id, code_hash, code_hint) VALUES (?,?,?,?)');
        $codes = [];
        while (count($codes) < $quantity) {
            $code = generate_code();
            try {
                $ins->execute([$batchId, $productId, code_hash($code), substr($code, -4)]);
                $codes[] = $code;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }   // duplicate hash (astronomically rare): just draw again
            }
        }
        return ['batch_id' => $batchId, 'codes' => $codes];
    });
}

/**
 * Claim a code for a customer.
 *
 * Safety layers against double awards:
 *   1. SELECT ... FOR UPDATE locks the code row, so concurrent claims queue up.
 *   2. UPDATE ... WHERE status='active' must affect exactly one row.
 *   3. UNIQUE(claims.code_id) and UNIQUE(point_transactions type/ref) reject duplicates at database level.
 *   4. All of it happens in ONE transaction: either everything is saved or nothing.
 */
function claim_code(int $userId, string $raw): array
{
    if (too_many('claim_failed', 'user_id', (string)$userId, 5, 15) || too_many('claim_failed', 'ip', client_ip(), 15, 15)) {
        security_event('claim_rate_limited', $userId, null, 'Claim blocked by rate limit');
        return ['ok' => false, 'error' => 'Too many failed attempts. Wait 15 minutes before trying another code.'];
    }
    $normalized = normalize_code($raw);
    if ($normalized === null) {
        security_event('claim_failed', $userId, null, 'Malformed code');
        return ['ok' => false, 'error' => 'That does not look like a product code. Codes have 16 letters and numbers.'];
    }
    $hash = code_hash($normalized);

    try {
        $result = transaction(function () use ($userId, $hash) {
            $code = fetch_one('SELECT id, status, product_id FROM product_codes WHERE code_hash = ? FOR UPDATE', [$hash]);
            if (!$code) {
                throw new BusinessException('This code was not found. Check it and try again.', 'unknown_code');
            }
            if ($code['status'] === 'claimed') {
                throw new BusinessException('This code has already been used.', 'used_code');
            }
            if ($code['status'] !== 'active') {
                throw new BusinessException('This code is not active. Contact support if you think this is a mistake.', 'inactive_code');
            }
            $product = fetch_one('SELECT name, points, is_active FROM products WHERE id = ?', [$code['product_id']]);
            if (!$product || !(int)$product['is_active']) {
                throw new BusinessException('This product is not part of the rewards programme right now.', 'inactive_product');
            }
            $user = fetch_one('SELECT points_balance, status FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || $user['status'] !== 'active') {
                throw new BusinessException('Your account cannot claim codes.', 'blocked_user');
            }

            $changed = q("UPDATE product_codes SET status = 'claimed', claimed_by = ?, claimed_at = NOW() WHERE id = ? AND status = 'active'",
                         [$userId, $code['id']])->rowCount();
            if ($changed !== 1) {
                throw new BusinessException('This code has already been used.', 'used_code');
            }
            $points = (int)$product['points'];
            q('INSERT INTO claims (user_id, code_id, product_id, points_awarded, ip) VALUES (?,?,?,?,?)',
              [$userId, $code['id'], $code['product_id'], $points, client_ip()]);
            $claimId = (int)db()->lastInsertId();
            $newBalance = (int)$user['points_balance'] + $points;
            q('UPDATE users SET points_balance = ? WHERE id = ?', [$newBalance, $userId]);
            q("INSERT INTO point_transactions (user_id, type, points, balance_after, ref_type, ref_id, note) VALUES (?, 'claim', ?, ?, 'claim', ?, ?)",
              [$userId, $points, $newBalance, $claimId, 'Claimed ' . $product['name']]);
            return ['ok' => true, 'points' => $points, 'balance' => $newBalance, 'product' => $product['name'], 'code_id' => (int)$code['id']];
        });
    } catch (BusinessException $e) {
        // Logged AFTER the rollback so the failure record is kept.
        security_event('claim_failed', $userId, null, $e->reason);
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {      // unique constraint caught a duplicate
            security_event('claim_failed', $userId, null, 'duplicate_blocked_by_db');
            return ['ok' => false, 'error' => 'This code has already been used.'];
        }
        throw $e;
    }
    log_activity('customer', $userId, 'claim_code', 'code', $result['code_id'], '+' . $result['points'] . ' points for ' . $result['product']);
    return $result;
}

/** Admin: activate/deactivate one code. Claimed codes can never change. */
function set_code_active(int $codeId, bool $active): bool
{
    [$from, $to] = $active ? ['inactive', 'active'] : ['active', 'inactive'];
    return q('UPDATE product_codes SET status = ? WHERE id = ? AND status = ?', [$to, $codeId, $from])->rowCount() === 1;
}
/** Admin: activate/deactivate every unclaimed code of a batch. Returns the number changed. */
function set_batch_active(int $batchId, bool $active): int
{
    [$from, $to] = $active ? ['inactive', 'active'] : ['active', 'inactive'];
    return q('UPDATE product_codes SET status = ? WHERE batch_id = ? AND status = ?', [$to, $batchId, $from])->rowCount();
}

/* =====================================================================
 * Rewards: redeeming and refunding
 * ===================================================================== */

function redeem_reward(int $userId, int $rewardId): array
{
    try {
        $res = transaction(function () use ($userId, $rewardId) {
            // Lock order is always user -> reward, so two redemptions cannot deadlock each other.
            $user = fetch_one('SELECT points_balance, status FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || $user['status'] !== 'active') {
                throw new BusinessException('Your account cannot redeem rewards.');
            }
            $reward = fetch_one('SELECT id, name, points_cost, stock, is_active FROM rewards WHERE id = ? FOR UPDATE', [$rewardId]);
            if (!$reward || !(int)$reward['is_active']) {
                throw new BusinessException('This reward is no longer available.');
            }
            if ((int)$reward['stock'] < 1) {
                throw new BusinessException('This reward is out of stock.');
            }
            $cost = (int)$reward['points_cost'];
            $balance = (int)$user['points_balance'];
            if ($balance < $cost) {
                throw new BusinessException('You need ' . fmt_num($cost - $balance) . ' more points for this reward.');
            }
            q('UPDATE rewards SET stock = stock - 1 WHERE id = ? AND stock > 0', [$rewardId]);
            $ok = q('UPDATE users SET points_balance = points_balance - ? WHERE id = ? AND points_balance >= ?', [$cost, $userId, $cost])->rowCount();
            if ($ok !== 1) {
                throw new BusinessException('Not enough points.');
            }
            q("INSERT INTO redemptions (user_id, reward_id, points_spent, status) VALUES (?,?,?, 'pending')", [$userId, $rewardId, $cost]);
            $redemptionId = (int)db()->lastInsertId();
            q("INSERT INTO point_transactions (user_id, type, points, balance_after, ref_type, ref_id, note) VALUES (?, 'redeem', ?, ?, 'redemption', ?, ?)",
              [$userId, -$cost, $balance - $cost, $redemptionId, 'Redeemed ' . $reward['name']]);
            return ['ok' => true, 'reward' => $reward['name'], 'cost' => $cost, 'balance' => $balance - $cost, 'redemption_id' => $redemptionId];
        });
    } catch (BusinessException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    log_activity('customer', $userId, 'redeem_reward', 'redemption', $res['redemption_id'], '-' . $res['cost'] . ' points for ' . $res['reward']);
    return $res;
}

/** Admin: move a pending redemption to fulfilled or cancelled (cancel = refund points + restore stock). */
function update_redemption(int $id, string $to, int $adminId): array
{
    try {
        $info = transaction(function () use ($id, $to) {
            $r = fetch_one('SELECT id, user_id, reward_id, points_spent, status FROM redemptions WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) { throw new BusinessException('Redemption not found.'); }
            if ($r['status'] !== 'pending') { throw new BusinessException('Only pending redemptions can be changed.'); }
            if ($to === 'fulfilled') {
                q("UPDATE redemptions SET status = 'fulfilled' WHERE id = ?", [$id]);
                return ['status' => 'fulfilled', 'user_id' => (int)$r['user_id']];
            }
            if ($to !== 'cancelled') { throw new BusinessException('Unknown status.'); }
            $user = fetch_one('SELECT points_balance FROM users WHERE id = ? FOR UPDATE', [$r['user_id']]);
            $new = (int)$user['points_balance'] + (int)$r['points_spent'];
            q('UPDATE users SET points_balance = ? WHERE id = ?', [$new, $r['user_id']]);
            q('UPDATE rewards SET stock = stock + 1 WHERE id = ?', [$r['reward_id']]);
            q("UPDATE redemptions SET status = 'cancelled' WHERE id = ?", [$id]);
            q("INSERT INTO point_transactions (user_id, type, points, balance_after, ref_type, ref_id, note) VALUES (?, 'refund', ?, ?, 'redemption', ?, ?)",
              [$r['user_id'], $r['points_spent'], $new, $id, 'Refund for cancelled redemption #' . $id]);
            return ['status' => 'cancelled', 'user_id' => (int)$r['user_id']];
        });
    } catch (BusinessException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    log_activity('admin', $adminId, 'redemption_' . $info['status'], 'redemption', $id, 'Customer #' . $info['user_id']);
    return ['ok' => true, 'status' => $info['status']];
}
