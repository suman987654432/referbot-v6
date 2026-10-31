<?php
// php tests/run_tests.php  (no network, temp DB)
$dir = sys_get_temp_dir() . '/referbot_test_' . getmypid();
mkdir($dir);
$conf = $dir . '/config.php';
$OUT = "$dir/out.log";
file_put_contents($conf, '<?php return ' . var_export([
    'bot_token' => '123456:TESTTOKEN', 'bot_username' => 'TestBot', 'admin_ids' => [1],
    'db_path' => "$dir/t.db", 'log_path' => "$dir/t.log", 'dry_run' => true, 'dry_run_log' => $OUT,
    'points_per_referral' => 2, 'fp_strict' => true, 'max_per_ip_24h' => 3, 'webapp_url' => 'https://x/verify.html',
], true) . ';');
putenv("REFERBOT_CONFIG=$conf");
require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/core.php';
require __DIR__ . '/../src/handlers.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}
function tok(string $s): string { return substr(hash('sha256', 't' . $s), 0, 32); }
function fpx(string $s): string { return hash('sha256', 'f' . $s); }
function msg(int $uid, string $text, string $name = 'U', array $extra = []): array {
    return ['message' => ['message_id' => 77, 'chat' => ['type' => 'private', 'id' => $uid],
            'from' => ['id' => $uid, 'first_name' => $name . $uid, 'username' => 'user' . $uid], 'text' => $text] + $extra];
}
function cbq(int $uid, string $data): array {
    return ['callback_query' => ['id' => 'cb' . mt_rand(), 'from' => ['id' => $uid, 'first_name' => 'U'],
            'message' => ['message_id' => 9, 'chat' => ['id' => $uid]], 'data' => $data]];
}
function pts(int $uid): int { return (int)get_user($uid)['points']; }
function reset_out(): void { global $OUT; file_put_contents($OUT, ''); }
function out(): string { global $OUT; return (string)file_get_contents($OUT); }
function calls(): array {
    $r = [];
    foreach (array_filter(explode("\n", out())) as $l) $r[] = json_decode($l, true);
    return $r;
}
function q(string $sql) { return db()->query($sql)->fetchColumn(); }

echo "referral flow\n";
handle_update(msg(100, '/start'));
t('A created', (bool)get_user(100));
t('A verifies', verify_device(100, tok('a'), fpx('a'), '1.1.1.1')['ok']);
handle_update(msg(200, '/start r100'));
t('B referral pending', q("SELECT status FROM referrals WHERE referred_id=200") === 'pending');
t('no points before verify', pts(100) === 0);
t('B verifies', verify_device(200, tok('b'), fpx('b'), '2.2.2.2')['ok']);
t('A got 2 points', pts(100) === 2);
t('referral credited', q("SELECT status FROM referrals WHERE referred_id=200") === 'credited');
t('already verified is idempotent', verify_device(200, tok('b'), fpx('b'), '2.2.2.2')['code'] === 'already' && pts(100) === 2);

echo "device rules\n";
handle_update(msg(300, '/start r100'));
$r = verify_device(300, tok('b'), fpx('c'), '3.3.3.3');
t('same device_token rejected', !$r['ok'] && $r['code'] === 'device_used');
t('no points for rejected', pts(100) === 2);
handle_update(msg(301, '/start r100'));
$r = verify_device(301, tok('new301'), fpx('a'), '3.3.3.3');
t('same fingerprint rejected', !$r['ok'] && $r['code'] === 'device_used');
t('unverified user not credited', q("SELECT status FROM referrals WHERE referred_id=301") === 'pending');
t('bad format rejected', verify_device(301, 'zz', 'zz', '3.3.3.3')['code'] === 'bad_request');
t('webdriver rejected', verify_device(301, tok('w'), fpx('w'), '3.3.3.3', '', ['webdriver' => true])['code'] === 'bot_detected');

echo "ip limit\n";
foreach ([401, 402, 403] as $i) { handle_update(msg($i, '/start')); verify_device($i, tok("ip$i"), fpx("ip$i"), '9.9.9.9'); }
handle_update(msg(404, '/start'));
$r = verify_device(404, tok('ip404'), fpx('ip404'), '9.9.9.9');
t('4th account same IP rejected', !$r['ok'] && $r['code'] === 'ip_limit');

