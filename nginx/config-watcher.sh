#!/bin/sh
# NGINX Config Watcher - Auto-reload on changes
# Watches for .reload_needed signal file

#!/bin/sh
# Watch for config changes and reload nginx

RELOAD_SIGNAL="/etc/nginx/sites-enabled/.reload_needed"
POLL_INTERVAL=5

echo "🐱 CatWAF Config Watcher Started"
echo "Watching for changes in: $RELOAD_SIGNAL"
echo "Poll interval: ${POLL_INTERVAL}s"

while true; do
    if [ -f "$RELOAD_SIGNAL" ]; then
        echo "⚡ Config change detected at $(date)"
        
        # Test NGINX config first
        if nginx -t 2>&1; then
            echo "✅ Config test passed - Reloading NGINX..."
            nginx -s reload
            
            if [ $? -eq 0 ]; then
                echo "✅ NGINX reloaded successfully"
                rm -f "$RELOAD_SIGNAL"
            else
                echo "❌ NGINX reload failed"
            fi
        else
            echo "❌ Config test failed - NOT reloading"
            echo "Keeping .reload_needed file for manual intervention"
        fi
    fi
    
    sleep $POLL_INTERVAL
done
