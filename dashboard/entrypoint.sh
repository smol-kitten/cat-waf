#!/bin/sh
set -e

# Fix permissions on shared volumes
echo "Fixing permissions on shared volumes..."
chown -R www-data:www-data /etc/nginx/sites-enabled 2>/dev/null || true
chmod -R 775 /etc/nginx/sites-enabled 2>/dev/null || true

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisord.conf