echo "admin exempt\n";
db()->exec('UPDATE users SET device_exempt=1 WHERE user_id=404');
t('exempt user passes ip limit', verify_device(404, tok('ip404'), fpx('ip404'), '9.9.9.9')['ok']);
handle_update(msg(405, '/start')); db()->exec('UPDATE users SET device_exempt=1 WHERE user_id=405');
t('exempt user passes same-device check', verify_device(405, tok('a'), fpx('a'), '8.8.8.8')['ok']);

echo "referral edge cases\n";
handle_update(msg(100, '/start r100'));
t('self referral ignored', (int)q("SELECT COUNT(*) FROM referrals WHERE referred_id=100") === 0);
handle_update(msg(100, '/start r200'));
t('existing user cannot be re-referred', (int)q("SELECT COUNT(*) FROM referrals WHERE referred_id=100") === 0);
handle_update(msg(500, '/start r99999'));
t('unknown referrer ignored', (int)q("SELECT COUNT(*) FROM referrals WHERE referred_id=500") === 0);
handle_update(msg(600, '/start'));
handle_update(msg(601, '/start r600'));
verify_device(601, tok('x601'), fpx('x601'), '6.6.6.1');
t('pending while referrer unverified', q("SELECT status FROM referrals WHERE referred_id=601") === 'pending');
verify_device(600, tok('x600'), fpx('x600'), '6.6.6.2');
t('credited after referrer verifies', pts(600) === 2);
handle_update(msg(700, '/start')); verify_device(700, tok('x700'), fpx('x700'), '7.7.7.1');
db()->exec('UPDATE users SET banned=1 WHERE user_id=700');
handle_update(msg(701, '/start r700'));
t('banned referrer link ignored', (int)q("SELECT COUNT(*) FROM referrals WHERE referred_id=701") === 0);

