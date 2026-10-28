# Refer Bot (PHP + SQLite) — device verification wala

Member apna refer link share karta hai → naya banda link se aata hai → **Mini App mein device verify** karta hai → refer karne wale ko **points** → points se **rewards redeem**.

## Rules (kaise kaam karta hai)
- Refer tabhi count hota hai jab **dono** (refer karne wala + naya user) device verify kar chuke ho.
- Ek device par ek hi account: `device_token` (Mini App storage) + `fingerprint` (GPU, screen, hardware, timezone ka hash) dono check hote hain.
- Same IP se 24 ghante mein max `max_per_ip_24h` accounts (soft limit).
- Self-refer, purane user ko refer, banned referrer — sab block.
- Points/stock transaction ke andar — double redeem nahi ho sakta.

## Ek zaroori baat (honest limitation)
Telegram/browser asli device ID (IMEI etc.) nahi deta. Ye system **probabilistic** hai:
- Koi expert banda alag browser/VPN/emulator se bypass kar sakta hai, par normal log nahi kar paayenge.
- Ek jaise 2 phone (same model) kabhi-kabhi same fingerprint de sakte hain → us user ko admin `/allow <uid>` se bypass do.
- Agar bahut false-reject aaye to config mein `'fp_strict' => false` karo (tab sirf device_token + IP check chalega).

## Zaroori: HTTPS domain
Telegram Mini App aur webhook dono ko **https://domain** chahiye (sirf IP/HTTP nahi chalega). Free option: domain ko Cloudflare par daalo ya `certbot --apache -d tumhara-domain.com`.

## Install (VPS par, root)
```bash
mkdir -p /var/www/referbot /etc/referbot /var/lib/referbot
# zip ko /var/www/referbot mein extract karo (src/, public/ folders)
chown -R www-data:www-data /var/lib/referbot

cp /var/www/referbot/config.sample.php /etc/referbot/config.php
nano /etc/referbot/config.php        # token, username, admin id, domain, webhook_secret bharo
chown root:www-data /etc/referbot/config.php && chmod 640 /etc/referbot/config.php
```

Apache mein sirf `public/` expose karo (src/ webroot ke bahar rahe):
```bash
cat > /etc/apache2/conf-available/referbot.conf <<'EOF'
Alias /referbot /var/www/referbot/public
<Directory /var/www/referbot/public>
    Require all granted
    Options -Indexes
</Directory>
EOF
a2enconf referbot && systemctl reload apache2
```

Phir:
```bash
sudo -u www-data php /var/www/referbot/src/setup.php init
sudo -u www-data php /var/www/referbot/src/setup.php webhook
sudo -u www-data php /var/www/referbot/src/setup.php info
```

**BotFather** mein: `/setfeaturedbot`/Menu button zaroori nahi — verify button bot khud bhejta hai (`webapp_url`).

## Admin Panel (buttons se, sirf `admin_ids` ko dikhta hai)
Menu mein **Admin Panel** button aata hai (ya `/admin`). Andar:
- **Channels**: force-join channel add/remove (bot us channel mein admin hona chahiye).
- **Rewards / Services**: naya reward (naam, cost, auto-code ya manual), stock add, cost/naam badlo, chalu/band, delete.
- **Points per Refer**: ek refer par kitne points.
- **Users**: ID/@username se dhundo, ban/unban, points do/kato, device bypass, device reset.
- **Premium Emoji**: har button/message ka premium emoji set karo, ON/OFF, emoji ID nikalo.
- **Device Rules**: fingerprint strict ON/OFF, IP limit.
- **Broadcast** aur **Stats**.

Panel ki settings database mein save hoti hain aur `config.php` ki value ko override karti hain. `/cancel` kisi bhi flow ko rok deta hai.

Chhote shortcut commands: `/ban`, `/unban`, `/allow`, `/disallow`, `/givepoints <uid> <n>`, `/devices <uid>`, `/resetdevice <uid>`, `/adminstats`.

## Premium emoji + button colours (Code Adda jaisa, automatic)
- Har button par naam ke hisaab se **rang** apne aap lagta hai (cancel/delete laal, buy/add/verify hara, baaki neela). Ye Premium ke bina bhi chalta hai.
- **Premium emoji** ke liye bot jis Telegram account se bana hai usme **Telegram Premium** hona zaroori hai. Admin Panel, phir Premium Emoji, phir **Test Karo** (ya `/emojitest`): test pass ho to saare known emoji (message + button icon) apne aap premium ho jate hain. Fail ho to premium band rehta hai aur normal emoji dikhte hain.
- Kisi khaas button ka emoji ya rang alag chahiye to Premium Emoji menu ke slots se badlo.

## Force-join channels
Ab Admin Panel se manage hote hain. `config.php` ke `channels` sirf pehli baar (khali database mein) seed hote hain.

## Test
```bash
php tests/run_tests.php      # network nahi lagta
```

## Security notes
- Bot token sirf `/etc/referbot/config.php` mein; purana/leaked token BotFather se revoke karo.
- `webhook_secret` ke bina bot.php request accept nahi karta.
- Mini App ka `initData` HMAC se check hota hai — koi fake user ID bhej kar verify nahi kar sakta.
