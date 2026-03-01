#!/bin/sh
set -e

# Parser position files are now stored locally inside the log-parser container (/app/data/),
# NOT on the shared waf-logs volume. Nothing to clean up here.
echo "✅ Shared log volume intact (parser uses local state)"

# Fix permissions on shared volumes
echo "Fixing permissions on shared volumes..."
chown -R www-data:www-data /etc/nginx/sites-enabled 2>/dev/null || true
chmod -R 775 /etc/nginx/sites-enabled 2>/dev/null || true

# Fix Docker socket permissions (if mounted)
if [ -S /var/run/docker.sock ]; then
    echo "Fixing Docker socket permissions..."
    chmod 666 /var/run/docker.sock 2>/dev/null || true
fi

# Download GeoIP database if credentials provided
echo "🌍 Checking GeoIP database..."
/usr/local/bin/download-geoip.sh || echo "⚠️  GeoIP download failed, continuing without local database"

# Wait for database to be ready
echo "⏳ Waiting for database..."
until php -r "try { \$db = new PDO('mysql:host=mariadb;dbname=waf_db', 'waf_user', getenv('DB_PASSWORD') ?: 'your_waf_password_here'); echo 'connected'; } catch(Exception \$e) { exit(1); }"; do
    sleep 2
done
echo "✅ Database ready"

# Clean up orphaned NGINX configs on startup
echo "🧹 Checking for orphaned NGINX configs..."
php /var/www/html/scripts/cleanup-orphaned-configs.php 2>&1 || true

# Regenerate all site configs to ensure they use syslog-based logging (no .log files)
# This ensures sites created before the syslog migration get updated configs
echo "🔄 Regenerating site configs (ensuring syslog-based logging)..."
php /var/www/html/regenerate-configs.php 2>&1 || echo "⚠️  Config regeneration failed, sites may need manual rebuild"
echo "✅ Site configs regenerated"

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
