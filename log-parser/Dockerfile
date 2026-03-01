FROM php:8.2-cli-alpine

# Install required packages and PHP extensions
RUN apk add --no-cache \
    mysql-client \
    bash && \
    docker-php-ext-install pdo pdo_mysql

WORKDIR /app

# Local state directory for position files — mounted as a named volume
# (waf-parser-data) so position files survive container recreation.
# This is NOT on the shared waf-logs volume to prevent cross-container corruption.
RUN mkdir -p /app/data

# Copy application code
COPY parser.php LogQueue.php emergency-cleanup.php /app/

CMD ["php", "parser.php"]
