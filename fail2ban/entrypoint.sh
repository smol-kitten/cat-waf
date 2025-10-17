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

echo "Starting fail2ban..."

# Start fail2ban in foreground
exec fail2ban-server -f
