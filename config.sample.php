<?php
/**
 * Copy to /etc/referbot/config.php  (chmod 640, chown root:www-data)
 * Is file ko kabhi webroot ke andar mat rakho aur kisi ko share mat karo.
 */
return [
    'bot_token'          => 'PASTE_NEW_BOT_TOKEN_FROM_BOTFATHER',
    'bot_username'       => 'YourReferBot',                 // bina @ ke
    'admin_ids'          => [123456789],                    // tumhari Telegram numeric ID

    'webhook_url'        => 'https://YOUR-DOMAIN/referbot/bot.php',
    'webhook_secret'     => 'RANDOM_32_CHARS_A-Z_a-z_0-9',  // openssl rand -hex 16
    'webapp_url'         => 'https://YOUR-DOMAIN/referbot/verify.html',

    'db_path'            => '/var/lib/referbot/referbot.db',
    'log_path'           => '/var/lib/referbot/referbot.log',

    'points_per_referral'=> 1,
    'fp_strict'          => true,    // true = same hardware fingerprint on 2 accounts => reject
    'max_per_ip_24h'     => 5,       // soft limit (mobile data IP shared hota hai). 0 = off
    'ip_header'          => 'REMOTE_ADDR',   // Cloudflare ke peeche ho to 'HTTP_CF_CONNECTING_IP'

    // Force-join (optional). Bot ko in channels mein ADMIN banao. Khali chhodo = off
    'channels'           => [],      // e.g. ['@mychannel', '-1001234567890']
    'channel_links'      => [],      // e.g. ['https://t.me/mychannel']

    'dry_run'            => false,   // sirf tests ke liye
];