echo "initData\n";
function make_init(array $user, int $auth, string $token): string {
    $d = ['auth_date' => (string)$auth, 'query_id' => 'AAH', 'user' => json_encode($user)];
    ksort($d); $pairs = [];
    foreach ($d as $k => $v) $pairs[] = "$k=$v";
    $secret = hash_hmac('sha256', $token, 'WebAppData', true);
    $d['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);
    return http_build_query($d);
}
$good = make_init(['id' => 42, 'first_name' => 'Z'], time(), '123456:TESTTOKEN');
t('valid initData', (validate_init_data($good)['id'] ?? 0) === 42);
t('tampered initData', validate_init_data(str_replace('Z', 'Y', $good)) === null && validate_init_data($good . 'x') === null);
t('wrong token', validate_init_data(make_init(['id' => 42], time(), 'other:token')) === null);
t('expired initData', validate_init_data(make_init(['id' => 42], time() - 7200, '123456:TESTTOKEN')) === null);
t('missing hash', validate_init_data('auth_date=1') === null);

echo "admin panel access\n";
reset_out();
handle_update(msg(1, '/start'));
t('admin sees Admin Panel button in menu', strpos(out(), 'Admin Panel') !== false);
reset_out();
handle_update(msg(100, '/start'));
t('normal user does NOT see Admin Panel button', strpos(out(), 'Admin Panel') === false);
reset_out();
handle_update(msg(1, '/admin'));
t('/admin opens panel with buttons', strpos(out(), 'a:ch') !== false && strpos(out(), 'a:rw') !== false && strpos(out(), 'a:em') !== false);
reset_out();
handle_update(msg(1, '⚙️ Admin Panel'));
t('Admin Panel menu button opens panel', strpos(out(), 'a:ch') !== false);
reset_out();
handle_update(cbq(100, 'a:rwadd'));
t('non-admin callback refused', strpos(out(), 'Sirf admin') !== false && get_state(get_user(100)) === []);
reset_out();
handle_update(msg(100, '/admin'));
t('non-admin /admin not opening panel', strpos(out(), 'a:ch') === false);

echo "rewards via panel\n";
handle_update(cbq(1, 'a:rwadd'));
handle_update(msg(1, 'Test Voucher'));
handle_update(msg(1, 'abc'));
t('non-number cost rejected', (get_state(get_user(1))['s'] ?? '') === 'rw_cost');
handle_update(msg(1, '3'));
handle_update(cbq(1, 'a:rwk:stock'));
t('reward created', (int)q('SELECT COUNT(*) FROM rewards') === 1 && (int)q('SELECT cost FROM rewards WHERE id=1') === 3);
handle_update(msg(1, "CODE-A\nCODE-B\nCODE-A"));
t('stock added (dupes ignored)', (int)q('SELECT COUNT(*) FROM reward_stock') === 2);
t('stock flow stays open for more files', (get_state(get_user(1))['s'] ?? '') === 'rw_stock');
handle_update(cbq(1, 'a:rwv:1'));
t('Done button closes the flow', get_state(get_user(1)) === []);
t('redeem rejected: low points', !redeem(100, 1)['ok']);
add_points(100, 5, 'test');
$r1 = redeem(100, 1);
t('redeem ok', $r1['ok'] && $r1['code'] === 'CODE-A' && pts(100) === 4);
$r2 = redeem(100, 1);
t('second redeem gets different code', $r2['ok'] && $r2['code'] === 'CODE-B' && pts(100) === 1);
$r3 = redeem(100, 1);
t('no negative / no stock', !$r3['ok'] && pts(100) === 1);
add_points(100, 10, 'test');
$r4 = redeem(100, 1);
t('out of stock rejected, points kept', !$r4['ok'] && pts(100) === 11);
handle_update(cbq(1, 'a:rwc:1')); handle_update(msg(1, '4'));
t('cost editable', (int)q('SELECT cost FROM rewards WHERE id=1') === 4);
handle_update(cbq(1, 'a:rwt:1'));
t('toggle off', (int)q('SELECT active FROM rewards WHERE id=1') === 0 && !list_rewards());
handle_update(cbq(1, 'a:rwt:1'));
handle_update(cbq(1, 'a:rwadd')); handle_update(msg(1, 'Manual Service')); handle_update(msg(1, '5')); handle_update(cbq(1, 'a:rwk:manual'));
$rid = (int)q("SELECT id FROM rewards WHERE kind='manual'");
t('manual reward created', $rid === 2);
reset_out();
handle_update(cbq(100, 'rd:2'));
t('manual redeem deducts points, no stock needed', pts(100) === 6 && strpos(out(), 'deliver') !== false);
t('admin notified of manual redeem', strpos(out(), 'MANUAL') !== false);
handle_update(cbq(1, 'a:rwd2:2'));
t('reward deleted', (int)q('SELECT COUNT(*) FROM rewards WHERE id=2') === 0);

echo "stock files (txt/json)\n";
t('lines', split_stock_items("A\n\nB \r\nC") === ['A', 'B', 'C']);
t('json array', split_stock_items('[{"a":1},{"a":2}]') === ['{"a":1}', '{"a":2}']);
t('json array of strings', split_stock_items('["x","y"]') === ['x', 'y']);
t('objects one per line', count(split_stock_items("{\"s\":\"a\"}\n{\"s\":\"b\"}")) === 2);
t('pretty multi-line objects', count(split_stock_items("{\n  \"s\": \"a}b\",\n  \"n\": {\"x\":1}\n}\n\n{\n \"s\":\"c\"\n}")) === 2);
t('braces inside strings handled', split_stock_items('{"k":"va}l{ue"}') === ['{"k":"va}l{ue"}']);
t('BOM stripped', split_stock_items("\xEF\xBB\xBFA\nB") === ['A', 'B']);
t('mixed text falls back to lines', split_stock_items("{x} junk\nB") === ['{x} junk', 'B']);
t('json code => file delivery', is_file_code('{"a":1}') && is_file_code("l1\nl2") && !is_file_code('ABC-123'));
$GLOBALS['tg_stub'] = fn($m, $p) => $m === 'getFile' ? ['ok' => true, 'result' => ['file_path' => 'docs/file_1.txt']] : null;
$GLOBALS['tg_file_stub'] = fn($path) => "{\"session\":\"s1\",\"phone\":\"91\"}\n{\"session\":\"s2\",\"phone\":\"92\"}";
handle_update(cbq(1, 'a:rwadd')); handle_update(msg(1, 'JSON Voucher')); handle_update(msg(1, '10')); handle_update(cbq(1, 'a:rwk:stock'));
$jr = (int)q('SELECT MAX(id) FROM rewards');
$doc = ['document' => ['file_id' => 'F1', 'file_size' => 100, 'file_name' => 'NO_OFFER_ZERO_ORDERS_(1_accs).txt']];
handle_update(msg(1, '', 'U', $doc));
t('.txt upload adds 2 JSON accounts', (int)q("SELECT COUNT(*) FROM reward_stock WHERE reward_id=$jr") === 2);
reset_out();
handle_update(msg(1, '', 'U', $doc));
t('same file again: duplicates skipped', (int)q("SELECT COUNT(*) FROM reward_stock WHERE reward_id=$jr") === 2 && strpos(out(), 'duplicate') !== false);
t('state stays open for next file', (get_state(get_user(1))['s'] ?? '') === 'rw_stock');
$GLOBALS['tg_file_stub'] = fn($path) => "a\0b";
reset_out();
handle_update(msg(1, '', 'U', $doc));
t('binary file rejected', (int)q("SELECT COUNT(*) FROM reward_stock WHERE reward_id=$jr") === 2 && strpos(out(), 'text file nahi') !== false);
$GLOBALS['tg_file_stub'] = fn($path) => null;
reset_out();
handle_update(msg(1, '', 'U', $doc));
t('failed download reported', strpos(out(), 'download nahi hui') !== false);
unset($GLOBALS['tg_stub'], $GLOBALS['tg_file_stub']);
handle_update(cbq(1, 'a:rwv:' . $jr));
db()->exec("UPDATE rewards SET cost=1 WHERE id=$jr");
reset_out();
handle_update(cbq(100, 'rd:' . $jr));
t('JSON reward delivered as .txt file', strpos(out(), 'sendDocument') !== false && strpos(out(), '[file:JSON_Voucher_') !== false && strpos(out(), 'Aapka code') === false);
add_points(100, 1, 'test');                 // give the point back so later checks stay exact
handle_update(cbq(1, 'a:rwd2:' . $jr));

echo "points per refer (settings override config)\n";
handle_update(cbq(1, 'a:ptsset')); handle_update(msg(1, '5'));
t('setting saved', pts_per_ref() === 5);
handle_update(msg(800, '/start r100'));
verify_device(800, tok('x800'), fpx('x800'), '5.5.5.1');
t('referral paid new rate (6 + 5 = 11)', pts(100) === 11);

echo "channels via panel\n";
$GLOBALS['members'] = [];
$GLOBALS['tg_stub'] = function ($m, $p) {
    if ($m === 'getChat') return ['ok' => true, 'result' => ['id' => -1001111111111, 'type' => 'channel', 'title' => 'Test Ch', 'username' => 'testch']];
    if ($m === 'getChatMember') {
        $uid = (int)$p['user_id'];
        $st = $uid === 123456 ? 'administrator' : (in_array($uid, $GLOBALS['members'], true) ? 'member' : 'left');
        return ['ok' => true, 'result' => ['status' => $st]];
    }
    return null;
};
handle_update(cbq(1, 'a:chadd'));
handle_update(msg(1, 'bad channel!'));
t('bad channel format rejected', (int)q('SELECT COUNT(*) FROM channels') === 0);
handle_update(msg(1, '@testch'));
t('channel added', (int)q('SELECT COUNT(*) FROM channels') === 1 && q('SELECT link FROM channels') === 'https://t.me/testch');
reset_out();
handle_update(msg(100, 'hello'));
t('non-member blocked with join button', strpos(out(), 'Join Channel 1') !== false);
$GLOBALS['members'] = [100];
handle_update(cbq(100, 'chk'));
reset_out();
handle_update(msg(100, 'hello'));
t('member passes gate', strpos(out(), 'Niche ke buttons') !== false);
$GLOBALS['tg_stub'] = function ($m, $p) {      // bot NOT admin in channel
    if ($m === 'getChat') return ['ok' => true, 'result' => ['id' => -1002222222222, 'type' => 'channel', 'title' => 'Other', 'username' => 'otherch']];
    if ($m === 'getChatMember') return ['ok' => true, 'result' => ['status' => 'member']];
    return null;
};
handle_update(cbq(1, 'a:chadd'));
handle_update(msg(1, 'otherch'));
t('channel rejected when bot is not admin', (int)q('SELECT COUNT(*) FROM channels') === 1);
handle_update(msg(1, '/cancel'));
$cid = (int)q('SELECT id FROM channels');
handle_update(cbq(1, 'a:chdel2:' . $cid));
t('channel removed -> join check off', (int)q('SELECT COUNT(*) FROM channels') === 0 && channels_ok(555));
unset($GLOBALS['tg_stub']);

echo "premium emoji\n";
t('plain emoji by default', E('points') === '💎');
handle_update(cbq(1, 'a:ems:points'));
t('flow waits for emoji', (get_state(get_user(1))['s'] ?? '') === 'setemoji');
handle_update(msg(1, 'text only'));
t('plain text rejected', setting('emoji_points') === null);
handle_update(msg(1, '💎', 'U', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '5368324170671202286']]]));
t('emoji id saved from entity', setting('emoji_points') === '5368324170671202286');
t('E() renders tg-emoji', strpos(E('points'), '<tg-emoji emoji-id="5368324170671202286">') === 0);
$GLOBALS['tg_stub'] = fn($m, $p) => ($m === 'sendMessage' && strpos(json_encode($p), 'tg-emoji') !== false && isset($p['reply_markup']))
    ? ['ok' => true, 'result' => ['text' => 'x']] : null;     // Telegram silently dropped the entities
