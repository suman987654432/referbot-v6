FROM php:8.2-apache

# Install SQLite and PostgreSQL dependencies and extensions
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    libpq-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite module
RUN a2enmod rewrite

# Setup directory
WORKDIR /var/www/referbot

# Copy application files
COPY . /var/www/referbot/

# Configure Apache DocumentRoot to point to /public
ENV APACHE_DOCUMENT_ROOT /var/www/referbot/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set permissions
RUN mkdir -p /var/lib/referbot && chown -R www-data:www-data /var/lib/referbot /var/www/referbot
RUN chmod +x /var/www/referbot/start.sh

EXPOSE 80

CMD ["/bin/bash", "/var/www/referbot/start.sh"]
