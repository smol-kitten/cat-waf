#!/bin/bash

# Wait for nginx to create log files
echo "Waiting for nginx to create log files..."
COUNTER=0
while [ ! -f "/mnt/logs/nginx/error.log" ] && [ $COUNTER -lt 30 ]; do
    echo "Waiting for /mnt/logs/nginx/error.log... ($COUNTER/30)"
    sleep 1
    COUNTER=$((COUNTER+1))
done

if [ -f "/mnt/logs/nginx/error.log" ]; then
    echo "✓ Found /mnt/logs/nginx/error.log"
    # Give the filesystem a moment to stabilize
    sleep 2
else
    echo "✗ WARNING: /mnt/logs/nginx/error.log not found after 30 seconds!"
    echo "Contents of /mnt/logs:"
    ls -laR /mnt/logs/ || echo "Cannot list /mnt/logs"
    # Try to continue anyway
fi

echo "Setting up log rotation..."
# Setup logrotate cron job to run hourly
echo "0 * * * * /usr/sbin/logrotate /etc/logrotate.d/fail2ban --state /var/lib/logrotate/logrotate.status >/dev/null 2>&1" > /etc/crontabs/root

# Create logrotate state directory
mkdir -p /var/lib/logrotate

# Start crond in background for log rotation
crond -b -l 2

echo "Starting fail2ban..."

# Remove stale socket if it exists
if [ -S "/var/run/fail2ban/fail2ban.sock" ]; then
    echo "Removing stale fail2ban socket..."
    rm -f /var/run/fail2ban/fail2ban.sock
fi

# Remove stale PID file if it exists
if [ -f "/var/run/fail2ban/fail2ban.pid" ]; then
    echo "Removing stale fail2ban PID file..."
    rm -f /var/run/fail2ban/fail2ban.pid
fi

# Start fail2ban in foreground
exec fail2ban-server -f
