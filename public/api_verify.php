<?php
require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/core.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'msg' => 'POST only'], 405);
$j = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($j)) out(['ok' => false, 'msg' => 'bad json'], 400);

$user = validate_init_data((string)($j['initData'] ?? ''));
if (!$user) out(['ok' => false, 'code' => 'bad_init', 'msg' => 'Telegram se dobara kholo (session expire / invalid).'], 401);

if (is_maintenance() && !is_admin((int)$user['id'])) {
    out(['ok' => false, 'code' => 'maintenance', 'msg' => strip_tags(maintenance_msg())], 503);
}

touch_user((int)$user['id'], (string)($user['first_name'] ?? ''), (string)($user['username'] ?? ''));

$res = verify_device(
    (int)$user['id'],
    strtolower((string)($j['deviceToken'] ?? '')),
    strtolower((string)($j['fp'] ?? '')),
    client_ip(),
    (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    (array)($j['signals'] ?? [])
);
out($res);
