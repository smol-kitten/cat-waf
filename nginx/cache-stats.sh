#!/bin/sh
# Cache statistics script for NGINX
# Returns JSON with cache zone statistics

CACHE_DIR="/var/cache/nginx"

# Find all cache zones
ZONES=$(find "$CACHE_DIR" -maxdepth 1 -type d -name '*_cache' -o -name '__cache' 2>/dev/null | sed 's|.*/||')

echo "{"
echo '  "zones": ['

FIRST=1
for ZONE in $ZONES; do
    ZONE_PATH="$CACHE_DIR/$ZONE"
    
    # Count files and total size
    FILE_COUNT=$(find "$ZONE_PATH" -type f 2>/dev/null | wc -l)
    TOTAL_SIZE=$(find "$ZONE_PATH" -type f -exec stat -c '%s' {} + 2>/dev/null | awk '{sum+=$1} END {print sum+0}')
    
    # Add comma before subsequent entries
    if [ $FIRST -eq 0 ]; then
        echo ","
    fi
    FIRST=0
    
    echo "    {"
    echo "      \"name\": \"$ZONE\","
    echo "      \"items\": $FILE_COUNT,"
    echo "      \"size\": $TOTAL_SIZE"
    echo -n "    }"
done

echo ""
echo "  ],"

# Overall statistics
TOTAL_ITEMS=$(find "$CACHE_DIR" -type f 2>/dev/null | wc -l)
TOTAL_SIZE=$(find "$CACHE_DIR" -type f -exec stat -c '%s' {} + 2>/dev/null | awk '{sum+=$1} END {print sum+0}')

echo "  \"total\": {"
echo "    \"items\": $TOTAL_ITEMS,"
echo "    \"size\": $TOTAL_SIZE"
echo "  }"
echo "}"
