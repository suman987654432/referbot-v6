#!/bin/bash
set -e

# Create directories if needed
mkdir -p /var/lib/referbot
chown -R www-data:www-data /var/lib/referbot

# Configure Apache port based on Render's PORT environment variable
PORT="${PORT:-80}"
sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Initialize database schema
php /var/www/referbot/src/setup.php init

# Register webhook if URL is provided
if [ -n "$WEBHOOK_URL" ]; then
    echo "Registering Webhook..."
    php /var/www/referbot/src/setup.php webhook
fi

echo "Starting Apache on port $PORT..."
exec apache2-foreground
