#!/bin/sh
set -e

# Clear old logs to prevent duplicate telemetry entries
echo "🧹 Clearing old logs..."
find /var/log -type f -name "*.log" -exec truncate -s 0 {} \; 2>/dev/null || true
echo "✅ Logs cleared"

# Fix permissions on shared volumes
echo "Fixing permissions on shared volumes..."
chown -R www-data:www-data /etc/nginx/sites-enabled 2>/dev/null || true
chmod -R 775 /etc/nginx/sites-enabled 2>/dev/null || true

# Fix Docker socket permissions (if mounted)
if [ -S /var/run/docker.sock ]; then
    echo "Fixing Docker socket permissions..."
    chmod 666 /var/run/docker.sock 2>/dev/null || true
fi

# Wait for database to be ready
echo "⏳ Waiting for database..."
until php -r "try { \$db = new PDO('mysql:host=mariadb;dbname=waf_db', 'waf_user', getenv('DB_PASSWORD') ?: 'your_waf_password_here'); echo 'connected'; } catch(Exception \$e) { exit(1); }"; do
    sleep 2
done
echo "✅ Database ready"

# Clean up orphaned NGINX configs on startup
echo "🧹 Checking for orphaned NGINX configs..."
php /var/www/html/scripts/cleanup-orphaned-configs.php 2>&1 || true

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
