<?php
/**
 * core.php - users, referrals, device verification, points, rewards.
 * All money-like operations (points / stock) run inside BEGIN IMMEDIATE transactions.
 */

function is_admin(int $uid): bool {
    return in_array($uid, array_map('intval', (array)cfg('admin_ids', [])), true);
}

function is_maintenance(): bool {
    return setting('maintenance_mode', '0') === '1';
}

function maintenance_msg(): string {
    $custom = trim((string)setting('maintenance_text', ''));
    if ($custom !== '') return $custom;
    return "🛠 <b>Bot Maintenance Mode me hai!</b>\n\nHum abhi zaroori maintenance/update kar rahe hain. Kripya thodi der baad dobara check karein.";
}

function get_user(int $uid): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE user_id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Creates the user row if missing. Returns true only for a brand-new user (atomic). */
function touch_user(int $uid, string $first, string $username): bool {
    $pdo = db();
    if (db_driver() === 'pgsql') {
        $st = $pdo->prepare('INSERT INTO users(user_id, username, first_name, created_at) VALUES(?,?,?,?) ON CONFLICT (user_id) DO NOTHING');
    } else {
        $st = $pdo->prepare('INSERT OR IGNORE INTO users(user_id, username, first_name, created_at) VALUES(?,?,?,?)');
    }
    $st->execute([$uid, $username, $first, time()]);
    $new = $st->rowCount() === 1;
    if (!$new) {
        $pdo->prepare('UPDATE users SET username = ?, first_name = ? WHERE user_id = ?')->execute([$username, $first, $uid]);
    }
    return $new;
}

/** Referral links work only for unverified users, never for self, banned or unknown referrers. */
function attach_referrer(int $uid, int $ref): bool {
    if ($ref <= 0 || $ref === $uid) return false;
    $r = get_user($ref);
    if (!$r || $r['banned']) return false;
    $u = get_user($uid);
    if ($u && !empty($u['verified_at'])) return false;
    if (db_driver() === 'pgsql') {
        $st = db()->prepare('INSERT INTO referrals(referred_id, referrer_id, created_at) VALUES(?,?,?) ON CONFLICT (referred_id) DO NOTHING');
    } else {
        $st = db()->prepare('INSERT OR IGNORE INTO referrals(referred_id, referrer_id, created_at) VALUES(?,?,?)');
    }
    $st->execute([$uid, $ref, time()]);
    return $st->rowCount() === 1;
}

/* ------------------------------------------------------------ points */

/** Applies a points change (never below zero) and writes the ledger. Returns the actual delta. */
function add_points(int $uid, int $delta, string $reason, string $ref = ''): int {
    $pdo = db();
    if ($delta >= 0) {
        $st = $pdo->prepare('UPDATE users SET points = points + ?, total_earned = total_earned + ? WHERE user_id = ?');
        $st->execute([$delta, $delta, $uid]);
        $actual = $delta;
    } else {
        $u = get_user($uid);
        if (!$u) return 0;
        $old = (int)$u['points'];
        $actual = max(-$old, $delta);
        $st = $pdo->prepare('UPDATE users SET points = points + ? WHERE user_id = ?');
        $st->execute([$actual, $uid]);
    }
    $pdo->prepare('INSERT INTO ledger(user_id, delta, reason, ref, created_at) VALUES(?,?,?,?,?)')
        ->execute([$uid, $actual, $reason, $ref, time()]);
    return $actual;
}

/** Credits one referral if BOTH sides are verified and not banned. Call inside a transaction. */
function credit_referral_if_ready(int $referred_id): ?int {
    $pdo = db();
    $st = $pdo->prepare("
        SELECT r.referrer_id FROM referrals r
        JOIN users ru ON ru.user_id = r.referred_id
        JOIN users rr ON rr.user_id = r.referrer_id
        WHERE r.referred_id = ? AND r.status = 'pending'
          AND ru.verified_at IS NOT NULL AND rr.verified_at IS NOT NULL
          AND ru.banned = 0 AND rr.banned = 0");
    $st->execute([$referred_id]);
    $row = $st->fetch();
    if (!$row) return null;
    $up = $pdo->prepare("UPDATE referrals SET status = 'credited', credited_at = ? WHERE referred_id = ? AND status = 'pending'");
    $up->execute([time(), $referred_id]);
    if ($up->rowCount() !== 1) return null;
    add_points((int)$row['referrer_id'], pts_per_ref(), 'referral', (string)$referred_id);
    return (int)$row['referrer_id'];
}

/** After $uid got verified: credit them as referred AND credit their waiting referrals. */
function credit_all_ready_for(int $uid): array {
    $out = [];
    $r = credit_referral_if_ready($uid);
    if ($r) $out[] = [$r, $uid];
    $st = db()->prepare("SELECT referred_id FROM referrals WHERE referrer_id = ? AND status = 'pending'");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $row) {
        $x = credit_referral_if_ready((int)$row['referred_id']);
        if ($x) $out[] = [$x, (int)$row['referred_id']];
    }
    return $out;
}

