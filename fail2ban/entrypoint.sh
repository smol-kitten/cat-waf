#!/bin/bash

# Wait for nginx to create access log file (fail2ban jails monitor access logs)
echo "Waiting for nginx access logs..."
COUNTER=0
while [ ! -f "/mnt/logs/nginx/access.log" ] && [ $COUNTER -lt 60 ]; do
    echo "Waiting for /mnt/logs/nginx/access.log... ($COUNTER/60)"
    sleep 1
    COUNTER=$((COUNTER+1))
done

if [ -f "/mnt/logs/nginx/access.log" ]; then
    echo "✓ Found /mnt/logs/nginx/access.log"
    sleep 2
else
    echo "✗ WARNING: /mnt/logs/nginx/access.log not found after 60 seconds!"
    echo "Creating empty access log so fail2ban can start..."
    mkdir -p /mnt/logs/nginx
    touch /mnt/logs/nginx/access.log
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

# Clean old database to prevent slow startup from processing old bans
if [ -f "/var/lib/fail2ban/fail2ban.sqlite3" ]; then
    echo "Cleaning old fail2ban database..."
    rm -f /var/lib/fail2ban/fail2ban.sqlite3
fi

# Start fail2ban in foreground with reduced startup checks
exec fail2ban-server -f --logtarget=STDOUT
