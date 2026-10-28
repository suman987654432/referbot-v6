<?php
/** handlers.php - Telegram update routing: user menu + button-based Admin Panel. */

/* ============================================================ helpers */

/** "🔗 My Refer Link" / "My Refer Link" -> "my refer link" (so premium-icon buttons still match) */
function norm_label(string $t): string {
    return mb_strtolower(trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $t)));
}

function cbtn(string $label, string $data): array {
    return ['text' => $label, 'callback_data' => $data];
}

function main_kb(int $uid): array {
    $rows = [
        [btn('My Refer Link', [], 'refer'), btn('My Stats', [], 'stats')],
        [btn('Rewards', [], 'gift'),        btn('Leaderboard', [], 'top')],
    ];
    if (is_admin($uid)) $rows[] = [btn('Admin Panel', [], 'admin')];
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

function ref_link(int $uid): string {
    return 'https://t.me/' . cfg('bot_username') . '?start=r' . $uid;
}

function verify_kb(): array {
    return ['inline_keyboard' => [[btn('Verify Device', ['web_app' => ['url' => (string)cfg('webapp_url')]], 'verify')]]];
}

function join_kb(): array {
    $rows = [];
    foreach (get_channels() as $i => $c) {
        if ($c['link'] === '') continue;
        $rows[] = [btn('Join Channel ' . ($i + 1), ['url' => $c['link']], 'join')];
    }
    $rows[] = [btn('Maine Join Kar Liya', ['callback_data' => 'chk'], 'joined')];
    return ['inline_keyboard' => $rows];
}

function set_state(int $uid, ?array $s): void {
    db()->prepare('UPDATE users SET state = ? WHERE user_id = ?')->execute([$s ? json_encode($s, JSON_UNESCAPED_UNICODE) : '', $uid]);
}

function get_state(array $u): array {
    $j = json_decode((string)($u['state'] ?? ''), true);
    return is_array($j) ? $j : [];
}

function custom_emoji_ids(array $m): array {
    $ids = [];
    foreach (['entities', 'caption_entities'] as $k) {
        foreach ((array)($m[$k] ?? []) as $e) {
            if (($e['type'] ?? '') === 'custom_emoji' && !empty($e['custom_emoji_id'])) $ids[] = (string)$e['custom_emoji_id'];
        }
    }
    return array_values(array_unique($ids));
}

/* ------------------------------------------------ stock files (txt / json) */

/** Top-level {...} objects in a text (one per line, pretty-printed or glued). null if the text is not only objects. */
function extract_json_objects(string $s): ?array {
    $n = strlen($s); $out = []; $depth = 0; $start = -1; $inStr = false; $esc = false;
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($esc) $esc = false;
            elseif ($c === '\\') $esc = true;
            elseif ($c === '"') $inStr = false;
            continue;
        }
        if ($depth === 0) {
            if ($c === '{') { $depth = 1; $start = $i; }
            elseif (!ctype_space($c) && $c !== ',') return null;
            continue;
        }
        if ($c === '"') $inStr = true;
        elseif ($c === '{') $depth++;
        elseif ($c === '}') { $depth--; if ($depth === 0) $out[] = substr($s, $start, $i - $start + 1); }
    }
    return ($depth === 0 && !$inStr && $out) ? $out : null;
}

/** Splits an uploaded/pasted stock text into items: JSON array, JSON objects, or one code per line. */
function split_stock_items(string $raw): array {
    $raw = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $raw));
    if ($raw === '') return [];
    if ($raw[0] === '[') {
        $j = json_decode($raw, true);
        if (is_array($j) && array_is_list($j)) {
            $items = [];
            foreach ($j as $el) {
                $s = is_string($el) ? trim($el) : json_encode($el, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($s !== '' && $s !== false) $items[] = $s;
            }
            if ($items) return $items;
        }
    }
    if ($raw[0] === '{') {
        $objs = extract_json_objects($raw);
        if ($objs) return array_map('trim', $objs);
    }
    $items = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '') $items[] = $line;
    }
    return $items;
}

function add_stock(int $rid, array $items): int {
    $ins = db()->prepare('INSERT OR IGNORE INTO reward_stock(reward_id, code) VALUES(?,?)');
    $n = 0;
    foreach ($items as $it) { $ins->execute([$rid, $it]); $n += $ins->rowCount(); }
    return $n;
}

/** JSON / multi-line codes are delivered as a .txt file, normal short codes as text. */
function is_file_code(string $code): bool {
    $c = ltrim($code);
    return $c !== '' && ($c[0] === '{' || $c[0] === '[' || strpos(trim($code), "\n") !== false);
}

function safe_filename(string $name): string {
    $s = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '_', $name), '_');
    return ($s !== '' ? $s : 'reward') . '_' . date('Ymd-His') . '.txt';
}

function emoji_test(int $uid): void {
    $id = '5040030395416969985';                      // 🛍 id that Code Adda itself shows (seen working in its menu)
    $kb = ['inline_keyboard' => [[['text' => 'Test Button', 'callback_data' => 'noop', 'icon_custom_emoji_id' => $id, 'style' => 'success']]]];
    $r = tg_raw('sendMessage', ['chat_id' => $uid, 'parse_mode' => 'HTML', 'reply_markup' => $kb,
        'text' => '<tg-emoji emoji-id="' . $id . '">🛍</tg-emoji> premium emoji test']);
    if (!($r['ok'] ?? false)) { send($uid, "❌ Telegram ne reject kiya: <code>" . h((string)($r['description'] ?? '?')) . "</code>"); return; }
    $txt = false;
    foreach ((array)($r['result']['entities'] ?? []) as $e) if (($e['type'] ?? '') === 'custom_emoji') $txt = true;
    $btn = !empty($r['result']['reply_markup']['inline_keyboard'][0][0]['icon_custom_emoji_id']);
    set_setting('premium_ok', $txt ? '1' : '0');   // info only; does not switch premium
    $verdict = $txt
        ? "✅ Telegram premium emoji accept kar raha hai. <b>Premium emoji chalu hain</b>: saare menu/messages/buttons ke emoji Code Adda jaise premium hain. Na dikhe to Telegram app update karo."
        : "❌ Telegram premium emoji hata raha hai. Iska matlab bot jis Telegram account se bana hai usme <b>Telegram Premium</b> nahi hai. "
        . "Bot code Code Adda jaisa hi emoji bhej raha hai, par Telegram is bot ke liye unhe hata raha hai. "
        . "Premium wale account se BotFather mein naya bot banao (ya ownership transfer), token <code>config.php</code> mein lagao, phir yahi test dobara chalao.";
    send($uid, "🧪 <b>Premium Emoji Test</b>\n\nMessage ka emoji: " . ($txt ? '✅ accept' : '❌ hata diya')
        . "\nButton ka icon: " . ($btn ? '✅ echo hua' : '❌ confirm nahi hua') . "\n\n" . $verdict);
}

