FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    curl \
    bash

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copy PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Copy NGINX configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY nginx-dashboard.conf /etc/nginx/http.d/default.conf

# Create temporary directories for nginx (use /tmp which is writable)
RUN mkdir -p /tmp/client_body /tmp/proxy /tmp/fastcgi /tmp/uwsgi /tmp/scgi && \
    chown -R nginx:nginx /tmp/client_body /tmp/proxy /tmp/fastcgi /tmp/uwsgi /tmp/scgi

# Copy dashboard application
COPY src /var/www/html

# Set permissions
RUN chown -R nginx:nginx /var/www/html 2>/dev/null || true && \
    chmod -R 755 /var/www/html

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisord.conf

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