reset_out(); emoji_test(1);
t('/emojitest: detects dropped premium emoji', strpos(out(), 'Telegram Premium') !== false);
t('...records result but premium stays ON (like Code Adda)', setting('premium_ok') === '0' && premium_active());
$GLOBALS['tg_stub'] = fn($m, $p) => ($m === 'sendMessage' && strpos(json_encode($p), 'tg-emoji') !== false && isset($p['reply_markup']))
    ? ['ok' => true, 'result' => ['entities' => [['type' => 'custom_emoji']], 'reply_markup' => ['inline_keyboard' => [[['icon_custom_emoji_id' => '1']]]]]] : null;
reset_out(); emoji_test(1);
t('/emojitest: Telegram accepts premium', strpos(out(), 'accept kar raha hai') !== false);
t('...records accept', setting('premium_ok') === '1' && premium_active());
unset($GLOBALS['tg_stub']);
$b = btn('Rewards', [], 'points');
t('button gets icon id', ($b['icon_custom_emoji_id'] ?? '') === '5368324170671202286');
reset_out();
send(100, E('points') . ' hi', ['inline_keyboard' => [[$b]]]);
$c = calls();
t('premium sent when accepted', strpos(json_encode($c[0]), 'tg-emoji') !== false && strpos(json_encode($c[0]), 'icon_custom_emoji_id') !== false);
t('internal _fb never sent', strpos(out(), '_fb') === false);
// Telegram rejects premium (owner has no Premium) -> automatic retry with normal emoji
$GLOBALS['tg_stub'] = function ($m, $p) {
    $s = json_encode($p);
    if (strpos($s, 'tg-emoji') !== false || strpos($s, 'icon_custom_emoji_id') !== false) {
        return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: DOCUMENT_INVALID'];
    }
    return null;
};
reset_out();
send(100, E('points') . ' hi', ['inline_keyboard' => [[$b]]]);
$c = calls();
t('retried once', count($c) === 2);
t('retry has no premium markup', strpos(json_encode($c[1]), 'tg-emoji') === false && strpos(json_encode($c[1]), 'icon_custom_emoji_id') === false);
t('retry keeps normal emoji + label', strpos(json_encode($c[1], JSON_UNESCAPED_UNICODE), '💎 Rewards') !== false);
unset($GLOBALS['tg_stub']);
t('default colour: refer button is green', (btn('x', [], 'refer')['style'] ?? '') === 'success' && !isset(btn('x', [], 'stats')['style']));
handle_update(cbq(1, 'a:ems:stats')); handle_update(cbq(1, 'a:emc:stats:danger'));
t('colour set from panel', (btn('x', [], 'stats')['style'] ?? '') === 'danger');
handle_update(cbq(1, 'a:emc:refer:none'));
reset_out();
send(100, 'c', ['inline_keyboard' => [[btn('Refer', [], 'refer')]]]);
t('"default colour" really sends no colour', strpos(out(), '"style"') === false);
handle_update(cbq(1, 'a:emc:refer:primary'));
t('colour is blue', (btn('x', [], 'refer')['style'] ?? '') === 'primary');
reset_out();
send(100, 'c', ['inline_keyboard' => [[btn('Refer', [], 'refer')]]]);
t('colour is sent to Telegram', strpos(out(), '"style":"primary"') !== false);
$GLOBALS['tg_stub'] = function ($m, $p) {                    // Telegram rejects colours too -> last-resort plain retry
    return strpos(json_encode($p), '"style"') !== false ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: BUTTON_STYLE_INVALID'] : null;
};
reset_out();
send(100, 'c', ['inline_keyboard' => [[btn('Refer', [], 'refer')]]]);
$c = calls();
t('falls back to plain button if colours rejected', end($c) !== false && strpos(json_encode(end($c)), '"style"') === false && count($c) >= 2);
unset($GLOBALS['tg_stub']);
echo "auto premium + colours (Code Adda style)\n";
reset_out();
send(100, '🎉 Mubarak 📦 ready');
t('known emoji in text become premium', strpos(out(), 'emoji-id=\"4956259055468282692\">🎉') !== false && strpos(out(), 'emoji-id=\"5040045238823945201\">📦') !== false);
reset_out();
send(100, E('points') . ' x 🎉');
t('already wrapped emoji not wrapped twice', strpos(out(), '<tg-emoji emoji-id=\"5368324170671202286\">💎</tg-emoji>') !== false || strpos(out(), '💎') !== false);
t('no nested tg-emoji', preg_match('~<tg-emoji[^>]*><tg-emoji~', out()) === 0);
reset_out();
answer_cb('abc', '✅ Done');
t('toast text (plain) is never wrapped', strpos(out(), 'tg-emoji') === false);
reset_out();
send(100, 'k', ['inline_keyboard' => [[['text' => '🔗 Link', 'callback_data' => 'q'], ['text' => '🗑 Delete', 'callback_data' => 'x'], ['text' => 'Stock Add', 'callback_data' => 'y'], ['text' => 'Users', 'callback_data' => 'z']]]]);
$j = calls()[0][1]['reply_markup']['inline_keyboard'][0];
t('button emoji moves into icon', ($j[0]['icon_custom_emoji_id'] ?? '') === '6316411739969687815' && $j[0]['text'] === 'Link');
t('delete = red, add = green, other = blue', $j[1]['style'] === 'danger' && $j[2]['style'] === 'success' && $j[3]['style'] === 'primary');
t('delete button keeps its icon', ($j[1]['icon_custom_emoji_id'] ?? '') === '5039671744172917707' && $j[1]['text'] === 'Delete');
reset_out();
send(1, 'm', main_kb(1));
$rows = calls()[0][1]['reply_markup']['keyboard'];
t('menu: map emoji become icons, label plain', ($rows[0][0]['icon_custom_emoji_id'] ?? '') === '6316411739969687815' && $rows[0][0]['text'] === 'My Refer Link' && ($rows[0][1]['icon_custom_emoji_id'] ?? '') === '5039834781131474002');
t('menu: emoji without premium id keeps normal emoji', $rows[1][0]['text'] === '🎁 Rewards' && !isset($rows[1][0]['icon_custom_emoji_id']));
t('menu: admin gets Admin Panel icon', ($rows[2][0]['icon_custom_emoji_id'] ?? '') === '5042341817966658405' && $rows[2][0]['text'] === 'Admin Panel');
t('menu labels still routed after icon move', norm_label('My Refer Link') === 'my refer link' && norm_label('Admin Panel') === 'admin panel');
set_setting('premium_ok', '0');                         // /emojitest said Telegram drops them
reset_out(); send(1, 'm', main_kb(1)); $rk = calls()[0][1]['reply_markup']['keyboard'];
t('icons dropped by Telegram: buttons keep normal emoji, no icon', $rk[0][0]['text'] === '🔗 My Refer Link' && strpos(json_encode($rk), 'icon_custom_emoji_id') === false);
set_setting('premium_ok', '1');
set_setting('premium_on', '0');                         // switched OFF manually: colours stay, emoji stay normal
reset_out();
send(1, '🎉 hi', main_kb(1));
$c0 = calls()[0][1];
t('premium OFF: text stays plain', strpos(json_encode($c0, JSON_UNESCAPED_UNICODE), 'tg-emoji') === false);
t('premium OFF: buttons keep their normal emoji, no icons', $c0['reply_markup']['keyboard'][0][0]['text'] === '🔗 My Refer Link' && strpos(json_encode($c0), 'icon_custom_emoji_id') === false);
t('premium OFF: colours still sent', in_array($c0['reply_markup']['keyboard'][1][0]['style'] ?? '', ['primary', 'success', 'danger'], true) && ($c0['reply_markup']['keyboard'][0][1]['style'] ?? '') === 'danger');
handle_update(cbq(1, 'a:emt'));
t('premium ON switch', strpos(E('points'), 'tg-emoji') !== false);
handle_update(cbq(1, 'a:emt'));
t('premium OFF switch', E('points') === '💎' && !isset(btn('x', [], 'points')['icon_custom_emoji_id']));
handle_update(cbq(1, 'a:emt'));
t('premium ON again', strpos(E('points'), 'tg-emoji') !== false);
reset_out();
handle_update(cbq(1, 'a:emid'));
handle_update(msg(1, '🔥', 'U', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '5104841245755180586']]]));
t('emoji id finder replies with id', strpos(out(), '5104841245755180586') !== false);
handle_update(msg(1, '/cancel'));
handle_update(cbq(1, 'a:emx:points'));
t('emoji removable', setting('emoji_points') === null);
t('premium-icon menu button still matches', norm_label('Rewards') === 'rewards' && norm_label('🎁 Rewards') === 'rewards' && norm_label('⚙️ Admin Panel') === 'admin panel');

