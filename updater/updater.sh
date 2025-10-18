#!/bin/bash
set -e

# Configuration
CHECK_INTERVAL=${CHECK_INTERVAL:-3600}  # Check every hour
COMPOSE_FILE=${COMPOSE_FILE:-/compose/docker-compose.prebuilt.yml}
COMPOSE_OVERRIDE=${COMPOSE_OVERRIDE:-/compose-override/docker-compose.override.yml}
BACKUP_API=${BACKUP_API:-http://waf-dashboard/backup/export}
BACKUP_IMPORT_API=${BACKUP_IMPORT_API:-http://waf-dashboard/backup/import}
BACKUP_DIR=${BACKUP_DIR:-/backups}
PROJECT_NAME=${PROJECT_NAME:-waf}
DASHBOARD_API_KEY=${DASHBOARD_API_KEY:-your_dashboard_api_key_here}

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Function to create backup before updates
create_backup() {
    local backup_file="$BACKUP_DIR/backup-$(date +'%Y%m%d-%H%M%S').zip"
    local is_reset=${1:-false}
    
    log "Creating backup: $backup_file" >&2
    
    # Try backup with authentication (use -H header for proper API auth)
    if curl -f -s -S -o "$backup_file" \
        -H "Authorization: Bearer ${DASHBOARD_API_KEY}" \
        "${BACKUP_API}" 2>&1 >&2; then
        local file_size=$(stat -c%s "$backup_file" 2>/dev/null || echo "0")
        
        # Verify backup is not empty
        if [ "$file_size" -lt 100 ]; then
            log "❌ BACKUP FAILED! File is too small ($file_size bytes)" >&2
            rm -f "$backup_file"
            if [ "$is_reset" = "true" ]; then
                log "FATAL: Cannot proceed with reset without valid backup!" >&2
                exit 1
            else
                return 1
            fi
        fi
        
        log "✅ Backup created successfully: $backup_file" >&2
        log "   Size: $(du -h "$backup_file" | cut -f1)" >&2
        
        # Keep only last 5 backups
        ls -t "$BACKUP_DIR"/backup-*.zip | tail -n +6 | xargs -r rm 2>/dev/null || true
        echo "$backup_file"  # Return filename via stdout
        return 0
    else
        log "❌ BACKUP FAILED!" >&2
        if [ "$is_reset" = "true" ]; then
            log "FATAL: Cannot proceed with reset without backup! Aborting to prevent data loss." >&2
            exit 1
        else
            log "WARNING: Backup failed, continuing with update anyway..." >&2
            return 1
        fi
    fi
}

# Function to import backup after fresh start
import_backup() {
    local backup_file=$1
    if [ ! -f "$backup_file" ]; then
        log "ERROR: Backup file not found: $backup_file"
        return 1
    fi
    
    log "Waiting for services to be ready..."
    sleep 15  # Wait for MariaDB and dashboard to start
    
    log "Importing backup: $backup_file"
    
    # Upload backup file
    if curl -f -X POST \
        -H "Authorization: Bearer ${DASHBOARD_API_KEY}" \
        -F "backup=@$backup_file" \
        -F "import_sites=1" \
        -F "import_settings=1" \
        -F "import_telemetry=1" \
        -F "import_bot_detections=1" \
        -F "import_modsec_events=1" \
        -F "import_access_logs=1" \
        -F "import_custom_block_rules=1" \
        -F "import_rate_limit_rules=1" \
        -F "merge_mode=replace" \
        "${BACKUP_IMPORT_API}" 2>&1; then
        log "Backup imported successfully!"
        return 0
    else
        log "ERROR: Failed to import backup"
        return 1
    fi
}

# Function to check for updates
check_updates() {
    log "Checking for updates..."
    
    # Pull latest images
    cd /compose
    local compose_cmd="docker compose -f $COMPOSE_FILE"
    
    # Add override if it exists
    if [ -f "$COMPOSE_OVERRIDE" ]; then
        compose_cmd="$compose_cmd -f $COMPOSE_OVERRIDE"
    fi
    
    # Check if updates available
    if $compose_cmd pull 2>&1 | grep -q "Downloaded newer image"; then
        log "Updates found!"
        return 0
    else
        log "No updates available"
        return 1
    fi
}

# Function to perform update
perform_update() {
    log "Starting update process..."
    
    # Create backup first
    create_backup
    
    cd /compose
    local compose_cmd="docker compose -f $COMPOSE_FILE -p $PROJECT_NAME"
    
    if [ -f "$COMPOSE_OVERRIDE" ]; then
        compose_cmd="$compose_cmd -f $COMPOSE_OVERRIDE"
    fi
    
    # Stop services
    log "Stopping services..."
    $compose_cmd down
    
    # Pull latest images (already done in check_updates, but ensuring)
    log "Pulling latest images..."
    $compose_cmd pull
    
    # Start services
    log "Starting services..."
    $compose_cmd up -d
    
    log "Update complete!"
}

# Function to cleanup old logs and data
cleanup_logs() {
    log "Cleaning up old logs..."
    
    # Cleanup fail2ban logs older than 30 days
    find /var/log/fail2ban -name "*.log.*" -type f -mtime +30 -delete 2>/dev/null || true
    
    # Truncate large fail2ban log if over 100MB
    local f2b_log="/var/log/fail2ban/fail2ban.log"
    if [ -f "$f2b_log" ]; then
        local size=$(stat -f%z "$f2b_log" 2>/dev/null || stat -c%s "$f2b_log" 2>/dev/null || echo "0")
        if [ "$size" -gt 104857600 ]; then  # 100MB
            log "Truncating large fail2ban.log ($(($size/1048576))MB)"
            tail -n 10000 "$f2b_log" > "$f2b_log.tmp"
            mv "$f2b_log.tmp" "$f2b_log"
        fi
    fi
    
    # Cleanup modsec audit logs older than 7 days
    find /var/log/modsec -name "*.log" -type f -mtime +7 -delete 2>/dev/null || true
    
    # Truncate large modsec audit log if over 50MB
    local modsec_log="/var/log/modsec/modsec_audit.log"
    if [ -f "$modsec_log" ]; then
        local size=$(stat -f%z "$modsec_log" 2>/dev/null || stat -c%s "$modsec_log" 2>/dev/null || echo "0")
        if [ "$size" -gt 52428800 ]; then  # 50MB
            log "Truncating large modsec_audit.log ($(($size/1048576))MB)"
            tail -n 5000 "$modsec_log" > "$modsec_log.tmp"
            mv "$modsec_log.tmp" "$modsec_log"
        fi
    fi
    
    # Cleanup nginx access logs older than 90 days
    find /var/log/nginx -name "access*.log.*" -type f -mtime +90 -delete 2>/dev/null || true
    
    # Cleanup docker system (remove dangling images)
    docker system prune -f --filter "until=168h" 2>/dev/null || true
    
    log "Cleanup complete"
}

# Function to force full reset
force_reset() {
    local auto_import=${1:-false}
    
    log "=========================================="
    log "FORCE RESET INITIATED"
    log "=========================================="
    log ""
    log "Step 1/5: Creating backup before destruction..."
    
    # Create backup and capture the filename
    local backup_file
    backup_file=$(create_backup true)
    local backup_status=$?
    
    # Double check: if backup failed, create_backup should have exited already
    # but we check again to be absolutely sure
    if [ $backup_status -ne 0 ] || [ -z "$backup_file" ] || [ ! -f "$backup_file" ]; then
        log ""
        log "=========================================="
        log "❌ FATAL ERROR: Backup creation failed!"
        log "=========================================="
        log "Cannot proceed with reset without a valid backup."
        log "This is a safety measure to prevent data loss."
        log ""
        log "Troubleshooting:"
        log "  1. Check that waf-dashboard container is running"
        log "  2. Verify DASHBOARD_API_KEY is set correctly"
        log "  3. Check network connectivity between updater and dashboard"
        log "  4. Check disk space in $BACKUP_DIR"
        log ""
        exit 1
    fi
    
    log ""
    log "✅ Step 1/5 Complete: Backup saved"
    log "   Location: $backup_file"
    log "   Size: $(du -h "$backup_file" | cut -f1)"
    log ""
    
    log "Step 2/5: Destroying all containers and volumes..."
    log "⚠️  WARNING: This will delete ALL data (containers + volumes)!"
    
    cd /compose
    local compose_cmd="docker compose -f $COMPOSE_FILE"
    
    if [ -f "$COMPOSE_OVERRIDE" ]; then
        compose_cmd="$compose_cmd -f $COMPOSE_OVERRIDE"
    fi
    
    # Stop and remove all containers individually (except updater which is running this script)
    # We can't use 'down -v' because it would stop the updater container too
    log "   Stopping and removing containers..."
    docker ps -a --filter "name=^waf-" --format "{{.Names}}" | grep -v "^waf-updater$" | xargs -r docker stop 2>/dev/null || true
    docker ps -a --filter "name=^waf-" --format "{{.Names}}" | grep -v "^waf-updater$" | xargs -r docker rm -f 2>/dev/null || true
    
    log "   Removing volumes..."
    docker volume ls --filter "name=^waf" --format "{{.Name}}" | xargs -r docker volume rm -f 2>/dev/null || true
    
    log ""
    log "✅ Step 2/5 Complete: Old infrastructure destroyed"
    log "" 
    
    log "Step 3/5: Starting core services (MariaDB, Migration-Runner, Dashboard)..."
    log "$compose_cmd up -d mariadb migration-runner dashboard"
    $compose_cmd up -d mariadb migration-runner dashboard
    
    log ""
    log "✅ Step 3/5 Complete: Core services started"
    log ""
    
    log "Step 4/5: Waiting for services to become healthy..."
    sleep 20  # Initial wait
    local retries=12
    local wait_time=5
    local attempt=1
    
    while [ $attempt -le $retries ]; do
        # Try different container name formats
        local mariadb_health=$(docker inspect --format='{{.State.Health.Status}}' "waf-mariadb" 2>/dev/null || \
                               docker inspect --format='{{.State.Health.Status}}' "${PROJECT_NAME}-mariadb-1" 2>/dev/null || \
                               echo "unknown")
        
        if [ "$mariadb_health" = "healthy" ]; then
            log "   ✅ MariaDB is healthy"
            break
        else
            log "   ⏳ Waiting for MariaDB... (attempt $attempt/$retries, status: $mariadb_health)"
            sleep $wait_time
            attempt=$((attempt + 1))
        fi
    done
    
    if [ $attempt -gt $retries ]; then
        log ""
        log "   ⚠️  WARNING: MariaDB health check timed out after $retries attempts"
        log "   Continuing anyway - migrations may take longer..."
    fi
    
    log ""
    log "✅ Step 4/5 Complete: Core services ready"
    log ""

    # Import backup if requested
    if [ "$auto_import" = "true" ]; then
        log "Step 5/5: Restoring data from backup..."
        log ""
        if import_backup "$backup_file"; then
            log ""
            log "✅ Step 5/5 Complete: Data restored successfully!"
            log ""
        else
            log ""
            log "❌ Step 5/5 FAILED: Backup import failed"
            log ""
            log "Manual restore required:"
            log "  Backup file: $backup_file"
            log "  Use the dashboard import feature to restore"
            log ""
        fi
    else
        log "Step 5/5: Skipping auto-restore (manual restore mode)"
        log ""
        log "✅ Reset complete - manual restore required"
        log "   Backup location: $backup_file"
        log ""
    fi

    # Start remaining services
    log "Starting remaining services (nginx, fail2ban, etc.)..."
    $compose_cmd up -d
    
    log ""
    log "=========================================="
    log "✅ FORCE RESET COMPLETED SUCCESSFULLY"
    log "=========================================="
    log ""
    log "Summary:"
    log "  - Backup created: $backup_file"
    log "  - Old infrastructure destroyed"
    log "  - Fresh deployment started"
    log "  - Data restore: $([ "$auto_import" = "true" ] && echo "Attempted" || echo "Manual required")"
    log ""
    log "All services are now starting up..."
    log ""
}

# Check if force reset requested
if [ "$1" = "reset" ]; then
    force_reset false
    exit 0
fi

# Check if force reset with auto-import requested
if [ "$1" = "reset-restore" ]; then
    force_reset true
    exit 0
fi

# Main loop
log "Auto-updater started (checking every ${CHECK_INTERVAL}s)"
log "Compose file: $COMPOSE_FILE"
log "Override file: $COMPOSE_OVERRIDE"

while true; do
    # Cleanup logs every cycle
    cleanup_logs
    
    # Check for updates
    if check_updates; then
        # If AUTO_UPDATE is enabled, perform update
        if [ "$AUTO_UPDATE" = "true" ]; then
            perform_update
        else
            log "AUTO_UPDATE is disabled. Set AUTO_UPDATE=true to enable automatic updates."
        fi
    fi
    
    # Wait for next check
    log "Next check in ${CHECK_INTERVAL}s..."
    sleep "$CHECK_INTERVAL"
done
