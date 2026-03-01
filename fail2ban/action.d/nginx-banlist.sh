#!/bin/bash
# CatWAF Fail2Ban action — update nginx geo banlist
# Uses same geo $ban format as auto-ban-service.php

BANLIST="/etc/fail2ban/state/banlist.conf"
ACTION=$1
IP=$2

# Ensure banlist exists with proper geo block
if [ ! -f "$BANLIST" ]; then
    cat > "$BANLIST" << 'EOF'
# CatWAF Ban List — managed by fail2ban + auto-ban-service
geo $ban {
    default 0;
}
EOF
fi

case "$ACTION" in
    ban)
        if ! grep -qF "$IP" "$BANLIST"; then
            # Insert before closing brace
            sed -i "/^}/i\\    $IP 1;" "$BANLIST"
            echo "Banned IP: $IP"
            # Signal nginx to reload via config-watcher
            touch /etc/nginx/sites-enabled/.reload_needed 2>/dev/null || true
        fi
        ;;
    unban)
        sed -i "/$IP/d" "$BANLIST"
        echo "Unbanned IP: $IP"
        touch /etc/nginx/sites-enabled/.reload_needed 2>/dev/null || true
        ;;
    *)
        echo "Usage: $0 {ban|unban} <ip>"
        exit 1
        ;;
esac

exit 0