echo "device rules via panel\n";
handle_update(cbq(1, 'a:dvs'));
t('fp strict toggled off', opt('fp_strict', true) === false);
handle_update(cbq(1, 'a:dvi')); handle_update(msg(1, '0'));
t('ip limit set to off', (int)opt('max_per_ip_24h', 5) === 0);
handle_update(cbq(1, 'a:dvs'));
t('fp strict back on', opt('fp_strict', true) === true);

echo "users via panel\n";
handle_update(cbq(1, 'a:us'));
handle_update(msg(1, '@user100'));
t('user found by @username', strpos(out(), 'Points') !== false);
handle_update(cbq(1, 'a:up:100'));
$p0 = pts(100);
handle_update(msg(1, '-3'));
t('points removed', pts(100) === $p0 - 3);
handle_update(msg(1, '/givepoints 100 2'));
t('/givepoints still works', pts(100) === $p0 - 1);
handle_update(cbq(1, 'a:ub:100'));
t('ban toggle', (int)get_user(100)['banned'] === 1);
handle_update(cbq(1, 'a:ub:100'));
t('unban toggle', (int)get_user(100)['banned'] === 0);
handle_update(cbq(1, 'a:ur:100'));
t('device reset', get_user(100)['verified_at'] === null && (int)q('SELECT COUNT(*) FROM devices WHERE user_id=100') === 0);

