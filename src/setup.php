<?php
/** CLI: php setup.php init | webhook | info */
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/lib.php';
require __DIR__ . '/core.php';

$cmd = $argv[1] ?? '';
switch ($cmd) {
    case 'init':
        db();
        echo "DB ready: " . cfg('db_path') . "\n";
        break;
    case 'webhook':
        $r = tg('setWebhook', [
            'url' => cfg('webhook_url'),
            'secret_token' => cfg('webhook_secret'),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => 'true',
        ]);
        echo json_encode($r), "\n";
        break;
    case 'info':
        echo json_encode(tg('getMe')), "\n", json_encode(tg('getWebhookInfo')), "\n";
        break;
    default:
        echo "Use: php setup.php init|webhook|info\n";
}