/* ------------------------------------------------ device verification */

function log_verify(int $uid, string $ip, string $fp, string $result): void {
    try {
        db()->prepare('INSERT INTO verify_log(user_id, ip, fp_hash, result, created_at) VALUES(?,?,?,?,?)')
            ->execute([$uid, $ip, $fp, $result, time()]);
    } catch (Throwable $e) {
        log_line('verify_log failed: ' . $e->getMessage());
    }
}

/**
 * One device = one account.
 *  - device_token : random id kept in the Mini App's storage (strong signal for the same install)
 *  - fp           : sha256 of hardware/browser traits (catches storage clears / reinstalls)
 *  - ip           : soft limit only (mobile carriers share IPs)
 */
function verify_device(int $uid, string $token, string $fp, string $ip, string $ua = '', array $signals = []): array {
    $fail = fn(string $code, string $msg) => ['ok' => false, 'code' => $code, 'msg' => $msg];

    $u = get_user($uid);
    if (!$u)             return $fail('no_user', 'Pehle bot mein /start dabao.');
    if ($u['banned'])    return $fail('banned', 'Aapka account block hai.');
    if ($u['verified_at']) return ['ok' => true, 'code' => 'already', 'msg' => 'Aap pehle se verified ho.'];
    if (!preg_match('/^[a-f0-9-]{16,64}$/', $token) || !preg_match('/^[a-f0-9]{64}$/', $fp)) {
        return $fail('bad_request', 'Verification data galat hai. Mini App dobara kholo.');
    }
    if (!empty($signals['webdriver'])) {
        log_verify($uid, $ip, $fp, 'bot_detected');
        return $fail('bot_detected', 'Automated browser detect hua. Asli Telegram app se kholo.');
    }

    $pdo = db();
    $exempt = (bool)$u['device_exempt'] || is_admin($uid);
    $credited = [];

    // 1) same install / same device already used by ANOTHER account
    $st = $pdo->prepare('SELECT user_id FROM devices WHERE device_token = ? AND user_id <> ?');
    $st->execute([$token, $uid]);
    $tokenTaken = (bool)$st->fetch();
    if ($tokenTaken && !$exempt) {
        log_verify($uid, $ip, $fp, 'device_used');
        return $fail('device_used', 'Is device par pehle hi ek aur account verify hai. Ek device par sirf ek account allowed hai.');
    }
    if (opt('fp_strict', false)) {
        $st = $pdo->prepare('SELECT user_id FROM devices WHERE fp_hash = ? AND user_id <> ?');
        $st->execute([$fp, $uid]);
        if ($st->fetch() && !$exempt) {
            log_verify($uid, $ip, $fp, 'device_used');
            return $fail('device_used', 'Is device par pehle hi ek aur account verify hai. Ek device par sirf ek account allowed hai.');
        }
    }
    // 2) soft IP limit (0 = off) - ignore local/private test IPs
    $maxIp = (int)opt('max_per_ip_24h', 5);
    $isPrivateIp = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    if ($maxIp > 0 && !$exempt && !$isPrivateIp) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE ip = ? AND created_at > ? AND user_id <> ?');
        $st->execute([$ip, time() - 86400, $uid]);
        if ((int)$st->fetchColumn() >= $maxIp) {
            log_verify($uid, $ip, $fp, 'ip_limit');
            return $fail('ip_limit', 'Is network se bahut saare accounts verify ho chuke hain. Mobile data/WiFi badal ke kal try karo.');
        }
    }

    try {
        // Safety: recover from any unexpected leftover transaction on this connection
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $ignored) {}
        }
        db_begin($pdo);

        $store = $tokenTaken ? hash('sha256', $token . ':' . $uid) : $token;   // exempt users: keep UNIQUE happy
        if (db_driver() === 'pgsql') {
            // Single atomic UPSERT — avoids the DELETE→INSERT gap that caused 25P02 errors
            $pdo->prepare('
                INSERT INTO devices(user_id, device_token, fp_hash, ip, ua, created_at)
                VALUES(?,?,?,?,?,?)
                ON CONFLICT (user_id) DO UPDATE
                  SET device_token = EXCLUDED.device_token,
                      fp_hash      = EXCLUDED.fp_hash,
                      ip           = EXCLUDED.ip,
                      ua           = EXCLUDED.ua,
                      created_at   = EXCLUDED.created_at
            ')->execute([$uid, $store, $fp, $ip, substr($ua, 0, 200), time()]);
        } else {
            $pdo->prepare('INSERT OR REPLACE INTO devices(user_id, device_token, fp_hash, ip, ua, created_at) VALUES(?,?,?,?,?,?)')
                ->execute([$uid, $store, $fp, $ip, substr($ua, 0, 200), time()]);
        }
        $pdo->prepare('UPDATE users SET verified_at = ? WHERE user_id = ?')->execute([time(), $uid]);
        $credited = credit_all_ready_for($uid);
        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
        log_line('verify_device error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return $fail('server', 'Server error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }

    log_verify($uid, $ip, $fp, 'ok');
    $oldMsgId = (int)setting("verify_msg_{$uid}", 0);
    if ($oldMsgId > 0) {
        delete_msg($uid, $oldMsgId);
        del_setting("verify_msg_{$uid}");
    }
    send($uid, "✅ <b>Device verify ho gaya!</b>\nAapka device safaltapoorvak verify ho chuka hai. Ab aap bot ke saare features use kar sakte hain.", main_kb($uid));
    foreach ($credited as [$referrer, $referred]) {
        $r = get_user($referrer);
        send($referrer, E('party') . " <b>Naya refer verify ho gaya!</b>\n+" . pts_per_ref()
            . " point mila.\n" . E('points') . " Total points: <b>" . (int)($r['points'] ?? 0) . "</b>");
    }
    return ['ok' => true, 'code' => 'ok', 'msg' => '✅ Device verify ho gaya!'];
}

