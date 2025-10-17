#!/bin/sh
# Generate self-signed snakeoil certificate

CERT_DIR="/etc/nginx/ssl/snakeoil"
mkdir -p "$CERT_DIR"

if [ ! -f "$CERT_DIR/cert.pem" ] || [ ! -f "$CERT_DIR/key.pem" ]; then
    echo "Generating snakeoil certificate..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$CERT_DIR/key.pem" \
        -out "$CERT_DIR/cert.pem" \
        -subj "/C=US/ST=State/L=City/O=Organization/OU=IT/CN=localhost"
    
    chmod 644 "$CERT_DIR/cert.pem"
    chmod 600 "$CERT_DIR/key.pem"
    echo "✅ Snakeoil certificate generated at $CERT_DIR"
else
    echo "✅ Snakeoil certificate already exists"
fi
