#!/bin/sh
# CatWAF Config Watcher + Log Rotation
# 1. Watches for .reload_needed signal and reloads nginx config
# 2. Rotates log files when they exceed MAX_LOG_SIZE to prevent disk fill
# 3. Log parser processes .1 files and deletes them after ingestion

RELOAD_SIGNAL="/etc/nginx/sites-enabled/.reload_needed"
POLL_INTERVAL=5
LOG_ROTATE_INTERVAL=60          # Check rotation every 60 seconds
MAX_LOG_SIZE=52428800           # 50 MB per file
MODSEC_MAX_SIZE=26214400        # 25 MB for modsec audit log
last_rotate=$(date +%s)

echo "CatWAF Config Watcher + Log Rotation Started"

while true; do
    # ── Config reload check ───────────────────────────────────
    if [ -f "$RELOAD_SIGNAL" ]; then
        echo "[$(date)] Config change detected"

        # If emergency fallback is active and real site configs now exist,
        # remove the emergency config BEFORE testing so it doesn't conflict
        # (duplicate default_server would cause nginx -t to fail)
        if [ -f "/etc/nginx/sites-enabled/emergency-fallback.conf" ]; then
            real_configs=$(find /etc/nginx/sites-enabled -name '*.conf' ! -name 'emergency-fallback.conf' | head -1)
            if [ -n "$real_configs" ]; then
                echo "[$(date)] Real configs detected, removing emergency fallback for test"
                mv /etc/nginx/sites-enabled/emergency-fallback.conf /tmp/emergency-fallback.conf.bak
            fi
        fi

        if nginx -t 2>&1; then
            nginx -s reload
            if [ $? -eq 0 ]; then
                rm -f "$RELOAD_SIGNAL"
                # Emergency fallback was removed and reload succeeded — clean up backup
                rm -f /tmp/emergency-fallback.conf.bak
                echo "[$(date)] NGINX reloaded OK"
            else
                # Reload failed — restore emergency fallback if we removed it
                if [ -f /tmp/emergency-fallback.conf.bak ]; then
                    mv /tmp/emergency-fallback.conf.bak /etc/nginx/sites-enabled/emergency-fallback.conf
                fi
                echo "[$(date)] NGINX reload failed"
            fi
        else
            # Config test failed — restore emergency fallback if we removed it
            if [ -f /tmp/emergency-fallback.conf.bak ]; then
                mv /tmp/emergency-fallback.conf.bak /etc/nginx/sites-enabled/emergency-fallback.conf
            fi
            rm -f "$RELOAD_SIGNAL"
            echo "[$(date)] Config test failed — NOT reloading"
        fi
    fi

    # ── Log rotation ──────────────────────────────────────────
    now=$(date +%s)
    elapsed=$((now - last_rotate))
    if [ "$elapsed" -ge "$LOG_ROTATE_INTERVAL" ]; then
        rotated=0

        # Rotate nginx access logs
        for logfile in /var/log/nginx/*-access.log /var/log/nginx/access.log; do
            [ -f "$logfile" ] || continue
            # Skip if .1 already exists (parser hasn't consumed it yet)
            [ -f "${logfile}.1" ] && continue

            size=$(stat -c%s "$logfile" 2>/dev/null || echo 0)
            if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
                mv "$logfile" "${logfile}.1"
                rotated=$((rotated + 1))
            fi
        done

        # Rotate nginx error log
        if [ -f "/var/log/nginx/error.log" ] && [ ! -f "/var/log/nginx/error.log.1" ]; then
            size=$(stat -c%s "/var/log/nginx/error.log" 2>/dev/null || echo 0)
            if [ "$size" -gt "$MAX_LOG_SIZE" ]; then
                mv "/var/log/nginx/error.log" "/var/log/nginx/error.log.1"
                rotated=$((rotated + 1))
            fi
        fi

        # Rotate modsec audit log
        if [ -f "/var/log/modsec/modsec_audit.log" ] && [ ! -f "/var/log/modsec/modsec_audit.log.1" ]; then
            size=$(stat -c%s "/var/log/modsec/modsec_audit.log" 2>/dev/null || echo 0)
            if [ "$size" -gt "$MODSEC_MAX_SIZE" ]; then
                mv "/var/log/modsec/modsec_audit.log" "/var/log/modsec/modsec_audit.log.1"
                rotated=$((rotated + 1))
            fi
        fi

        # If we rotated anything, tell nginx to reopen log files
        if [ "$rotated" -gt 0 ]; then
            nginx -s reopen 2>/dev/null
            echo "[$(date)] Rotated $rotated log file(s), nginx reopened"
        fi

        # Clean up old .1 files that parser might have missed (older than 10 min)
        find /var/log/nginx -name "*.log.1" -mmin +10 -delete 2>/dev/null
        find /var/log/modsec -name "*.log.1" -mmin +10 -delete 2>/dev/null

        # Also clean old error.log.1
        if [ -f "/var/log/nginx/error.log.1" ]; then
            age=$(stat -c%Y "/var/log/nginx/error.log.1" 2>/dev/null || echo 0)
            if [ "$((now - age))" -gt 600 ]; then
                rm -f "/var/log/nginx/error.log.1"
            fi
        fi

        last_rotate=$now
    fi

    sleep $POLL_INTERVAL
done
