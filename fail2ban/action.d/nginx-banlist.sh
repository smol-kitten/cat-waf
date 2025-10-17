#!/bin/bash
# Script to manage NGINX banlist

BANLIST="/etc/fail2ban/state/banlist.conf"
ACTION=$1
IP=$2

# Ensure banlist exists
if [ ! -f "$BANLIST" ]; then
    cat > "$BANLIST" << 'EOF'
# Fail2Ban managed ban list
map $remote_addr $ban {
    default 0;
}
EOF
fi

case "$ACTION" in
    ban)
        # Check if IP is already banned
        if ! grep -q "$IP" "$BANLIST"; then
            # Add IP to ban list (before the closing brace)
            sed -i "/^}/i\\    $IP 1;" "$BANLIST"
            echo "Banned IP: $IP"
            
            # Reload NGINX (signal the container)
            if [ -f /var/run/nginx.pid ]; then
                kill -HUP $(cat /var/run/nginx.pid) 2>/dev/null || true
            fi
        fi
        ;;
    unban)
        # Remove IP from ban list
        sed -i "/$IP/d" "$BANLIST"
        echo "Unbanned IP: $IP"
        
        # Reload NGINX
        if [ -f /var/run/nginx.pid ]; then
            kill -HUP $(cat /var/run/nginx.pid) 2>/dev/null || true
        fi
        ;;
    *)
        echo "Usage: $0 {ban|unban} <ip>"
        exit 1
        ;;
esac

exit 0
