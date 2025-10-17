# Database Schema Update - 2025-10-16

## What Changed

The database initialization files have been updated to reflect the current production schema from the running MariaDB container.

### Before
- Multiple migration files (01-05) that were potentially out of sync
- Schema definitions spread across multiple files
- Some fields/columns might have been missing

### After
- Single authoritative schema file: `01-complete-schema.sql`
- Exported directly from running production database
- Includes all current tables with exact field definitions

## Tables Included

1. **sites** - Site configuration with all features (58 columns)
2. **banned_ips** - IP ban management
3. **access_logs** - HTTP access logging
4. **modsec_events** - ModSecurity event logs
5. **api_tokens** - API authentication tokens
6. **settings** - System settings
7. **bot_detections** - Bot detection logs
8. **request_telemetry** - Request performance metrics

## Backup

Old schema files have been moved to `old-backup/` directory for reference.

## How to Use

1. For fresh installations, the `01-complete-schema.sql` will run automatically
2. For existing deployments, the schema is already in place (no migration needed)
3. The schema file is used when MariaDB container is initialized with an empty volume

## Notes

- All tables use UTF8MB4 character set for full Unicode support
- Proper indexes are in place for performance
- JSON validation constraints on `sites.backends` field
- Timestamps default to current time where appropriate
