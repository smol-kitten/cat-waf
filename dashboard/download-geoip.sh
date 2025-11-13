#!/bin/bash
# MaxMind GeoIP Database Downloader
# Downloads GeoLite2-City database if credentials are provided

set -e

GEOIP_DIR="/usr/share/GeoIP"
DB_FILE="${GEOIP_DIR}/GeoLite2-City.mmdb"
TEMP_DIR="/tmp/geoip-download"

# Check if credentials are provided
if [ -z "$MAXMIND_ACCOUNT_ID" ] || [ -z "$MAXMIND_LICENSE_KEY" ]; then
    echo "ℹ️  MaxMind credentials not provided, skipping GeoIP database download"
    echo "   To enable local GeoIP lookups, set MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY in .env"
    exit 0
fi

echo "🌍 MaxMind GeoIP Database Setup"
echo "================================"

# Create directories
mkdir -p "$GEOIP_DIR"
mkdir -p "$TEMP_DIR"

# Check if database already exists and is recent (less than 7 days old)
if [ -f "$DB_FILE" ]; then
    DB_AGE=$(( ($(date +%s) - $(stat -c %Y "$DB_FILE")) / 86400 ))
    echo "📦 Existing database found (${DB_AGE} days old)"
    
    if [ $DB_AGE -lt 7 ]; then
        echo "✅ Database is recent, skipping download"
        exit 0
    else
        echo "⏰ Database is old, downloading update..."
    fi
else
    echo "📥 No database found, downloading..."
fi

# Download database
DB_URL="https://download.maxmind.com/geoip/databases/GeoLite2-City/download?suffix=tar.gz"
CHECKSUM_URL="${DB_URL}.sha256"

cd "$TEMP_DIR"

echo "📡 Downloading GeoLite2-City database..."
if ! curl -sSL -u "${MAXMIND_ACCOUNT_ID}:${MAXMIND_LICENSE_KEY}" \
    -o GeoLite2-City.tar.gz \
    "$DB_URL"; then
    echo "❌ Download failed! Check your credentials."
    exit 1
fi

echo "🔐 Downloading checksum..."
if ! curl -sSL -u "${MAXMIND_ACCOUNT_ID}:${MAXMIND_LICENSE_KEY}" \
    -o GeoLite2-City.tar.gz.sha256 \
    "$CHECKSUM_URL"; then
    echo "⚠️  Checksum download failed, skipping verification"
else
    echo "✓ Verifying checksum..."
    if sha256sum -c GeoLite2-City.tar.gz.sha256 2>/dev/null; then
        echo "✅ Checksum verified"
    else
        echo "❌ Checksum verification failed!"
        exit 1
    fi
fi

echo "📦 Extracting database..."
tar -xzf GeoLite2-City.tar.gz --strip-components=1

if [ ! -f "GeoLite2-City.mmdb" ]; then
    echo "❌ Database file not found in archive!"
    exit 1
fi

echo "📋 Installing database..."
mv GeoLite2-City.mmdb "$DB_FILE"
chmod 644 "$DB_FILE"

# Get file size and modification date
DB_SIZE=$(du -h "$DB_FILE" | cut -f1)
DB_DATE=$(date -r "$DB_FILE" "+%Y-%m-%d %H:%M:%S")

echo ""
echo "✅ GeoIP Database Installation Complete"
echo "   Location: $DB_FILE"
echo "   Size: $DB_SIZE"
echo "   Date: $DB_DATE"
echo ""
echo "💡 Database will be automatically updated if older than 7 days"

# Cleanup
cd /
rm -rf "$TEMP_DIR"

exit 0