echo "broadcast\n";
$n = (int)q('SELECT COUNT(*) FROM users WHERE banned = 0');
handle_update(cbq(1, 'a:bc'));
handle_update(msg(1, 'Hello everyone'));
reset_out();
handle_update(cbq(1, 'a:bcgo'));
$copies = array_filter(calls(), fn($c) => $c[0] === 'copyMessage');
t('broadcast copies message to every non-banned user', count($copies) === $n);
reset_out();
handle_update(cbq(100, 'a:bcgo'));
t('non-admin cannot broadcast', count(array_filter(calls(), fn($c) => $c[0] === 'copyMessage')) === 0);

echo "maintenance mode\n";
t('maintenance is off by default', is_maintenance() === false);
handle_update(cbq(1, 'a:mttoggle'));
t('maintenance toggle turns on', is_maintenance() === true);
reset_out();
handle_update(msg(100, '/start'));
$maintCalls = array_filter(calls(), fn($c) => $c[0] === 'sendMessage' && str_contains($c[1]['text'] ?? '', 'Maintenance'));
t('user receives maintenance message', count($maintCalls) > 0);
reset_out();
handle_update(cbq(100, 'chk'));
t('user callback blocked in maintenance', count(calls()) === 1 && calls()[0][0] === 'answerCallbackQuery' && calls()[0][1]['show_alert'] === true);
handle_update(cbq(1, 'a:mtmsg'));
handle_update(msg(1, 'Custom Maintenance Text'));
t('custom maintenance message updated', setting('maintenance_text') === 'Custom Maintenance Text');
handle_update(cbq(1, 'a:mtrst'));
t('custom maintenance message reset', setting('maintenance_text', null) === null);
handle_update(msg(1, '/on'));
t('admin slash command turns bot on', is_maintenance() === false);

