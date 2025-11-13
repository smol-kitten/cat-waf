FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \    
    docker-cli-compose \
    curl \
    bash \
    docker-cli \
    libzip-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli zip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy dashboard application first (for composer install)
COPY src /var/www/html

# Install GeoIP2 library via Composer
WORKDIR /var/www/html
RUN composer require geoip2/geoip2:~2.0 --no-interaction --no-dev --optimize-autoloader || echo "⚠️  Composer install failed, GeoIP2 library not available"

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy NGINX configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY nginx-dashboard.conf /etc/nginx/http.d/default.conf

# Create temporary directories for nginx (use /tmp which is writable)
RUN mkdir -p /tmp/client_body /tmp/proxy /tmp/fastcgi /tmp/uwsgi /tmp/scgi && \
    chown -R nginx:nginx /tmp/client_body /tmp/proxy /tmp/fastcgi /tmp/uwsgi /tmp/scgi

# Set permissions
RUN chown -R nginx:nginx /var/www/html 2>/dev/null || true && \
    chmod -R 755 /var/www/html

# Back to root for remaining setup
WORKDIR /

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisord.conf

# Copy GeoIP download script
COPY download-geoip.sh /usr/local/bin/download-geoip.sh
RUN chmod +x /usr/local/bin/download-geoip.sh

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Create GeoIP directory
RUN mkdir -p /usr/share/GeoIP

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