/* ======================================================== user side */

function send_welcome(int $uid, string $name): void {
    send($uid, "👋 Namaste <b>" . h($name) . "</b>!\n\n"
        . "Dosto ko refer karo, har verified refer par <b>" . pts_per_ref() . " point</b> jeeto aur points se rewards redeem karo.\n\n"
        . "Niche ke buttons use karo 👇", main_kb($uid));
}

function gate(int $uid, array $u): bool {
    if (!channels_ok($uid)) {
        send($uid, E('join') . " Bot use karne ke liye pehle hamare channels join karo, phir <b>Maine Join Kar Liya</b> dabao.", join_kb());
        return false;
    }
    if (!$u['verified_at']) {
        send($uid, E('verify') . " <b>Device verification baaki hai.</b>\nEk device par sirf ek account allowed hai. Niche button dabao (2 second lagte hain).", verify_kb());
        return false;
    }
    return true;
}

function handle_update(array $upd): void {
    if (isset($upd['callback_query'])) { handle_callback($upd['callback_query']); return; }
    $m = $upd['message'] ?? null;
    if (!$m || ($m['chat']['type'] ?? '') !== 'private' || empty($m['from']) || !empty($m['from']['is_bot'])) return;

    $uid  = (int)$m['from']['id'];
    $name = (string)($m['from']['first_name'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $isNew = touch_user($uid, $name, (string)($m['from']['username'] ?? ''));

    if (preg_match('/^\/start(?:\s+(\S+))?$/', $text, $mm)) {
        if ($isNew && isset($mm[1]) && preg_match('/^r(\d+)$/', $mm[1], $rm)) {
            attach_referrer($uid, (int)$rm[1]);
        }
        $u = get_user($uid);
        if ($u['banned']) { send($uid, 'Aapka account block hai.'); return; }
        if (is_admin($uid)) set_state($uid, null);
        send_welcome($uid, $name);
        gate($uid, $u);
        return;
    }

    $u = get_user($uid);
    if ($u['banned']) return;

    $k = norm_label($text);
    if (is_admin($uid)) {
        if (admin_input($uid, $u, $m)) return;
        if ($k === 'admin panel' || $k === 'admin') { open_panel($uid); return; }
        if (handle_admin_cmd($uid, $text)) return;
    }

    if (!gate($uid, $u)) return;

    if ($k === 'my refer link') {
        send($uid, E('refer') . " <b>Aapka refer link:</b>\n<code>" . h(ref_link($uid)) . "</code>\n\n"
            . "Jo bhi is link se judega aur apna device verify karega, aapko <b>" . pts_per_ref() . " point</b> milega.");
    } elseif ($k === 'my stats' || $k === 'mystats') {
        send_stats($uid);
    } elseif ($k === 'rewards') {
        send_rewards($uid);
    } elseif ($k === 'leaderboard' || $k === 'top') {
        send_top($uid);
    } else {
        send($uid, 'Niche ke buttons use karo 👇', main_kb($uid));
    }
}

function send_stats(int $uid): void {
    $u = get_user($uid);
    $q = db()->prepare("SELECT status, COUNT(*) c FROM referrals WHERE referrer_id = ? GROUP BY status");
    $q->execute([$uid]);
    $c = ['pending' => 0, 'credited' => 0];
    foreach ($q->fetchAll() as $r) $c[$r['status']] = (int)$r['c'];
    send($uid, E('stats') . " <b>Aapke Stats</b>\n\n" . E('points') . " Points: <b>" . (int)$u['points'] . "</b>\n"
        . "✅ Verified refers: <b>{$c['credited']}</b>\n⏳ Pending (verify baaki): <b>{$c['pending']}</b>\n"
        . "🏅 Total kamaye: <b>" . (int)$u['total_earned'] . "</b>");
}

function send_rewards(int $uid): void {
    $rw = list_rewards();
    if (!$rw) { send($uid, 'Abhi koi reward add nahi hua. Jaldi aayega!'); return; }
    $u = get_user($uid);
    $rows = [];
    $txt = E('gift') . " <b>Rewards</b>\n" . E('points') . " Aapke points: <b>" . (int)$u['points'] . "</b>\n\n";
    foreach ($rw as $r) {
        $stock = $r['kind'] === 'manual' ? 'available' : 'stock: ' . $r['stock'];
        $txt .= "• <b>" . h($r['name']) . "</b> — {$r['cost']} pts ({$stock})\n";
        $rows[] = [cbtn($r['name'] . ' • ' . $r['cost'] . ' pts', 'rd:' . $r['id'])];
    }
    send($uid, $txt, ['inline_keyboard' => $rows]);
}

function send_top(int $uid): void {
    $rows = db()->query("SELECT first_name, total_earned FROM users WHERE banned = 0 AND total_earned > 0 ORDER BY total_earned DESC LIMIT 10")->fetchAll();
    if (!$rows) { send($uid, 'Abhi leaderboard khali hai.'); return; }
    $t = E('top') . " <b>Top Referrers</b>\n\n";
    foreach ($rows as $i => $r) {
        $n = mb_substr((string)$r['first_name'], 0, 2) . '***';
        $t .= ($i + 1) . ". " . h($n) . " — " . (int)$r['total_earned'] . " pts\n";
    }
    send($uid, $t);
}

function handle_callback(array $cb): void {
    $uid  = (int)($cb['from']['id'] ?? 0);
    $data = (string)($cb['data'] ?? '');
    $id   = (string)($cb['id'] ?? '');
    $u = get_user($uid);
    if (!$u || $u['banned']) { answer_cb($id); return; }

    if (strpos($data, 'a:') === 0) {
        if (!is_admin($uid)) { answer_cb($id, 'Sirf admin ke liye.', true); return; }
        admin_cb($cb, $u);
        return;
    }

    if ($data === 'chk') {
        db()->prepare('UPDATE users SET chan_ok_until = 0 WHERE user_id = ?')->execute([$uid]);
        if (!channels_ok($uid)) { answer_cb($id, '❌ Abhi saare channels join nahi kiye.', true); return; }
        answer_cb($id, '✅ Join verified!');
        $u = get_user($uid);
        if (gate($uid, $u)) send($uid, '✅ Sab ready! Buttons use karo 👇', main_kb($uid));
        return;
    }

    if (strpos($data, 'rd:') === 0) {
        if (!channels_ok($uid) || !$u['verified_at']) { answer_cb($id, 'Pehle channels join + device verify karo.', true); return; }
        $res = redeem($uid, (int)substr($data, 3));
        if (!$res['ok']) { answer_cb($id, $res['msg'], true); return; }
        answer_cb($id, '✅ Redeem ho gaya!');
        if (!empty($res['manual'])) {
            send($uid, E('party') . " <b>" . h($res['reward']) . "</b> ki request mil gayi!\nAdmin jaldi aapko deliver karega.\n\n"
                . E('points') . " Bache points: <b>" . (int)$res['left'] . "</b>");
        } elseif (is_file_code((string)$res['code'])) {
            send_text_file($uid, trim((string)$res['code']) . "\n", safe_filename($res['reward']),
                E('party') . " <b>" . h($res['reward']) . "</b> redeem ho gaya!\n\n" . E('points') . " Bache points: <b>" . (int)$res['left'] . "</b>");
        } else {
            send($uid, E('party') . " <b>" . h($res['reward']) . "</b> redeem ho gaya!\n\n🔑 Aapka code:\n<code>" . h($res['code'])
                . "</code>\n\n" . E('points') . " Bache points: <b>" . (int)$res['left'] . "</b>");
        }
        foreach ((array)cfg('admin_ids', []) as $a) {
            send((int)$a, "🧾 Redeem" . (!empty($res['manual']) ? ' (MANUAL deliver karna hai)' : '') . ": <a href=\"tg://user?id={$uid}\">user {$uid}</a> → " . h($res['reward']));
        }
        return;
    }
    answer_cb($id);
}

/* ====================================================== admin panel */

function open_panel(int $uid, ?int $mid = null): void {
    [$t, $kb] = p_home();
    panel_send($uid, $mid, $t, $kb);
}

function back_row(string $to = 'a:home'): array {
    return [cbtn('⬅️ Back', $to)];
}

function count_q(string $sql): int {
    return (int)db()->query($sql)->fetchColumn();
}

function p_home(): array {
    $t = E('admin') . " <b>Admin Panel</b>\n\n"
       . "👥 Users: <b>" . count_q('SELECT COUNT(*) FROM users') . "</b> (verified " . count_q('SELECT COUNT(*) FROM users WHERE verified_at IS NOT NULL') . ")\n"
       . "⏳ Pending refers: <b>" . count_q("SELECT COUNT(*) FROM referrals WHERE status='pending'") . "</b>\n"
       . E('points') . " Per refer: <b>" . pts_per_ref() . " pts</b>\n"
       . "📢 Channels: <b>" . count_q('SELECT COUNT(*) FROM channels') . "</b>  •  🎁 Rewards: <b>" . count_q('SELECT COUNT(*) FROM rewards WHERE active = 1') . "</b>";
    return [$t, ['inline_keyboard' => [
        [cbtn('📢 Channels', 'a:ch'),        cbtn('🎁 Rewards / Services', 'a:rw')],
        [cbtn('💎 Points per Refer', 'a:pts'), cbtn('👥 Users', 'a:us')],
        [cbtn('🎨 Premium Emoji', 'a:em'),   cbtn('🛡 Device Rules', 'a:dv')],
        [cbtn('📣 Broadcast', 'a:bc'),       cbtn('📊 Stats', 'a:st')],
    ]]];
}

function p_channels(): array {
    $rows = get_channels();
    $t = "📢 <b>Force-join Channels</b>\n\nUser ko bot use karne se pehle ye saare channels join karne padte hain.\n\n";
    $kb = [];
    if (!$rows) $t .= "<i>Abhi koi channel nahi hai (join check band).</i>\n";
    foreach ($rows as $c) {
        $t .= "• " . h($c['title'] ?: $c['chat']) . " <code>" . h($c['chat']) . "</code>\n";
        $kb[] = [cbtn('❌ ' . ($c['title'] ?: $c['chat']), 'a:chdel:' . $c['id'])];
    }
    $t .= "\n<i>Hatane ke liye channel ke button par dabao.</i>";
    $kb[] = [cbtn('➕ Channel Add', 'a:chadd')];
    $kb[] = back_row();
    return [$t, ['inline_keyboard' => $kb]];
}

/** Validates the channel (exists, bot is admin) and stores it. Returns [ok, message]. */
function add_channel(string $in): array {
    $in = trim($in);
    if (preg_match('~^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{4,})/?$~i', $in, $m)) $in = '@' . $m[1];
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,}$/', $in)) $in = '@' . $in;
    if (!preg_match('/^(@[A-Za-z][A-Za-z0-9_]{4,}|-100\d{5,})$/', $in)) {
        return [false, "❌ Format galat. <code>@channelusername</code> ya <code>-1001234567890</code> bhejo."];
    }
    $r = tg('getChat', ['chat_id' => $in]);
    if (!($r['ok'] ?? false) || empty($r['result']['id'])) {
        return [false, "❌ Channel nahi mila. Username check karo (private channel ho to numeric ID bhejo) aur bot ko us channel mein admin banao."];
    }
    $chat = $r['result'];
    if (!in_array($chat['type'] ?? '', ['channel', 'supergroup', 'group'], true)) return [false, '❌ Ye channel/group nahi hai.'];
    $botId = (int)explode(':', (string)cfg('bot_token'))[0];
    $me = tg('getChatMember', ['chat_id' => $in, 'user_id' => $botId]);
    if (!in_array($me['result']['status'] ?? '', ['administrator', 'creator'], true)) {
        return [false, "❌ Bot is channel mein <b>admin</b> nahi hai. Pehle bot ko admin banao, phir dobara bhejo."];
    }
    $username = $chat['username'] ?? '';
    $link = $username ? 'https://t.me/' . $username : '';
    if ($link === '') {
        $l = tg('exportChatInviteLink', ['chat_id' => $in]);
        $link = is_string($l['result'] ?? null) ? $l['result'] : '';
    }
    if ($link === '') return [false, "❌ Invite link nahi ban paya. Bot ko 'Invite users via link' permission do ya public channel use karo."];
    $store = $username ? '@' . $username : (string)$chat['id'];
    $title = (string)($chat['title'] ?? $store);
    $ins = db()->prepare('INSERT OR IGNORE INTO channels(chat, title, link) VALUES(?,?,?)');
    $ins->execute([$store, $title, $link]);
    if ($ins->rowCount() !== 1) return [false, 'Ye channel pehle se add hai.'];
    db()->exec('UPDATE users SET chan_ok_until = 0');
    return [true, "✅ Channel add ho gaya: <b>" . h($title) . "</b>"];
}

function p_rewards(): array {
    $rows = db()->query("SELECT r.*, (SELECT COUNT(*) FROM reward_stock s WHERE s.reward_id = r.id AND s.used = 0) AS stock
                         FROM rewards r ORDER BY r.id")->fetchAll();
    $t = E('gift') . " <b>Rewards / Services</b>\n\nUser points dekar ye cheezein le sakta hai.\n\n";
    $kb = [];
    if (!$rows) $t .= "<i>Abhi koi reward nahi hai.</i>\n";
    foreach ($rows as $r) {
        $tag = $r['kind'] === 'manual' ? 'manual' : 'stock ' . $r['stock'];
        $kb[] = [cbtn(($r['active'] ? '🟢 ' : '🔴 ') . $r['name'] . ' • ' . $r['cost'] . ' pts • ' . $tag, 'a:rwv:' . $r['id'])];
    }
    $kb[] = [cbtn('➕ Naya Reward', 'a:rwadd')];
    $kb[] = back_row();
    return [$t, ['inline_keyboard' => $kb]];
}

function p_reward(int $rid): array {
    $st = db()->prepare("SELECT r.*, (SELECT COUNT(*) FROM reward_stock s WHERE s.reward_id = r.id AND s.used = 0) AS stock,
                         (SELECT COUNT(*) FROM reward_stock s WHERE s.reward_id = r.id AND s.used = 1) AS sold FROM rewards r WHERE r.id = ?");
    $st->execute([$rid]);
    $r = $st->fetch();
    if (!$r) return p_rewards();
    $t = E('gift') . " <b>" . h($r['name']) . "</b>  (#{$r['id']})\n\n"
       . E('points') . " Cost: <b>{$r['cost']} pts</b>\n"
       . "Type: <b>" . ($r['kind'] === 'manual' ? 'Manual (admin deliver karega)' : 'Auto code (stock se)') . "</b>\n"
       . ($r['kind'] === 'manual' ? '' : "Stock bacha: <b>{$r['stock']}</b>  •  Bik chuka: <b>{$r['sold']}</b>\n")
       . "Status: <b>" . ($r['active'] ? '🟢 Chalu' : '🔴 Band') . "</b>";
    $kb = [
        [cbtn('✏️ Naam', 'a:rwn:' . $rid), cbtn('💎 Cost', 'a:rwc:' . $rid)],
    ];
    $row2 = [];
    if ($r['kind'] !== 'manual') $row2[] = cbtn('📦 Stock Add', 'a:rws:' . $rid);
    $row2[] = cbtn($r['active'] ? '🔴 Band Karo' : '🟢 Chalu Karo', 'a:rwt:' . $rid);
    $kb[] = $row2;
    $kb[] = [cbtn('🗑 Delete', 'a:rwd:' . $rid)];
    $kb[] = back_row('a:rw');
    return [$t, ['inline_keyboard' => $kb]];
}

function p_points(): array {
    return [E('points') . " <b>Points per Refer</b>\n\nAbhi: <b>" . pts_per_ref() . " point</b> har verified refer par.\n\n<i>Reward ka cost har reward ke andar alag set hota hai (Rewards menu).</i>",
        ['inline_keyboard' => [[cbtn('✏️ Change Karo', 'a:ptsset')], back_row()]]];
}

function p_user(int $uid): array {
    $u = get_user($uid);
    if (!$u) return ["❌ User nahi mila.", ['inline_keyboard' => [back_row()]]];
    $ref = db()->prepare('SELECT referrer_id, status FROM referrals WHERE referred_id = ?'); $ref->execute([$uid]); $by = $ref->fetch();
    $c = db()->prepare("SELECT status, COUNT(*) n FROM referrals WHERE referrer_id = ? GROUP BY status"); $c->execute([$uid]);
    $cnt = ['pending' => 0, 'credited' => 0];
    foreach ($c->fetchAll() as $x) $cnt[$x['status']] = (int)$x['n'];
    $t = "👤 <b>" . h($u['first_name']) . "</b> " . ($u['username'] ? '@' . h($u['username']) : '') . "\nID: <code>{$u['user_id']}</code>\n\n"
       . E('points') . " Points: <b>{$u['points']}</b>  (total kamaye {$u['total_earned']})\n"
       . "Verified: <b>" . ($u['verified_at'] ? 'Haan' : 'Nahi') . "</b>  •  Banned: <b>" . ($u['banned'] ? 'Haan' : 'Nahi') . "</b>\n"
       . "Device bypass: <b>" . ($u['device_exempt'] ? 'Haan' : 'Nahi') . "</b>\n"
       . "Refers: ✅ {$cnt['credited']}  ⏳ {$cnt['pending']}\n"
       . ($by ? "Kisne refer kiya: <code>{$by['referrer_id']}</code> ({$by['status']})" : '');
    $id = $u['user_id'];
    return [$t, ['inline_keyboard' => [
        [cbtn($u['banned'] ? '✅ Unban' : '🚫 Ban', 'a:ub:' . $id), cbtn('💎 Points +/-', 'a:up:' . $id)],
        [cbtn($u['device_exempt'] ? '🔒 Device Bypass Hatao' : '🔓 Device Bypass Do', 'a:ua:' . $id), cbtn('♻️ Device Reset', 'a:ur:' . $id)],
        back_row(),
    ]]];
}

function p_emoji(): array {
    $on = setting('premium_on', '1') === '1';
    $ok = setting('premium_ok', '');
    $t = "🎨 <b>Premium Emoji &amp; Rang</b>\n\n"
       . "Code Adda ki tarah emoji aur button rang <b>apne aap</b> lagte hain: jin emoji ka premium version pata hai wo premium ho jate hain, "
       . "buttons ko naam ke hisaab se rang milta hai (cancel/delete laal, buy/add/verify hara, baaki neela).\n\n"
       . "Premium emoji ke liye bot jis Telegram account se bana hai usme <b>Telegram Premium</b> hona zaroori hai. "
       . "Premium emoji hamesha bheje jate hain (Code Adda jaise). <b>🧪 Test Karo</b> sirf bata deta hai ki Telegram unhe accept kar raha hai ya nahi.\n\n"
       . "Test: <b>" . ($ok === '1' ? '✅ Telegram accept karta hai' : ($ok === '0' ? '❌ Telegram emoji hata raha hai' : '⏳ abhi nahi hua')) . "</b>\n"
       . "Switch: <b>" . ($on ? '🟢 ON' : '🔴 OFF') . "</b>\n\n"
       . "<b>Khaas slots</b> (jinke emoji ki ID manually badalni ho ya rang chunna ho):\n";
    $kb = [];
    $row = [];
    foreach (EMOJI_SLOTS as $slot => [$fb, $label]) {
        $has = (string)setting('emoji_' . $slot, '') !== '';
        $dot = ['success' => '🟢', 'primary' => '🔵', 'danger' => '🔴'][style_of($slot) ?? ''] ?? '⚪';
        $t .= ($has ? '✅' : '▫️') . ' ' . ($has ? E($slot) : $fb) . " {$label}  {$dot}\n";
        $row[] = cbtn(($has ? '✅ ' : '') . $label, 'a:ems:' . $slot);
        if (count($row) === 2) { $kb[] = $row; $row = []; }
    }
    if ($row) $kb[] = $row;
    $kb[] = [cbtn($on ? '🔴 Premium OFF Karo' : '🟢 Premium ON Karo', 'a:emt')];
    $kb[] = [cbtn('🔍 Emoji ID Nikalo', 'a:emid'), cbtn('🧪 Test Karo', 'a:emtest')];
    $kb[] = back_row();
    return [$t . "\n<i>Kisi jagah ka emoji ya rang badalne ke liye uske button par dabao. Rang: 🟢 hara 🔵 neela 🔴 laal ⚪ default.</i>", ['inline_keyboard' => $kb]];
}

function p_slot(string $slot): array {
    [$fb, $label] = EMOJI_SLOTS[$slot];
    $id = (string)setting('emoji_' . $slot, '');
    $col = style_of($slot);
    $t = "🎨 <b>" . h($label) . "</b>\n\nPremium emoji: <b>" . ($id ? '✅ set (' . $id . ')' : 'set nahi') . "</b>\n"
       . "Button rang: <b>" . ($col ? STYLE_NAMES[$col] : '⚪ Default') . "</b>\n\n"
       . "• Premium emoji badalna ho to ab ek <b>premium emoji</b> bhejo (ya sirf uski ID number).\n"
       . "• Rang badalna ho to niche se chuno.\n/cancel = ruko";
    $row = [];
    foreach (STYLE_NAMES as $k => $name) $row[] = cbtn($name, 'a:emc:' . $slot . ':' . $k);
    $row[] = cbtn('⚪ Default', 'a:emc:' . $slot . ':none');
    return [$t, ['inline_keyboard' => [$row, [cbtn('🗑 Emoji Hatao', 'a:emx:' . $slot), cbtn('⬅️ Back', 'a:em')]]]];
}

function p_device(): array {
    $strict = (bool)opt('fp_strict', true);
    $ip = (int)opt('max_per_ip_24h', 5);
    $rej = count_q("SELECT COUNT(*) FROM verify_log WHERE result IN ('device_used','ip_limit') AND created_at > " . (time() - 86400));
    $t = "🛡 <b>Device Rules</b>\n\nFingerprint strict: <b>" . ($strict ? '🟢 ON' : '🔴 OFF') . "</b>\n"
       . "<i>ON = same phone/hardware 2 account par reject. False reject zyada aaye to OFF karo.</i>\n\n"
       . "IP limit (24 ghante): <b>" . ($ip > 0 ? $ip . ' account/IP' : 'OFF') . "</b>\n"
       . "Pichle 24h mein reject: <b>{$rej}</b>";
    return [$t, ['inline_keyboard' => [
        [cbtn($strict ? '🔴 Strict OFF Karo' : '🟢 Strict ON Karo', 'a:dvs')],
        [cbtn('✏️ IP Limit Badlo', 'a:dvi')],
        back_row(),
    ]]];
}

function stats_text(): string {
    return "📊 <b>Stats</b>\n\nUsers: " . count_q('SELECT COUNT(*) FROM users')
        . "\nVerified: " . count_q('SELECT COUNT(*) FROM users WHERE verified_at IS NOT NULL')
        . "\nBanned: " . count_q('SELECT COUNT(*) FROM users WHERE banned = 1')
        . "\nRefers credited: " . count_q("SELECT COUNT(*) FROM referrals WHERE status='credited'")
        . "\nRefers pending: " . count_q("SELECT COUNT(*) FROM referrals WHERE status='pending'")
        . "\nDevice reject (total): " . count_q("SELECT COUNT(*) FROM verify_log WHERE result IN ('device_used','ip_limit')")
        . "\nPoints diye gaye: " . count_q("SELECT COALESCE(SUM(delta),0) FROM ledger WHERE reason='referral'")
        . "\nRedeems: " . count_q('SELECT COUNT(*) FROM ledger WHERE reason=\'redeem\'');
}

function render(int $chat, ?int $mid, array $page): void {
    panel_send($chat, $mid, $page[0], $page[1]);
}

function admin_cb(array $cb, array $u): void {
    $uid  = (int)$cb['from']['id'];
    $chat = (int)($cb['message']['chat']['id'] ?? $uid);
    $mid  = isset($cb['message']['message_id']) ? (int)$cb['message']['message_id'] : null;
    $cid  = (string)$cb['id'];
    $p    = explode(':', substr((string)$cb['data'], 2), 2);
    $act  = $p[0];
    $arg  = $p[1] ?? '';
    $pdo  = db();
    $toast = '';
    $alert = false;

    switch ($act) {
        case 'home':
            set_state($uid, null);
            render($chat, $mid, p_home());
            break;

        /* ---- channels ---- */
        case 'ch':
            set_state($uid, null);
            render($chat, $mid, p_channels());
            break;
        case 'chadd':
            set_state($uid, ['s' => 'addch']);
            send($uid, "📢 Channel ka <code>@username</code> ya numeric ID (<code>-100...</code>) bhejo.\n\n<b>Pehle bot ko us channel mein Admin banao.</b>\n/cancel = ruko");
            break;
        case 'chdel':
            $st = $pdo->prepare('SELECT * FROM channels WHERE id = ?'); $st->execute([(int)$arg]); $c = $st->fetch();
            if (!$c) { render($chat, $mid, p_channels()); break; }
            panel_send($chat, $mid, "❌ <b>" . h($c['title'] ?: $c['chat']) . "</b> ko hata dun?\nUsers ko ab ye channel join nahi karna padega.",
                ['inline_keyboard' => [[cbtn('✅ Haan Hatao', 'a:chdel2:' . $c['id']), cbtn('⬅️ Nahi', 'a:ch')]]]);
            break;
        case 'chdel2':
            $pdo->prepare('DELETE FROM channels WHERE id = ?')->execute([(int)$arg]);
            $pdo->exec('UPDATE users SET chan_ok_until = 0');
            $toast = 'Hata diya';
            render($chat, $mid, p_channels());
            break;

        /* ---- rewards ---- */
        case 'rw':
            set_state($uid, null);
            render($chat, $mid, p_rewards());
            break;
        case 'rwv':
            set_state($uid, null);
            render($chat, $mid, p_reward((int)$arg));
            break;
        case 'rwadd':
            set_state($uid, ['s' => 'rw_name']);
            send($uid, "🎁 Naye reward / service ka <b>naam</b> bhejo (jaise: Netflix 1 Month).\n/cancel = ruko");
            break;
        case 'rwk':
            $st = get_state($u);
            if (($st['s'] ?? '') !== 'rw_kind') { $toast = 'Session expire, dobara shuru karo.'; $alert = true; break; }
            $kind = $arg === 'manual' ? 'manual' : 'stock';
            $pdo->prepare('INSERT INTO rewards(name, cost, kind) VALUES(?,?,?)')->execute([$st['name'], (int)$st['cost'], $kind]);
            $rid = (int)$pdo->lastInsertId();
            if ($kind === 'stock') {
                set_state($uid, ['s' => 'rw_stock', 'rid' => $rid]);
                send($uid, "✅ Reward ban gaya. Ab <b>codes</b> bhejo:\n• text (ek line mein ek code), ya\n• <b>.txt / .json file</b> (JSON accounts bhi chalenge, ek file mein kitne bhi)\n\nFile forward bhi kar sakte ho. /cancel = ruko");
            } else {
                set_state($uid, null);
                render($chat, null, p_reward($rid));
            }
            break;
        case 'rws':
            set_state($uid, ['s' => 'rw_stock', 'rid' => (int)$arg]);
            send($uid, "📦 Codes bhejo:\n• text (ek line mein ek code), ya\n• <b>.txt / .json file</b> (JSON accounts bhi chalenge)\nDuplicate apne aap skip ho jayenge. /cancel = ruko");
            break;
        case 'rwn':
            set_state($uid, ['s' => 'rw_editname', 'rid' => (int)$arg]);
            send($uid, "✏️ Naya naam bhejo.\n/cancel = ruko");
            break;
        case 'rwc':
            set_state($uid, ['s' => 'rw_editcost', 'rid' => (int)$arg]);
            send($uid, "💎 Naya cost (points) bhejo.\n/cancel = ruko");
            break;
        case 'rwt':
            $pdo->prepare('UPDATE rewards SET active = 1 - active WHERE id = ?')->execute([(int)$arg]);
            render($chat, $mid, p_reward((int)$arg));
            break;
        case 'rwd':
            panel_send($chat, $mid, "🗑 Ye reward delete kar dun? Bacha hua unused stock bhi hat jayega.",
                ['inline_keyboard' => [[cbtn('✅ Haan Delete', 'a:rwd2:' . (int)$arg), cbtn('⬅️ Nahi', 'a:rwv:' . (int)$arg)]]]);
            break;
        case 'rwd2':
            $pdo->prepare('DELETE FROM reward_stock WHERE reward_id = ? AND used = 0')->execute([(int)$arg]);
            $pdo->prepare('DELETE FROM rewards WHERE id = ?')->execute([(int)$arg]);
            $toast = 'Delete ho gaya';
            render($chat, $mid, p_rewards());
            break;

        /* ---- points ---- */
        case 'pts':
            set_state($uid, null);
            render($chat, $mid, p_points());
            break;
        case 'ptsset':
            set_state($uid, ['s' => 'setpts']);
            send($uid, "💎 Ek refer par kitne points milein? Number bhejo (jaise 1, 2, 5).\n/cancel = ruko");
            break;

        /* ---- users ---- */
        case 'us':
            set_state($uid, ['s' => 'finduser']);
            send($uid, "👥 User ki <b>Telegram ID</b> ya <code>@username</code> bhejo.\n/cancel = ruko");
            break;
        case 'ub':
            $pdo->prepare('UPDATE users SET banned = 1 - banned WHERE user_id = ?')->execute([(int)$arg]);
            render($chat, $mid, p_user((int)$arg));
            break;
        case 'ua':
            $pdo->prepare('UPDATE users SET device_exempt = 1 - device_exempt WHERE user_id = ?')->execute([(int)$arg]);
            render($chat, $mid, p_user((int)$arg));
            break;
        case 'ur':
            $pdo->prepare('DELETE FROM devices WHERE user_id = ?')->execute([(int)$arg]);
            $pdo->prepare('UPDATE users SET verified_at = NULL WHERE user_id = ?')->execute([(int)$arg]);
            $toast = 'Device reset. User ko dobara verify karna hoga.';
            $alert = true;
            render($chat, $mid, p_user((int)$arg));
            break;
        case 'up':
            set_state($uid, ['s' => 'givepts', 'uid' => (int)$arg]);
            send($uid, "💎 Kitne points? Number bhejo. Jaise <code>10</code> (do) ya <code>-5</code> (kato).\n/cancel = ruko");
            break;

        /* ---- premium emoji ---- */
        case 'em':
            set_state($uid, null);
            render($chat, $mid, p_emoji());
            break;
        case 'emt':
            set_setting('premium_on', setting('premium_on', '1') === '1' ? '0' : '1');
            render($chat, $mid, p_emoji());
            break;
        case 'ems':
            if (!isset(EMOJI_SLOTS[$arg])) break;
            set_state($uid, ['s' => 'setemoji', 'slot' => $arg]);
            render($chat, $mid, p_slot($arg));
            break;
        case 'emc':                                   // button colour: a:emc:<slot>:<colour>
            [$sl, $col] = array_pad(explode(':', $arg, 2), 2, '');
            if (!isset(EMOJI_SLOTS[$sl])) break;
            set_setting('style_' . $sl, isset(STYLE_NAMES[$col]) ? $col : 'none');
            set_state($uid, ['s' => 'setemoji', 'slot' => $sl]);
            render($chat, $mid, p_slot($sl));
            break;
        case 'emx':
            if (isset(EMOJI_SLOTS[$arg])) del_setting('emoji_' . $arg);
            set_state($uid, null);
            render($chat, $mid, p_emoji());
            break;
        case 'emtest':
            emoji_test($uid);
            break;
        case 'emid':
            set_state($uid, ['s' => 'emojiid']);
            send($uid, "🔍 Koi bhi premium emoji bhejo, main uski ID bata dunga.\n/cancel = ruko");
            break;

        /* ---- device rules ---- */
        case 'dv':
            set_state($uid, null);
            render($chat, $mid, p_device());
            break;
        case 'dvs':
            set_setting('fp_strict', opt('fp_strict', true) ? '0' : '1');
            render($chat, $mid, p_device());
            break;
        case 'dvi':
            set_state($uid, ['s' => 'setip']);
            send($uid, "🛡 Ek IP se 24 ghante mein max kitne account verify ho sakte hain? Number bhejo (0 = limit band).\n/cancel = ruko");
            break;

        /* ---- stats / broadcast ---- */
        case 'st':
            panel_send($chat, $mid, stats_text(), ['inline_keyboard' => [back_row()]]);
            break;
        case 'bc':
            set_state($uid, ['s' => 'bcast']);
            send($uid, "📣 Jo message sabko bhejna hai wo ab bhejo (text, photo, premium emoji sab chalega).\n/cancel = ruko");
            break;
        case 'bcgo':
            $st = get_state($u);
            if (($st['s'] ?? '') !== 'bc_ok') { $toast = 'Session expire.'; $alert = true; break; }
            set_state($uid, null);
            answer_cb($cid, 'Bhej raha hoon...');
            [$ok, $fail] = do_broadcast((int)$st['from'], (int)$st['mid']);
            send($uid, "📣 Broadcast pura: ✅ {$ok} gaye, ❌ {$fail} fail (blocked/inactive).");
            return;
    }
    answer_cb($cid, $toast, $alert);
}

function do_broadcast(int $fromChat, int $mid): array {
    ignore_user_abort(true);
    @set_time_limit(0);
    $ids = db()->query('SELECT user_id FROM users WHERE banned = 0')->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0; $fail = 0;
    foreach ($ids as $id) {
        $r = tg('copyMessage', ['chat_id' => (int)$id, 'from_chat_id' => $fromChat, 'message_id' => $mid]);
        ($r['ok'] ?? false) ? $ok++ : $fail++;
        usleep(60000);                    // stay under Telegram's ~30 msg/sec limit
    }
    return [$ok, $fail];
}

/** Handles the admin's typed answer while a panel flow is waiting for input. Returns true if consumed. */
function admin_input(int $uid, array $u, array $m): bool {
    $st = get_state($u);
    if (!$st) return false;
    $text = trim((string)($m['text'] ?? ''));
    if ($text === '/cancel') { set_state($uid, null); send($uid, 'Cancel ho gaya.', main_kb($uid)); return true; }
    if ($text !== '' && $text[0] === '/') { set_state($uid, null); return false; }   // other commands cancel the flow
    if (in_array(norm_label($text), ['my refer link', 'my stats', 'rewards', 'leaderboard', 'admin panel'], true)) {
        set_state($uid, null);                                                          // menu buttons cancel the flow too
        return false;
    }
    $pdo = db();

    switch ($st['s'] ?? '') {
        case 'addch':
            [$ok, $msg] = add_channel($text);
            if ($ok) { set_state($uid, null); send($uid, $msg, ['inline_keyboard' => [[cbtn('📢 Channels', 'a:ch'), cbtn('⬅️ Panel', 'a:home')]]]); }
            else send($uid, $msg);
            return true;

        case 'rw_name':
            if ($text === '') { send($uid, 'Naam text mein bhejo.'); return true; }
            set_state($uid, ['s' => 'rw_cost', 'name' => mb_substr($text, 0, 60)]);
            send($uid, E('points') . " Is reward ka <b>cost</b> kitne points? (number)");
            return true;

        case 'rw_cost':
            if (!ctype_digit($text) || (int)$text < 1) { send($uid, 'Sirf number bhejo (1 ya zyada).'); return true; }
            set_state($uid, ['s' => 'rw_kind', 'name' => $st['name'], 'cost' => (int)$text]);
            send($uid, "Ye reward kaise milega?", ['inline_keyboard' => [
                [cbtn('📦 Auto Code (stock se)', 'a:rwk:stock')],
                [cbtn('🧑‍💼 Manual (admin deliver karega)', 'a:rwk:manual')],
            ]]);
            return true;

        case 'rw_kind':
            send($uid, 'Upar ke buttons mein se ek chuno, ya /cancel.');
            return true;

        case 'rw_stock':
            $rid = (int)$st['rid'];
            $raw = $text;
            if (!empty($m['document'])) {                      // .txt / .json file upload (forwarded files work too)
                $d = $m['document'];
                if ((int)($d['file_size'] ?? 0) > 15 * 1024 * 1024) { send($uid, '❌ File 15MB se badi hai. Chhoti files mein tod ke bhejo.'); return true; }
                $f = tg('getFile', ['file_id' => (string)$d['file_id']]);
                $body = isset($f['result']['file_path']) ? tg_download((string)$f['result']['file_path']) : null;
                if ($body === null) { send($uid, '❌ File download nahi hui. Dobara bhejo ya text paste karo.'); return true; }
                if (strpos($body, "\0") !== false) { send($uid, '❌ Ye text file nahi lagti. .txt ya .json file bhejo.'); return true; }
                $raw = $body;
            }
            $items = split_stock_items($raw);
            if (!$items) { send($uid, 'Codes text mein ya .txt / .json file mein bhejo (ek line mein ek code, ya JSON).'); return true; }
            $n = add_stock($rid, $items);
            $left = (int)$pdo->query("SELECT COUNT(*) FROM reward_stock WHERE reward_id = {$rid} AND used = 0")->fetchColumn();
            send($uid, "✅ <b>{$n}</b> naye code add hue" . (count($items) > $n ? " (" . (count($items) - $n) . " duplicate skip)" : '')
                . "\n📦 Total stock: <b>{$left}</b>\n\nAur file/codes bhejo, ya <b>Done</b> dabao.",
                ['inline_keyboard' => [[cbtn('✅ Done', 'a:rwv:' . $rid)]]]);
            return true;                                       // state stays so more files can follow

        case 'rw_editname':
            if ($text === '') { send($uid, 'Naam text mein bhejo.'); return true; }
            $pdo->prepare('UPDATE rewards SET name = ? WHERE id = ?')->execute([mb_substr($text, 0, 60), (int)$st['rid']]);
            set_state($uid, null);
            [$t, $kb] = p_reward((int)$st['rid']);
            send($uid, "✅ Naam badal gaya.\n\n" . $t, $kb);
            return true;

        case 'rw_editcost':
            if (!ctype_digit($text) || (int)$text < 1) { send($uid, 'Sirf number bhejo (1 ya zyada).'); return true; }
            $pdo->prepare('UPDATE rewards SET cost = ? WHERE id = ?')->execute([(int)$text, (int)$st['rid']]);
            set_state($uid, null);
            [$t, $kb] = p_reward((int)$st['rid']);
            send($uid, "✅ Cost badal gaya.\n\n" . $t, $kb);
            return true;

        case 'setpts':
            if (!ctype_digit($text) || (int)$text < 1 || (int)$text > 1000) { send($uid, 'Number bhejo (1 se 1000).'); return true; }
            set_setting('points_per_referral', (string)(int)$text);
            set_state($uid, null);
            [$t, $kb] = p_points();
            send($uid, "✅ Ab har refer par <b>" . (int)$text . " point</b> milega.\n\n" . $t, $kb);
            return true;

        case 'setip':
            if (!ctype_digit($text) || (int)$text > 1000) { send($uid, 'Number bhejo (0 se 1000).'); return true; }
            set_setting('max_per_ip_24h', (string)(int)$text);
            set_state($uid, null);
            [$t, $kb] = p_device();
            send($uid, "✅ Ho gaya.\n\n" . $t, $kb);
            return true;

        case 'finduser':
            $q = ltrim($text, '@');
            if (ctype_digit($q)) { $s = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ?'); $s->execute([(int)$q]); }
            else { $s = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?)'); $s->execute([$q]); }
            $f = $s->fetch();
            if (!$f) { send($uid, '❌ User nahi mila (usne bot start kiya hona chahiye). Dobara bhejo ya /cancel.'); return true; }
            set_state($uid, null);
            [$t, $kb] = p_user((int)$f['user_id']);
            send($uid, $t, $kb);
            return true;

        case 'givepts':
            if (!preg_match('/^[+-]?\d{1,7}$/', $text) || (int)$text === 0) { send($uid, 'Number bhejo, jaise 10 ya -5.'); return true; }
            $target = (int)$st['uid'];
            $pdo->exec('BEGIN IMMEDIATE');
            $a = add_points($target, (int)$text, 'admin', (string)$uid);
            $pdo->exec('COMMIT');
            set_state($uid, null);
            if ($a > 0) send($target, E('points') . " Admin ne aapko <b>{$a} points</b> diye!");
            [$t, $kb] = p_user($target);
            send($uid, "✅ Points change: {$a}\n\n" . $t, $kb);
            return true;

        case 'setemoji':
            $ids = custom_emoji_ids($m);
            if (!$ids && preg_match('/^\d{5,25}$/', $text)) $ids = [$text];
            if (!$ids) { send($uid, '❌ Isme premium emoji nahi mila. Ek <b>premium</b> emoji bhejo (ya /cancel).'); return true; }
            if (isset(EMOJI_SLOTS[$st['slot'] ?? ''])) set_setting('emoji_' . $st['slot'], $ids[0]);
            set_state($uid, null);
            [$t, $kb] = p_emoji();
            send($uid, "✅ Set ho gaya: " . E($st['slot']) . "\n(Premium account se bana bot ho tabhi ye animated dikhega.)\n\n" . $t, $kb);
            return true;

        case 'emojiid':
            $ids = custom_emoji_ids($m);
            if (!$ids) { send($uid, 'Premium emoji nahi mila. Premium emoji bhejo, ya /cancel.'); return true; }
            send($uid, "🔍 Emoji ID:\n" . implode("\n", array_map(fn($i) => "<code>{$i}</code>", $ids)));
            return true;

        case 'bcast':
            $n = count_q('SELECT COUNT(*) FROM users WHERE banned = 0');
            set_state($uid, ['s' => 'bc_ok', 'from' => (int)$m['chat']['id'], 'mid' => (int)$m['message_id']]);
            send($uid, "📣 Ye message <b>{$n} users</b> ko jayega. Pakka?", ['inline_keyboard' => [[cbtn('✅ Bhejo', 'a:bcgo'), cbtn('❌ Cancel', 'a:home')]]]);
            return true;

        case 'bc_ok':
            send($uid, 'Upar ke buttons se Bhejo ya Cancel dabao.');
            return true;
    }
    set_state($uid, null);
    return false;
}

/** Small set of slash commands kept for convenience (the panel does the same with buttons). */
function handle_admin_cmd(int $uid, string $text): bool {
    if ($text === '' || $text[0] !== '/') return false;
    $p = preg_split('/\s+/', $text, 3);
    $cmd = strtolower(explode('@', $p[0])[0]);
    $pdo = db();
    $t = (int)($p[1] ?? 0);

    switch ($cmd) {
        case '/admin': case '/panel':
            open_panel($uid);
            return true;
        case '/ban': case '/unban':
            $pdo->prepare('UPDATE users SET banned = ? WHERE user_id = ?')->execute([$cmd === '/ban' ? 1 : 0, $t]);
            send($uid, 'Done.');
            return true;
        case '/allow': case '/disallow':
            $pdo->prepare('UPDATE users SET device_exempt = ? WHERE user_id = ?')->execute([$cmd === '/allow' ? 1 : 0, $t]);
            send($uid, 'Done.');
            return true;
        case '/givepoints':
            $n = (int)($p[2] ?? 0);
            if (!$t || !$n || !get_user($t)) { send($uid, 'Use: /givepoints <uid> <n>'); return true; }
            $pdo->exec('BEGIN IMMEDIATE');
            $a = add_points($t, $n, 'admin', (string)$uid);
            $pdo->exec('COMMIT');
            send($uid, "Done. Change: {$a}");
            return true;
        case '/devices':
            $st = $pdo->prepare('SELECT * FROM devices WHERE user_id = ?'); $st->execute([$t]);
            $d = $st->fetch();
            if (!$d) { send($uid, 'Koi device record nahi.'); return true; }
            $st = $pdo->prepare('SELECT user_id FROM devices WHERE fp_hash = ? AND user_id <> ?'); $st->execute([$d['fp_hash'], $d['user_id']]);
            $same = array_column($st->fetchAll(), 'user_id');
            send($uid, "fp: <code>" . h(substr($d['fp_hash'], 0, 16)) . "</code>\nIP: " . h($d['ip']) . "\nUA: " . h($d['ua'])
                . "\nSame fp users: " . ($same ? implode(', ', $same) : 'none'));
            return true;
        case '/resetdevice':
            $pdo->prepare('DELETE FROM devices WHERE user_id = ?')->execute([$t]);
            $pdo->prepare('UPDATE users SET verified_at = NULL WHERE user_id = ?')->execute([$t]);
            send($uid, 'Device reset. User ko dobara verify karna hoga.');
            return true;
        case '/adminstats':
            send($uid, stats_text());
            return true;
        case '/emojitest':
            emoji_test($uid);
            return true;
    }
    return false;
}