/* ------------------------------------------------------ channel gate */

function pts_per_ref(): int {
    return max(1, (int)opt('points_per_referral', 1));
}

function get_channels(): array {
    return db()->query('SELECT * FROM channels ORDER BY id')->fetchAll();
}

function channels_ok(int $uid): bool {
    $chs = array_column(get_channels(), 'chat');
    if (!$chs) return true;
    $u = get_user($uid);
    if ($u && (int)$u['chan_ok_until'] > time()) return true;
    foreach ($chs as $ch) {
        $r = tg('getChatMember', ['chat_id' => $ch, 'user_id' => $uid]);
        if (!($r['ok'] ?? false)) { log_line("CHANNEL_CHECK_FAIL $ch uid=$uid"); return false; }
        $st = $r['result']['status'] ?? '';
        $in = in_array($st, ['member', 'administrator', 'creator'], true)
           || ($st === 'restricted' && !empty($r['result']['is_member']));
        if (!$in) return false;
    }
    db()->prepare('UPDATE users SET chan_ok_until = ? WHERE user_id = ?')->execute([time() + 1800, $uid]);
    return true;
}

/* ----------------------------------------------------------- rewards */

function list_rewards(): array {
    return db()->query("
        SELECT r.id, r.name, r.cost, r.kind, r.custom_icon,
               (SELECT COUNT(*) FROM reward_stock s WHERE s.reward_id = r.id AND s.used = 0) AS stock
        FROM rewards r WHERE r.active = 1 ORDER BY r.cost, r.id")->fetchAll();
}

function redeem(int $uid, int $rid): array {
    $pdo = db();
    try {
        db_begin($pdo);
        $st = $pdo->prepare('SELECT * FROM rewards WHERE id = ? AND active = 1');
        $st->execute([$rid]);
        $rw = $st->fetch();
        $u = get_user($uid);
        $err = null;
        if (!$rw || !$u)                          $err = 'Ye reward available nahi hai.';
        elseif ((int)$u['points'] < (int)$rw['cost']) $err = 'Aapke points kam hain. Chahiye: ' . (int)$rw['cost'] . ', aapke paas: ' . (int)$u['points'];
        if ($err) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'msg' => $err]; }

        if (($rw['kind'] ?? 'stock') === 'manual') {          // service delivered by admin, no stock needed
            add_points($uid, -(int)$rw['cost'], 'redeem', 'reward:' . $rid . ':manual');
            $pdo->commit();
            return ['ok' => true, 'manual' => true, 'reward' => $rw['name'], 'left' => (int)$u['points'] - (int)$rw['cost']];
        }
        $st = $pdo->prepare('SELECT id, code FROM reward_stock WHERE reward_id = ? AND used = 0 ORDER BY id LIMIT 1');
        $st->execute([$rid]);
        $s = $st->fetch();
        if (!$s) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'msg' => 'Is reward ka stock abhi khatam hai. Baad mein try karo.']; }

        $up = $pdo->prepare('UPDATE reward_stock SET used = 1, claimed_by = ?, claimed_at = ? WHERE id = ? AND used = 0');
        $up->execute([$uid, time(), $s['id']]);
        if ($up->rowCount() !== 1) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'msg' => 'Dobara try karo.']; }
        add_points($uid, -(int)$rw['cost'], 'redeem', 'reward:' . $rid . ':stock:' . $s['id']);
        $pdo->commit();
        return ['ok' => true, 'code' => $s['code'], 'reward' => $rw['name'],
                'left' => (int)$u['points'] - (int)$rw['cost']];
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
        log_line('redeem error: ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Server error, dobara try karo.'];
    }
}
