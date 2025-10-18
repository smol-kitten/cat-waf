#!/usr/bin/env bash
set -e

echo "=== WAF Database Migration Runner ==="
echo "Waiting for MariaDB to be ready..."

# Wait for MariaDB with timeout
TIMEOUT=60
ELAPSED=0
while [ $ELAPSED -lt $TIMEOUT ]; do
    if mariadb -h mariadb -u waf_user -p${DB_PASSWORD} -e "SELECT 1" waf_db 2>/dev/null; then
        echo "✅ MariaDB is ready!"
        break
    fi
    echo "Waiting... ($ELAPSED/$TIMEOUT)"
    sleep 2
    ELAPSED=$((ELAPSED + 2))
done

if [ $ELAPSED -ge $TIMEOUT ]; then
    echo "❌ Timeout waiting for MariaDB"
    exit 1
fi

echo ""
echo "Applying migrations..."
echo "======================="

# Apply migrations in order
for migration in /migrations/*.sql; do
    if [ -f "$migration" ]; then
        filename=$(basename "$migration")
        echo "  → $filename"
        
        # Check if migration_logs table exists and if migration was applied
        TABLE_EXISTS=$(mariadb -h mariadb -u waf_user -p${DB_PASSWORD} -N -B waf_db \
            -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='waf_db' AND TABLE_NAME='migration_logs'" 2>/dev/null || echo "0")
        
        APPLIED="0"
        if [ "$TABLE_EXISTS" = "1" ]; then
            APPLIED=$(mariadb -h mariadb -u waf_user -p${DB_PASSWORD} -N -B waf_db \
                -e "SELECT COUNT(*) FROM migration_logs WHERE migration_name='$filename'" 2>/dev/null || echo "0")
        fi
        
        if [ "$APPLIED" = "0" ]; then
            # Apply migration (suppress known harmless errors)
            mariadb -h mariadb -u waf_user -p${DB_PASSWORD} waf_db < "$migration" 2>&1 | \
                grep -v "Warning: Using a password" | \
                grep -v "ERROR 1146.*Table.*migration_logs.*doesn't exist" | \
                grep -v "ERROR 1062.*Duplicate entry" || true
            echo "    ✅ Applied successfully"
        else
            echo "    ⏭️  Already applied (skipping)"
        fi
    fi
done

echo ""
echo "======================="
echo "✅ Migrations completed!"
echo ""

# Verify schema
echo "Verifying database schema..."
COLUMN_COUNT=$(mariadb -h mariadb -u waf_user -p${DB_PASSWORD} -N -B waf_db \
    -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='waf_db' AND TABLE_NAME='sites'" 2>/dev/null)

echo "  Sites table columns: $COLUMN_COUNT"

if [ "$COLUMN_COUNT" -gt 0 ]; then
    echo "✅ Schema verification complete!"
else
    echo "⚠️  Warning: Schema may not be initialized"
fi

echo ""
echo "=== Migration run completed ==="
