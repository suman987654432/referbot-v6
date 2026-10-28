<?php
require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/core.php';
require __DIR__ . '/../src/handlers.php';

$secret = (string)cfg('webhook_secret', '');
if ($secret === '' || !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit;
}
// answer Telegram fast, then work
$raw = file_get_contents('php://input');
http_response_code(200);
header('Content-Length: 0');
header('Connection: close');
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

$upd = json_decode((string)$raw, true);
if (is_array($upd)) {
    try {
        // Telegram re-sends an update if we are slow: process each update_id only once
        $uidv = (int)($upd['update_id'] ?? 0);
        if ($uidv) {
            $st = db()->prepare('INSERT OR IGNORE INTO seen_updates(update_id, created_at) VALUES(?,?)');
            $st->execute([$uidv, time()]);
            if ($st->rowCount() === 0) exit;
            if ($uidv % 100 === 0) db()->exec('DELETE FROM seen_updates WHERE created_at < ' . (time() - 86400));
        }
        handle_update($upd);
    }
    catch (Throwable $e) { log_line('HANDLER_ERR ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); }
}
