#!/bin/sh
# CatWAF NGINX Entrypoint
# Handles CRS installation before NGINX starts

cat << "EOF"
  ╔═══════════════════════════════════════════╗
  ║   🐱  CatWAF - Web Application Firewall  ║
  ║   Purr-otecting your sites since 2025!   ║
  ╚═══════════════════════════════════════════╝
EOF

echo ""
echo "🐱 Starting CatWAF NGINX + ModSecurity v3..."
echo ""

# Clear all logs on startup to prevent duplicate telemetry entries
echo "🧹 Clearing old logs to prevent duplicate telemetry entries..."
find /var/log/nginx -type f -name "*.log" -exec truncate -s 0 {} \; 2>/dev/null || true
echo "✅ Logs cleared"

# Create symlink to fail2ban state volume for banlist
if [ ! -f "/etc/nginx/banlist.conf" ]; then
    echo "🔗 Creating symlink to fail2ban banlist..."
    mkdir -p /etc/fail2ban/state
    touch /etc/fail2ban/state/banlist.conf 2>/dev/null || true
    ln -sf /etc/fail2ban/state/banlist.conf /etc/nginx/banlist.conf
    if [ $? -eq 0 ]; then
        echo "✅ Banlist symlink created"
    else
        echo "⚠️  Could not create symlink, using default banlist"
    fi
fi

# Generate snakeoil certificate if not present
if [ ! -f "/etc/nginx/ssl/snakeoil/cert.pem" ]; then
    echo "🔐 Generating snakeoil self-signed certificate..."
    mkdir -p /etc/nginx/ssl/snakeoil
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/snakeoil/key.pem \
        -out /etc/nginx/ssl/snakeoil/cert.pem \
        -subj "/C=US/ST=State/L=City/O=Snakeoil/CN=localhost" \
        2>/dev/null
    if [ $? -eq 0 ]; then
        echo "✅ Snakeoil certificate generated (valid for 10 years)"
    else
        echo "❌ Failed to generate snakeoil certificate"
    fi
else
    echo "✅ Snakeoil certificate already exists"
fi

