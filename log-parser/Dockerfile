FROM php:8.2-cli-alpine

# Install required packages and PHP extensions
RUN apk add --no-cache \
    mysql-client \
    bash && \
    docker-php-ext-install pdo pdo_mysql

WORKDIR /app

# Copy application code
COPY parser.php LogQueue.php /app/

CMD ["php", "parser.php"]
