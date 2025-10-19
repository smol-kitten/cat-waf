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

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