# Sync certificates from ACME to nginx if needed
echo "🔄 Checking certificate sync from ACME to nginx..."
SYNC_COUNT=0
# Check if /acme.sh directory exists and is accessible
if [ -d "/acme.sh" ]; then
    # Find all certificate directories in ACME volume
    for acme_cert_dir in /acme.sh/*/; do
        if [ -d "$acme_cert_dir" ]; then
            domain=$(basename "$acme_cert_dir")
            
            # Skip special directories
            if [ "$domain" = "ca" ] || [ "$domain" = "http.header" ]; then
                continue
            fi
            
            acme_cert="/acme.sh/$domain/fullchain.pem"
            nginx_cert="/etc/nginx/certs/$domain/fullchain.pem"
            
            # Check if ACME cert exists and is valid
            if [ -f "$acme_cert" ]; then
                # Check if nginx cert is missing or different
                if [ ! -f "$nginx_cert" ] || ! cmp -s "$acme_cert" "$nginx_cert"; then
                    echo "  🔄 Syncing certificate for $domain from ACME..."
                    mkdir -p "/etc/nginx/certs/$domain"
                    
                    # Check if ACME cert is about to expire (less than 30 days)
                    expiry=$(openssl x509 -enddate -noout -in "$acme_cert" 2>/dev/null | cut -d= -f2)
                    if [ -n "$expiry" ]; then
                        # Use portable date parsing (works in both GNU and BusyBox)
                        expiry_epoch=$(date -j -f "%b %d %H:%M:%S %Y %Z" "$expiry" +%s 2>/dev/null || date -d "$expiry" +%s 2>/dev/null || echo 0)
                        now_epoch=$(date +%s)
                        days_until_expiry=$(( ($expiry_epoch - $now_epoch) / 86400 ))
                        
                        if [ $days_until_expiry -gt 30 ]; then
                            # Certificate is valid and not expiring soon, sync it
                            ln -sf "$acme_cert" "$nginx_cert"
                            ln -sf "/acme.sh/$domain/key.pem" "/etc/nginx/certs/$domain/key.pem"
                            SYNC_COUNT=$((SYNC_COUNT + 1))
                            echo "    ✅ Synced $domain (valid for $days_until_expiry days)"
                        else
                            echo "    ⚠️  Certificate for $domain expires in $days_until_expiry days, skipping sync"
                        fi
                    fi
                fi
            fi
        fi
    done
fi

if [ $SYNC_COUNT -gt 0 ]; then
    echo "  ✅ Synced $SYNC_COUNT certificate(s) from ACME"
else
    echo "  ✅ All certificates are in sync"
fi

# Function to fix broken certificate symlinks
fix_broken_symlinks() {
    echo "🔍 Checking for broken certificate symlinks..."
    find /etc/nginx/certs -type l | while read -r link; do
        if [ ! -e "$link" ]; then
            echo "⚠️  Found broken symlink: $link"
            local dir=$(dirname "$link")
            local domain=$(basename "$dir")
            local filename=$(basename "$link")
            
            # Remove broken symlink
            rm -f "$link"
            echo "   🗑️  Removed broken symlink"
            
            # Check if we have snakeoil cert for this domain
            if [ -f "/etc/nginx/certs/snakeoil/fullchain.pem" ]; then
                echo "   📋 Using snakeoil certificate"
                cp "/etc/nginx/certs/snakeoil/fullchain.pem" "$dir/fullchain.pem" 2>/dev/null || true
                cp "/etc/nginx/certs/snakeoil/key.pem" "$dir/key.pem" 2>/dev/null || true
            else
                echo "   🔐 Generating new snakeoil certificate for $domain"
                openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
                    -keyout "$dir/key.pem" \
                    -out "$dir/fullchain.pem" \
                    -subj "/CN=$domain" 2>/dev/null || true
            fi
        fi
    done
    echo "✅ Certificate symlink check complete"
}

# Fix any broken symlinks before proceeding
fix_broken_symlinks

# Install OWASP CRS if not present
if [ ! -d "/etc/nginx/modsecurity/coreruleset" ]; then
    echo "🛡️ Installing OWASP ModSecurity Core Rule Set..."
    cd /etc/nginx/modsecurity
    git clone --depth 1 https://github.com/coreruleset/coreruleset
    if [ $? -eq 0 ]; then
        cp coreruleset/crs-setup.conf.example coreruleset/crs-setup.conf
        echo "✅ OWASP CRS installed successfully"
    else
        echo "❌ Failed to clone CRS repository"
        echo "⚠️  Commenting out CRS includes..."
        sed -i 's/^Include \/etc\/nginx\/modsecurity\/coreruleset/#Include \/etc\/nginx\/modsecurity\/coreruleset/g' /etc/nginx/modsecurity/modsecurity.conf
    fi
else
    echo "✅ OWASP CRS already installed"
fi

# Count and save ModSecurity rules for stats
echo "📊 Counting ModSecurity rules..."
RULE_COUNT=0
if [ -d "/etc/nginx/modsecurity/coreruleset/rules" ]; then
    for file in /etc/nginx/modsecurity/coreruleset/rules/*.conf; do
        if [ -f "$file" ]; then
            count=$(grep -c "^[[:space:]]*SecRule[[:space:]]" "$file" 2>/dev/null || echo 0)
            RULE_COUNT=$((RULE_COUNT + count))
        fi
    done
fi
echo "${RULE_COUNT}" > /etc/nginx/sites-enabled/.modsec_stats
echo "✅ Found ${RULE_COUNT} ModSecurity rules"

# Generate missing certificates with snakeoil fallback
echo "🔐 Checking for missing SSL certificates..."
for conf_file in /etc/nginx/sites-enabled/*.conf; do
    if [ -f "$conf_file" ]; then
        # Extract domain from filename
        domain=$(basename "$conf_file" .conf)
        
        # Skip if domain is _ or contains .local
        if [ "$domain" = "_" ] || echo "$domain" | grep -q "\.local"; then
            continue
        fi
        
        # Check if certificate is referenced in config and missing
        if grep -q "ssl_certificate.*$domain" "$conf_file"; then
            cert_path="/etc/nginx/certs/$domain/fullchain.pem"
            if [ ! -f "$cert_path" ]; then
                echo "⚠️  Missing certificate for $domain, generating snakeoil..."
                mkdir -p "/etc/nginx/certs/$domain"
                openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
                    -keyout "/etc/nginx/certs/$domain/key.pem" \
                    -out "/etc/nginx/certs/$domain/fullchain.pem" \
                    -subj "/CN=$domain" 2>/dev/null
                if [ $? -eq 0 ]; then
                    echo "✅ Created snakeoil certificate for $domain"
                else
                    echo "❌ Failed to create certificate for $domain"
                fi
            fi
        fi
    fi
done

# Clean up any .copy, .bak, .old, .tmp configs before testing
echo "🧹 Cleaning up potentially invalid config files..."
CLEANUP_COUNT=0
for pattern in '*.copy.conf' '*.bak.conf' '*.old.conf' '*.tmp.conf' '*.backup.conf' '*.orig.conf'; do
    for conf_file in /etc/nginx/sites-enabled/$pattern; do
        if [ -f "$conf_file" ]; then
            filename=$(basename "$conf_file")
            echo "  ⚠️  Removing invalid config: $filename"
            rm -f "$conf_file"
            CLEANUP_COUNT=$((CLEANUP_COUNT + 1))
            
            # Log to quarantine log for record-keeping
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] STARTUP_CLEANUP: Removed $filename (invalid suffix)" >> /var/log/nginx/quarantine.log
        fi
    done
done

if [ $CLEANUP_COUNT -gt 0 ]; then
    echo "  ✅ Cleaned up $CLEANUP_COUNT invalid config file(s)"
fi

# Test NGINX configuration
echo "🧪 Testing NGINX configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "❌ NGINX configuration test failed!"
    echo "🔧 Attempting to recover by quarantining broken configs..."
    
    # Create quarantine directory
    mkdir -p /etc/nginx/sites-quarantine
    
    # Test each site config individually to find the broken one(s)
    BROKEN_CONFIGS=""
    for conf_file in /etc/nginx/sites-enabled/*.conf; do
        if [ -f "$conf_file" ]; then
            domain=$(basename "$conf_file")
            
            # Skip default config
            if [ "$domain" = "default.conf" ]; then
                continue
            fi
            
            echo "  🔍 Testing $domain..."
            
            # Create temp nginx config with only this site
            cat > /tmp/test-nginx.conf << 'TESTCONF'
user nginx;
worker_processes 1;
error_log /dev/null;
pid /tmp/nginx-test.pid;
events { worker_connections 1024; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log /dev/null;
    include /etc/nginx/conf.d/*.conf;
TESTCONF
            echo "    include $conf_file;" >> /tmp/test-nginx.conf
            echo "}" >> /tmp/test-nginx.conf
            
            # Test this specific config
            nginx -t -c /tmp/test-nginx.conf 2>&1 | grep -q "test is successful"
            if [ $? -ne 0 ]; then
                echo "  ❌ Broken config detected: $domain"
                BROKEN_CONFIGS="${BROKEN_CONFIGS}$domain "
                
                # Move to quarantine
                mv "$conf_file" "/etc/nginx/sites-quarantine/$domain"
                echo "  📦 Quarantined: $domain"
                
                # Log the error for debugging
                echo "[$(date)] Quarantined broken config: $domain" >> /var/log/nginx/quarantine.log
                nginx -t -c /tmp/test-nginx.conf 2>&1 >> /var/log/nginx/quarantine.log
            else
                echo "  ✅ Config OK: $domain"
            fi
            
            rm -f /tmp/test-nginx.conf /tmp/nginx-test.pid
        fi
    done
    
    # Test again after quarantine
    echo "🧪 Re-testing NGINX configuration..."
    nginx -t
    if [ $? -ne 0 ]; then
        echo "❌ NGINX still won't start! Loading emergency fallback..."
        
        # Quarantine ALL site configs
        mv /etc/nginx/sites-enabled/*.conf /etc/nginx/sites-quarantine/ 2>/dev/null || true
        
        # Copy emergency HTML page
        mkdir -p /var/www/emergency
        cp /emergency.html /var/www/emergency/index.html 2>/dev/null || true
        
        # Create minimal emergency config to keep API accessible
        cat > /etc/nginx/sites-enabled/emergency-fallback.conf << 'EMERGENCY'
# Emergency Fallback Configuration
# This config is loaded when all site configs fail
# Provides minimal API access for recovery

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    
    # Root directory for emergency page
    root /var/www/emergency;
    index index.html;
    
    # Serve emergency recovery page
    location / {
        try_files $uri $uri/ /index.html;
        add_header X-Emergency-Mode "true" always;
    }
    
    # Keep API accessible via HTTP (dashboard access)
    location /api/ {
        proxy_pass http://waf-dashboard:80/;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_redirect off;
        proxy_buffering off;
    }
    
    # Health check endpoint
    location /health {
        access_log off;
        return 200 '{"status": "emergency_mode", "message": "API accessible", "quarantined": true}';
        add_header Content-Type application/json;
    }
}
EMERGENCY
        
        echo "✅ Emergency fallback loaded"
        echo "📝 Broken configs: ${BROKEN_CONFIGS}"
        echo "📁 Quarantined to: /etc/nginx/sites-quarantine/"
        echo "📋 Recovery logs: /var/log/nginx/quarantine.log"
    else
        echo "✅ NGINX configuration recovered!"
        echo "📝 Quarantined broken configs: ${BROKEN_CONFIGS}"
    fi
else
    echo "✅ NGINX configuration test passed"
fi

# Create log files that fail2ban expects
echo "📝 Creating log files for fail2ban..."
mkdir -p /var/log/nginx /var/log/modsec
touch /var/log/nginx/access.log
touch /var/log/nginx/error.log
touch /var/log/modsec/modsec_audit.log
echo "✅ Log files created"

# Start config watcher in background
echo "🔍 Starting config watcher..."
/usr/local/bin/config-watcher.sh &

# Start NGINX in foreground
echo "🚀 Starting NGINX..."
exec nginx -g 'daemon off;'
