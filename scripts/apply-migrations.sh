#!/bin/bash
# Apply Database Migrations
# Usage: ./scripts/apply-migrations.sh [migration-file]

set -e

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
fi

DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-your_root_password_here}"
CONTAINER_NAME="waf-mariadb"

echo "=== WAF Database Migration Tool ==="
echo ""

# Check if container is running
if ! docker ps | grep -q "$CONTAINER_NAME"; then
    echo "❌ Error: MariaDB container '$CONTAINER_NAME' is not running"
    echo "   Start it with: docker compose up -d mariadb"
    exit 1
fi

# If specific migration file provided
if [ -n "$1" ]; then
    MIGRATION_FILE="$1"
    
    if [ ! -f "$MIGRATION_FILE" ]; then
        echo "❌ Error: Migration file not found: $MIGRATION_FILE"
        exit 1
    fi
    
    echo "📄 Applying migration: $(basename $MIGRATION_FILE)"
    echo ""
    
    # Copy to container and execute
    docker cp "$MIGRATION_FILE" "$CONTAINER_NAME:/tmp/migration.sql"
    docker exec -i "$CONTAINER_NAME" mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db < /tmp/migration.sql
    
    echo "✅ Migration applied successfully!"
    exit 0
fi

# Apply all migrations in order
echo "📦 Applying all migrations..."
echo ""

for migration in mariadb/init/*-migration-*.sql; do
    if [ -f "$migration" ]; then
        echo "  → Applying: $(basename $migration)"
        docker cp "$migration" "$CONTAINER_NAME:/tmp/migration.sql"
        docker exec -i "$CONTAINER_NAME" mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db < /tmp/migration.sql 2>&1 | grep -v "Warning: Using a password"
    fi
done

echo ""
echo "✅ All migrations applied!"
echo ""
echo "🔍 Verifying schema..."

# Count columns in sites table
COLUMN_COUNT=$(docker exec "$CONTAINER_NAME" mariadb -u root -p"$DB_ROOT_PASSWORD" -sN waf_db -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'waf_db' AND TABLE_NAME = 'sites';" 2>&1 | grep -v "Warning")

echo "   Sites table has $COLUMN_COUNT columns"
echo ""

# Check for new columns
echo "📋 Checking new columns (2025-10-17 migration):"
docker exec "$CONTAINER_NAME" mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db -e "
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'waf_db' 
  AND TABLE_NAME = 'sites' 
  AND COLUMN_NAME IN ('disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst')
ORDER BY COLUMN_NAME;
" 2>&1 | grep -v "Warning"

echo ""
echo "✅ Migration verification complete!"