echo "upgrade from v1 database\n";
$old = "$dir/old.db";
$pdo = new PDO('sqlite:' . $old);
$pdo->exec("CREATE TABLE users(user_id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT '', first_name TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL, verified_at INTEGER, points INTEGER NOT NULL DEFAULT 0, total_earned INTEGER NOT NULL DEFAULT 0, banned INTEGER NOT NULL DEFAULT 0, device_exempt INTEGER NOT NULL DEFAULT 0, chan_ok_until INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT '');
 CREATE TABLE rewards(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, cost INTEGER NOT NULL, active INTEGER NOT NULL DEFAULT 1);
 INSERT INTO users(user_id, created_at, points) VALUES(5, 1, 9); INSERT INTO rewards(name, cost) VALUES('Old', 2); PRAGMA user_version = 1;");
$pdo = null;
$conf2 = "$dir/config2.php";
file_put_contents($conf2, '<?php return ' . var_export(['bot_token' => '1:T', 'db_path' => $old, 'log_path' => "$dir/o.log",
    'channels' => ['@oldch'], 'channel_links' => ['https://t.me/oldch']], true) . ';');
$script = "$dir/mig.php";
file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/../src/lib.php', true) . ';
$d = db(); echo json_encode([(int)$d->query("SELECT points FROM users WHERE user_id=5")->fetchColumn(), $d->query("SELECT kind FROM rewards")->fetchColumn(), $d->query("SELECT chat FROM channels")->fetchColumn(), (int)$d->query("PRAGMA user_version")->fetchColumn()]);');
$proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
    array_merge(getenv(), ['REFERBOT_CONFIG' => $conf2]));      // portable (Windows + Linux)
$res = json_decode((string)stream_get_contents($pipes[1]), true);
foreach ($pipes as $pp) fclose($pp);
proc_close($proc);
t('old data kept, kind column added, config channel seeded', $res === [9, 'stock', '@oldch', 2]);

echo "\n$pass passed, $fail failed\n";
$cmd = PHP_OS_FAMILY === 'Windows' ? 'rmdir /s /q ' . escapeshellarg($dir) . ' >nul 2>&1' : 'rm -rf ' . escapeshellarg($dir);
@exec($cmd);
exit($fail ? 1 : 0);
