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

# Test NGINX configuration
echo "🧪 Testing NGINX configuration..."
nginx -t
if [ $? -ne 0 ]; then
    echo "❌ NGINX configuration test failed!"
    echo "⚠️  Starting anyway to allow debugging..."
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
