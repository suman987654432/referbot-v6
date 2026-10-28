<?php
/**
 * lib.php - config, database, settings, Telegram helper (with premium-emoji fallback), Mini App validation.
 * Nothing in here prints output; safe to include from CLI, webhook and API.
 */

const SCHEMA_VERSION = 2;

function cfg(string $key, $default = null) {
    static $c = null;
    if ($c === null) {
        $c = [];
        $f = getenv('REFERBOT_CONFIG') ?: (is_file(__DIR__ . '/../config.php') ? __DIR__ . '/../config.php' : '/etc/referbot/config.php');
        if (is_file($f)) {
            $c = require $f;
        }
    }

    // Direct environment variables (Render.com)
    $envKey = strtoupper($key);
    $envVal = getenv($envKey);
    if ($envVal !== false && $envVal !== '') {
        if ($key === 'admin_ids') {
            return array_map('intval', explode(',', $envVal));
        }
        if ($key === 'channels' || $key === 'channel_links') {
            return array_filter(array_map('trim', explode(',', $envVal)));
        }
        if ($key === 'fp_strict' || $key === 'dry_run') {
            return filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
        }
        if ($key === 'points_per_referral' || $key === 'max_per_ip_24h') {
            return (int)$envVal;
        }
        return $envVal;
    }

    return $c[$key] ?? $default;
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function log_line(string $msg): void {
    $f = cfg('log_path', '/var/lib/referbot/referbot.log');
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------------------------------------------------------------- DB */

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . cfg('db_path', '/var/lib/referbot/referbot.db'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('PRAGMA foreign_keys=ON');
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < SCHEMA_VERSION) {
        init_schema($pdo);
    }
    return $pdo;
}

function init_schema(PDO $pdo): void {
    // every statement is IF NOT EXISTS, so this upgrades v1 databases in place (data is kept)
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
      user_id       INTEGER PRIMARY KEY,
      username      TEXT NOT NULL DEFAULT '',
      first_name    TEXT NOT NULL DEFAULT '',
      created_at    INTEGER NOT NULL,
      verified_at   INTEGER,
      points        INTEGER NOT NULL DEFAULT 0 CHECK(points >= 0),
      total_earned  INTEGER NOT NULL DEFAULT 0,
      banned        INTEGER NOT NULL DEFAULT 0,
      device_exempt INTEGER NOT NULL DEFAULT 0,
      chan_ok_until INTEGER NOT NULL DEFAULT 0,
      state         TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS referrals(
      referred_id INTEGER PRIMARY KEY,
      referrer_id INTEGER NOT NULL,
      created_at  INTEGER NOT NULL,
      status      TEXT NOT NULL DEFAULT 'pending',
      credited_at INTEGER
    );
    CREATE INDEX IF NOT EXISTS idx_ref_referrer ON referrals(referrer_id, status);
    CREATE TABLE IF NOT EXISTS devices(
      user_id      INTEGER PRIMARY KEY,
      device_token TEXT NOT NULL UNIQUE,
      fp_hash      TEXT NOT NULL,
      ip           TEXT NOT NULL,
      ua           TEXT NOT NULL DEFAULT '',
      created_at   INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_dev_fp ON devices(fp_hash);
    CREATE INDEX IF NOT EXISTS idx_dev_ip ON devices(ip, created_at);
    CREATE TABLE IF NOT EXISTS ledger(
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id    INTEGER NOT NULL,
      delta      INTEGER NOT NULL,
      reason     TEXT NOT NULL,
      ref        TEXT NOT NULL DEFAULT '',
      created_at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_ledger_user ON ledger(user_id, created_at);
    CREATE TABLE IF NOT EXISTS rewards(
      id     INTEGER PRIMARY KEY AUTOINCREMENT,
      name   TEXT NOT NULL,
      cost   INTEGER NOT NULL CHECK(cost > 0),
      active INTEGER NOT NULL DEFAULT 1,
      kind   TEXT NOT NULL DEFAULT 'stock'
    );
    CREATE TABLE IF NOT EXISTS reward_stock(
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      reward_id  INTEGER NOT NULL,
      code       TEXT NOT NULL,
      used       INTEGER NOT NULL DEFAULT 0,
      claimed_by INTEGER,
      claimed_at INTEGER,
      UNIQUE(reward_id, code)
    );
    CREATE INDEX IF NOT EXISTS idx_stock_avail ON reward_stock(reward_id, used, id);
    CREATE TABLE IF NOT EXISTS verify_log(
      id         INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id    INTEGER,
      ip         TEXT,
      fp_hash    TEXT,
      result     TEXT,
      created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS settings(
      k TEXT PRIMARY KEY,
      v TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS channels(
      id    INTEGER PRIMARY KEY AUTOINCREMENT,
      chat  TEXT NOT NULL UNIQUE,
      title TEXT NOT NULL DEFAULT '',
      link  TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS seen_updates(
      update_id  INTEGER PRIMARY KEY,
      created_at INTEGER NOT NULL
    );
    ");
    // v1 -> v2: rewards.kind
    $cols = array_column($pdo->query('PRAGMA table_info(rewards)')->fetchAll(), 'name');
    if (!in_array('kind', $cols, true)) {
        $pdo->exec("ALTER TABLE rewards ADD COLUMN kind TEXT NOT NULL DEFAULT 'stock'");
    }
    // seed channels from config.php once (later they are managed from the Admin Panel)
    if (!(int)$pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn()) {
        $links = array_values((array)cfg('channel_links', []));
        $ins = $pdo->prepare('INSERT OR IGNORE INTO channels(chat, title, link) VALUES(?,?,?)');
        foreach (array_values((array)cfg('channels', [])) as $i => $ch) {
            $ch = (string)$ch;
            $link = $links[$i] ?? (strpos($ch, '@') === 0 ? 'https://t.me/' . substr($ch, 1) : '');
            $ins->execute([$ch, $ch, $link]);
        }
    }
    $pdo->exec('PRAGMA user_version = ' . SCHEMA_VERSION);
}

/* ---------------------------------------------------------- settings */

function setting(string $k, $default = null) {
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $k, string $v): void {
    db()->prepare('INSERT OR REPLACE INTO settings(k, v) VALUES(?,?)')->execute([$k, $v]);
}

function del_setting(string $k): void {
    db()->prepare('DELETE FROM settings WHERE k = ?')->execute([$k]);
}

/** Value changed from the Admin Panel (settings table) wins over config.php. */
function opt(string $k, $default = null) {
    $d = cfg($k, $default);
    $v = setting($k, null);
    if ($v === null) return $d;
    if (is_bool($d)) return $v === '1';
    if (is_int($d))  return (int)$v;
    return $v;
}

/* ------------------------------------------------------ premium emoji */

/** Same emoji -> premium emoji ID map that Code Adda uses (custom emoji IDs are global). */
const EMOJI_MAP = [
    "✅" => "5042225965518816316", "⚠️" => "5042334757040423886", "🆘" => "5364288683824401154", "💰" => "5039623284056917259", "⬅" => "5042176294222037888",
    "📦" => "5040045238823945201", "🧾" => "5042050649248760772", "❌" => "5042302287087666158",
    "🔁" => "5042020176455795565", "➕" => "5039686583284925280", "📋" => "5039774728898741491",
    "🎟" => "5039727497143387500", "🛍" => "5040030395416969985", "⏳" => "5042101437237036298",
    "🛠" => "5042341817966658405", "⚙" => "5042341817966658405", "🏪" => "5042102141611672423", "📣" => "5039895103947146186",
    "🗑" => "5039671744172917707", "⛔" => "5040025580758631490", "🔑" => "5039613856603702817",
    "📥" => "5041921924783932671", "👤" => "5039834781131474002", "✍️" => "6325762240255362536",
    "🚫" => "4985543083090248870", "💳" => "4985503389002499406", "📂" => "5095906365159769288", "🖼" => "5096138645581071322", "📤" => "5098263881298478132",
    "🔎" => "5095936949621883874", "🙈" => "5096200480225231911", "✏️" => "5098091653109908459",
    "🔒" => "5095829382165955413", "🔖" => "5096266266239304790", "🏠" => "5095873689048581180", "⏭" => "5096242966041723704",
    "⏰" => "5098405997471335371", "📊" => "5095855078955287752", "📝" => "5098101896606909489", "🏦" => "5095940106422846598",
    "❓" => "5422699019279283099", "🔢" => "4956259055468282692", "📩" => "5041921924783932671",
    "👋" => "5422699019279283099", "📢" => "5039895103947146186", "👇" => "5273849153745592110",
    "⏱" => "5098405997471335371", "📲" => "4985503389002499406", "🔗" => "6316411739969687815",
    "👁" => "5095936949621883874", "🎉" => "4956259055468282692", "🔍" => "5095936949621883874",
    "💡" => "4956259055468282692", "🔓" => "5095829382165955413", "👥" => "5039834781131474002",
    "ℹ️" => "5273849153745592110", "🔄" => "5042020176455795565", "📌" => "5039774728898741491", "🕐" => "5098405997471335371", "⚡" => "4956259055468282692",
];

const EMOJI_SLOTS = [          // optional manual overrides from the Admin Panel (for emoji that are not in EMOJI_MAP)
    'refer'  => ['🔗', 'My Refer Link'],
    'stats'  => ['👥', 'My Stats'],
    'gift'   => ['🎁', 'Rewards'],
    'top'    => ['🏆', 'Leaderboard'],
    'admin'  => ['⚙️', 'Admin Panel'],
    'verify' => ['🛡', 'Verify Device'],
    'join'   => ['📢', 'Join Channel'],
    'joined' => ['✅', 'Maine Join Kar Liya'],
    'points' => ['💎', 'Points'],
    'party'  => ['🎉', 'Celebration'],
    'ok'     => ['✅', 'Success tick'],
    'fire'   => ['🔥', 'Fire'],
];

const STYLE_DEFAULTS = ['refer' => 'success', 'verify' => 'success', 'joined' => 'success'];
const STYLE_NAMES = ['success' => '🟢 Hara', 'primary' => '🔵 Neela', 'danger' => '🔴 Laal'];

/** Premium emoji are used only when switched ON *and* /emojitest proved Telegram really shows them. */
function premium_active(): bool {
    return setting('premium_on', '1') === '1';   // like Code Adda: always send, no auto-gate
}

function map_id(string $emoji): ?string {
    return EMOJI_MAP[$emoji] ?? EMOJI_MAP[str_replace("\u{FE0F}", '', $emoji)] ?? null;
}

function emoji_regex(): string {
    static $re = null;
    if ($re === null) {
        $keys = array_keys(EMOJI_MAP);
        usort($keys, fn($a, $b) => strlen($b) <=> strlen($a));
        $re = '(?:' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $keys)) . ")\u{FE0F}?";
    }
    return $re;
}

/** Wraps every known emoji of an HTML text in <tg-emoji>, except those already wrapped. */
function premiumize_html(string $html): string {
    $parts = preg_split('#(<tg-emoji[^>]*>.*?</tg-emoji>)#su', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $seg) {
        if ($i % 2 === 1 || $seg === '') continue;
        $parts[$i] = (string)preg_replace_callback('/' . emoji_regex() . '/u', function ($m) {
            $id = map_id($m[0]);
            return $id ? '<tg-emoji emoji-id="' . $id . '">' . $m[0] . '</tg-emoji>' : $m[0];
        }, $seg);
    }
    return implode('', $parts);
}

/** Colour of a button chosen from its name, like Code Adda: danger / success / primary. */
function auto_style(array $b): string {
    $all = mb_strtolower((string)($b['text'] ?? '')) . ' ' . mb_strtolower((string)($b['callback_data'] ?? ''));
    if (preg_match('/cancel|delete|remove|ban user|danger|decline|out of stock|hidden|hatao|band karo/', $all)) return 'danger';
    if (preg_match('/buy|agree|paid|deliver|add|verify|unban|solved|proceed|success|save|confirm|in stock|done|chalu|bhejo/', $all)) return 'success';
    return 'primary';
}

function style_of(string $slot): ?string {
    $v = (string)setting('style_' . $slot, STYLE_DEFAULTS[$slot] ?? '');
    return isset(STYLE_NAMES[$v]) ? $v : null;
}

function emoji_id(string $slot): ?string {
    if (!premium_active()) return null;
    $v = (string)setting('emoji_' . $slot, '');
    return preg_match('/^\d{5,25}$/', $v) ? $v : null;
}

/** Inline emoji for message text (HTML). Plain emoji unless a premium override is set for this slot. */
function E(string $slot): string {
    $fb = EMOJI_SLOTS[$slot][0];
    $id = emoji_id($slot);
    return $id ? '<tg-emoji emoji-id="' . $id . '">' . $fb . '</tg-emoji>' : $fb;
}

/** Button for a menu slot: premium icon override + colour override. Everything else is decorated automatically when sent. */
function btn(string $label, array $extra = [], ?string $slot = null): array {
    $b = ['text' => $label] + $extra;
    if ($slot !== null) {
        $b['_fb'] = EMOJI_SLOTS[$slot][0];
        if ($id = emoji_id($slot)) $b['icon_custom_emoji_id'] = $id;
        $sv = (string)setting('style_' . $slot, STYLE_DEFAULTS[$slot] ?? '');
        if ($sv === 'none') $b['style'] = '';                      // admin chose "default colour"
        elseif (isset(STYLE_NAMES[$sv])) $b['style'] = $sv;
    }
    return $b;
}

function strip_tg_emoji(string $s): string {
    return (string)preg_replace('#<tg-emoji[^>]*>(.*?)</tg-emoji>#su', '$1', $s);
}

/**
 * Final touch before every Telegram call (Code Adda's "decorate"):
 *  - $premium : known emoji in HTML text/captions become premium emoji, button emoji become button icons
 *  - $style   : buttons get a colour (explicit > by name)
 */
function prep_params(array $p, bool $premium, bool $style = true): array {
    foreach (['text', 'caption'] as $k) {
        if (!isset($p[$k]) || !is_string($p[$k])) continue;
        if (!$premium) $p[$k] = strip_tg_emoji($p[$k]);
        elseif (($p['parse_mode'] ?? '') === 'HTML') $p[$k] = premiumize_html($p[$k]);
    }
    // Button icons only when Telegram really shows them; if /emojitest said they are dropped, keep the normal emoji in the label
    $icons = $premium && setting('premium_ok', '') !== '0';
    if (isset($p['reply_markup']) && is_array($p['reply_markup'])) {
        foreach (['inline_keyboard', 'keyboard'] as $kb) {
            if (!isset($p['reply_markup'][$kb])) continue;
            foreach ($p['reply_markup'][$kb] as $ri => $row) {
                foreach ($row as $bi => $b) {
                    if (is_string($b)) $b = ['text' => $b];
                    if (!is_array($b)) continue;
                    $fb = $b['_fb'] ?? null;
                    unset($b['_fb']);
                    $icon = $icons ? ($b['icon_custom_emoji_id'] ?? null) : null;
                    if ($fb !== null) {                                       // slot button: label has no emoji yet
                        if ($icons && !$icon) $icon = map_id($fb);
                        if ($icon) $b['icon_custom_emoji_id'] = $icon;
                        else { $b['text'] = $fb . ' ' . $b['text']; unset($b['icon_custom_emoji_id']); }
                    } elseif ($icons && !$icon && preg_match('/^' . emoji_regex() . '/u', (string)$b['text'], $m) && ($id = map_id($m[0]))) {
                        $label = trim((string)preg_replace('/^' . preg_quote($m[0], '/') . '[\x{FE0F}\s]*/u', '', (string)$b['text']));
                        $b['text'] = $label !== '' ? $label : 'Open';         // emoji moves into the icon
                        $b['icon_custom_emoji_id'] = $id;
                    } elseif (!$icons) {
                        unset($b['icon_custom_emoji_id']);
                    }
                    if (!$style) unset($b['style']);
                    elseif (($b['style'] ?? null) === '') unset($b['style']);
                    elseif (!isset($b['style'])) $b['style'] = auto_style($b);
                    $p['reply_markup'][$kb][$ri][$bi] = $b;
                }
            }
        }
    }
    return $p;
}

/* ---------------------------------------------------------- Telegram */

function tg_raw(string $method, array $params = []): array {
    if (cfg('dry_run', false)) {
        $logp = array_map(fn($v) => $v instanceof CURLFile ? '[file:' . $v->getPostFilename() . ']' : $v, $params);
        file_put_contents(cfg('dry_run_log', sys_get_temp_dir() . '/referbot_out.log'),
            json_encode([$method, $logp], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
    if (isset($GLOBALS['tg_stub'])) {                  // tests can fake Telegram answers
        $o = ($GLOBALS['tg_stub'])($method, $params);
        if ($o !== null) return $o;
    }
    if (cfg('dry_run', false)) return ['ok' => true, 'result' => ['message_id' => 1]];

    foreach ($params as $k => $v) {
        if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    $ch = curl_init('https://api.telegram.org/bot' . cfg('bot_token') . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if (!is_array($j)) {
        log_line("TG_FAIL $method $err");
        return ['ok' => false];
    }
    if (!($j['ok'] ?? false)) log_line("TG_ERR $method " . substr((string)$res, 0, 200));
    return $j;
}

/**
 * Calls Telegram with premium emoji + button colours. If Telegram rejects them (e.g. the bot owner has no
 * Telegram Premium) the same call is retried with normal emoji, and last of all without colours.
 */
function tg(string $method, array $params = []): array {
    $prem = premium_active();
    $p1 = prep_params($params, $prem, true);
    $enc = json_encode($p1, JSON_UNESCAPED_UNICODE) ?: '';
    $usedPrem = $prem && (strpos($enc, 'tg-emoji') !== false || strpos($enc, 'icon_custom_emoji_id') !== false);
    $usedStyle = strpos($enc, '"style"') !== false;
    $r = tg_raw($method, $p1);
    $bad = fn(array $x) => !($x['ok'] ?? false) && (int)($x['error_code'] ?? 400) === 400
        && stripos((string)($x['description'] ?? ''), 'not modified') === false;
    if ($usedPrem && $bad($r)) {                                  // 2nd try: normal emoji, colours kept
        log_line("PREMIUM_REJECTED $method " . substr((string)($r['description'] ?? ''), 0, 120) . ' -> retry with normal emoji');
        $r = tg_raw($method, prep_params($params, false));
    }
    if (($usedPrem || $usedStyle) && $bad($r)) {                  // 3rd try: no colours either
        log_line("STYLE_REJECTED $method " . substr((string)($r['description'] ?? ''), 0, 120) . ' -> retry plain');
        $r = tg_raw($method, prep_params($params, false, false));
    }
    return $r;
}

function send(int $chat, string $text, ?array $kb = null): array {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
          'link_preview_options' => ['is_disabled' => true]];
    if ($kb) $p['reply_markup'] = $kb;
    return tg('sendMessage', $p);
}

/** Sends text as a downloadable .txt file (used for JSON / session style codes). */
function send_text_file(int $chat, string $content, string $filename, string $caption = ''): array {
    $tmp = tempnam(sys_get_temp_dir(), 'rb');
    file_put_contents($tmp, $content);
    $p = ['chat_id' => $chat, 'document' => new CURLFile($tmp, 'text/plain', $filename)];
    if ($caption !== '') { $p['caption'] = $caption; $p['parse_mode'] = 'HTML'; }
    $r = tg('sendDocument', $p);
    @unlink($tmp);
    return $r;
}

/** Downloads a file that Telegram stored (path from getFile). Returns the body or null. */
function tg_download(string $file_path): ?string {
    if (isset($GLOBALS['tg_file_stub'])) return ($GLOBALS['tg_file_stub'])($file_path);
    $ch = curl_init('https://api.telegram.org/file/bot' . cfg('bot_token') . '/' . $file_path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 60]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $http === 200) ? (string)$body : null;
}

/** Edit an existing panel message in place (falls back to a new message). */
function panel_send(int $chat, ?int $mid, string $text, array $kb): void {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb,
          'link_preview_options' => ['is_disabled' => true]];
    if ($mid) {
        $p['message_id'] = $mid;
        $r = tg('editMessageText', $p);
        if (($r['ok'] ?? false) || stripos((string)($r['description'] ?? ''), 'not modified') !== false) return;
        unset($p['message_id']);
    }
    tg('sendMessage', $p);
}

function answer_cb(string $id, string $text = '', bool $alert = false): void {
    tg('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']);
}

/* ------------------------------------------------- Mini App security */

/** Validates Telegram Mini App initData (HMAC). Returns the user array or null. */
function validate_init_data(string $init, int $max_age = 3600): ?array {
    parse_str($init, $data);
    if (!isset($data['hash']) || !is_string($data['hash'])) return null;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) $pairs[] = $k . '=' . $v;
    $secret = hash_hmac('sha256', (string)cfg('bot_token'), 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $pairs), $secret);
    if (!hash_equals($calc, $hash)) return null;
    if (time() - (int)($data['auth_date'] ?? 0) > $max_age) return null;
    $user = json_decode((string)($data['user'] ?? ''), true);
    return (is_array($user) && !empty($user['id'])) ? $user : null;
}

function client_ip(): string {
    $hdr = (string)cfg('ip_header', 'REMOTE_ADDR');
    $ip = trim(explode(',', (string)($_SERVER[$hdr] ?? ''))[0]);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return $ip;
}
