# Apply Database Migrations (Windows)
# Usage: .\scripts\apply-migrations.ps1 [migration-file]

# Load environment variables from .env
if (Test-Path .env) {
    Get-Content .env | ForEach-Object {
        if ($_ -match '^([^#][^=]+)=(.+)$') {
            [System.Environment]::SetEnvironmentVariable($matches[1].Trim(), $matches[2].Trim(), 'Process')
        }
    }
}

$DB_ROOT_PASSWORD = $env:DB_ROOT_PASSWORD
if (-not $DB_ROOT_PASSWORD) {
    $DB_ROOT_PASSWORD = "your_root_password_here"
}

$CONTAINER_NAME = "waf-mariadb"

Write-Host "=== WAF Database Migration Tool ===" -ForegroundColor Cyan
Write-Host ""

# Check if container is running
$containerRunning = docker ps --format "{{.Names}}" | Select-String -Pattern $CONTAINER_NAME -Quiet
if (-not $containerRunning) {
    Write-Host "❌ Error: MariaDB container '$CONTAINER_NAME' is not running" -ForegroundColor Red
    Write-Host "   Start it with: docker compose up -d mariadb" -ForegroundColor Yellow
    exit 1
}

# If specific migration file provided
if ($args.Count -gt 0) {
    $MIGRATION_FILE = $args[0]
    
    if (-not (Test-Path $MIGRATION_FILE)) {
        Write-Host "❌ Error: Migration file not found: $MIGRATION_FILE" -ForegroundColor Red
        exit 1
    }
    
    Write-Host "📄 Applying migration: $(Split-Path $MIGRATION_FILE -Leaf)" -ForegroundColor Yellow
    Write-Host ""
    
    # Copy to container and execute
    docker cp $MIGRATION_FILE "${CONTAINER_NAME}:/tmp/migration.sql"
    Get-Content $MIGRATION_FILE | docker exec -i $CONTAINER_NAME mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db
    
    Write-Host "✅ Migration applied successfully!" -ForegroundColor Green
    exit 0
}

# Apply all migrations in order
Write-Host "📦 Applying all migrations..." -ForegroundColor Yellow
Write-Host ""

$migrations = Get-ChildItem -Path "mariadb\init\*-migration-*.sql" | Sort-Object Name

foreach ($migration in $migrations) {
    Write-Host "  → Applying: $($migration.Name)" -ForegroundColor Cyan
    docker cp $migration.FullName "${CONTAINER_NAME}:/tmp/migration.sql"
    Get-Content $migration.FullName | docker exec -i $CONTAINER_NAME mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db 2>&1 | Where-Object { $_ -notmatch "Warning: Using a password" }
}

Write-Host ""
Write-Host "✅ All migrations applied!" -ForegroundColor Green
Write-Host ""
Write-Host "🔍 Verifying schema..." -ForegroundColor Yellow

# Count columns in sites table
$COLUMN_COUNT = docker exec $CONTAINER_NAME mariadb -u root -p"$DB_ROOT_PASSWORD" -sN waf_db -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'waf_db' AND TABLE_NAME = 'sites';" 2>&1 | Where-Object { $_ -notmatch "Warning" }

Write-Host "   Sites table has $COLUMN_COUNT columns" -ForegroundColor Cyan
Write-Host ""

# Check for new columns
Write-Host "📋 Checking new columns (2025-10-17 migration):" -ForegroundColor Yellow
docker exec $CONTAINER_NAME mariadb -u root -p"$DB_ROOT_PASSWORD" waf_db -e @"
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_DEFAULT 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = 'waf_db' 
  AND TABLE_NAME = 'sites' 
  AND COLUMN_NAME IN ('disable_http_redirect', 'cf_bypass_ratelimit', 'cf_custom_rate_limit', 'cf_rate_limit_burst')
ORDER BY COLUMN_NAME;
"@ 2>&1 | Where-Object { $_ -notmatch "Warning" }

Write-Host ""
Write-Host "✅ Migration verification complete!" -ForegroundColor Green
