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

# Ensure log directories exist (do NOT truncate — the log-parser tracks position)
echo "📁 Ensuring log directories exist..."
mkdir -p /var/log/nginx /var/log/modsec
echo "✅ Log directories ready"

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

# Sync certificates from ACME to nginx via relative symlinks
# Both /acme.sh and /etc/nginx/certs are the SAME waf-certs volume.
# acme.sh writes ECC certs to {domain}_ecc/ directories.
# We create: {domain}/fullchain.pem → ../{domain}_ecc/fullchain.cer
echo "🔄 Syncing ACME certificates to nginx..."
SYNC_COUNT=0

if [ -d "/etc/nginx/certs" ]; then
    # Find _ecc directories (acme.sh ECC cert storage)
    for ecc_dir in /etc/nginx/certs/*_ecc/; do
        [ -d "$ecc_dir" ] || continue

        ecc_name=$(basename "$ecc_dir")
        # Extract domain from dir name: catboy.farm_ecc → catboy.farm
        domain="${ecc_name%_ecc}"

        # Skip special acme.sh dirs
        case "$domain" in ca|http.header|deploy) continue ;; esac

        # Find the cert and key files in the _ecc dir
        cert_file=""
        key_file=""

        if [ -f "$ecc_dir/fullchain.cer" ]; then
            cert_file="fullchain.cer"
        elif [ -f "$ecc_dir/fullchain.pem" ]; then
            cert_file="fullchain.pem"
        fi

        if [ -f "$ecc_dir/${domain}.key" ]; then
            key_file="${domain}.key"
        elif [ -f "$ecc_dir/key.pem" ]; then
            key_file="key.pem"
        fi

        if [ -z "$cert_file" ] || [ -z "$key_file" ]; then
            echo "  ⚠️  Skipping $domain: missing cert or key in $ecc_name"
            continue
        fi

        # Create relative symlinks: {domain}/fullchain.pem → ../{domain}_ecc/{cert_file}
        nginx_dir="/etc/nginx/certs/$domain"
        mkdir -p "$nginx_dir"

        # Only update if symlink is missing, broken, or points to wrong target
        expected_cert="../${ecc_name}/${cert_file}"
        expected_key="../${ecc_name}/${key_file}"
        current_cert=$(readlink "$nginx_dir/fullchain.pem" 2>/dev/null || echo "")
        current_key=$(readlink "$nginx_dir/key.pem" 2>/dev/null || echo "")

        needs_update=0
        # Update if missing, if it's not a symlink (was a regular file), or if target changed
        if [ ! -e "$nginx_dir/fullchain.pem" ] || [ "$current_cert" != "$expected_cert" ]; then
            needs_update=1
        fi
        if [ ! -e "$nginx_dir/key.pem" ] || [ "$current_key" != "$expected_key" ]; then
            needs_update=1
        fi

        if [ $needs_update -eq 1 ]; then
            rm -f "$nginx_dir/fullchain.pem" "$nginx_dir/key.pem"
            ln -s "$expected_cert" "$nginx_dir/fullchain.pem"
            ln -s "$expected_key" "$nginx_dir/key.pem"
            SYNC_COUNT=$((SYNC_COUNT + 1))

            # Show expiry info
            expiry_days=""
            expiry_info=$(openssl x509 -enddate -noout -in "${ecc_dir}/${cert_file}" 2>/dev/null | cut -d= -f2)
            if [ -n "$expiry_info" ]; then
                expiry_epoch=$(date -d "$expiry_info" +%s 2>/dev/null || echo 0)
                now_epoch=$(date +%s)
                if [ "$expiry_epoch" -gt 0 ]; then
                    expiry_days=$(( (expiry_epoch - now_epoch) / 86400 ))
                fi
            fi
            echo "  ✅ Linked $domain → ${ecc_name}/${cert_file} (${expiry_days:-?} days remaining)"
        fi
    done

    # Also check non-ECC directories (RSA certs or old installs)
    for cert_dir in /etc/nginx/certs/*/; do
        [ -d "$cert_dir" ] || continue
        dir_name=$(basename "$cert_dir")

        # Skip _ecc dirs (handled above) and special dirs
        case "$dir_name" in *_ecc|ca|http.header|deploy|snakeoil) continue ;; esac

        # If this dir has a fullchain.pem that's a real file (not symlink), leave it alone
        # (it's either a custom upload or snakeoil - both are valid)
        if [ -f "$cert_dir/fullchain.pem" ] && [ ! -L "$cert_dir/fullchain.pem" ]; then
            continue
        fi

        # If it's a broken symlink, remove it and generate snakeoil
        if [ -L "$cert_dir/fullchain.pem" ] && [ ! -e "$cert_dir/fullchain.pem" ]; then
            echo "  ⚠️  Broken symlink for $dir_name, generating snakeoil..."
            rm -f "$cert_dir/fullchain.pem" "$cert_dir/key.pem"
            openssl req -x509 -nodes -days 3650 \
                -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 \
                -keyout "$cert_dir/key.pem" \
                -out "$cert_dir/fullchain.pem" \
                -subj "/CN=$dir_name/O=CatWAF Snakeoil" 2>/dev/null
            echo "  🔐 Generated snakeoil for $dir_name"
        fi
    done
fi

if [ $SYNC_COUNT -gt 0 ]; then
    echo "✅ Synced $SYNC_COUNT certificate(s)"
else
    echo "✅ All certificates in sync"
fi

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
