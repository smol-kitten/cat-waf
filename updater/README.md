# WAF Auto-Updater

Automatic update and maintenance service for Cat WAF deployments.

## Features

- **Automatic Updates**: Pull and deploy latest Docker images
- **Pre-Update Backups**: Creates database backup before each update
- **Log Cleanup**: Automatically rotates and cleans up old logs
- **Self-Contained**: Runs alongside your WAF deployment without interference
- **Safe Updates**: Can test updates manually before enabling auto-update

## Quick Start

### Option 1: Standalone Updater

```bash
# Run updater as a separate service
docker-compose -f docker-compose.updater.yml up -d
```

### Option 2: Integrated (Prebuilt Compose)

The updater is already included in `docker-compose.prebuilt.yml`. Enable it with:

```bash
# Set AUTO_UPDATE in .env file
echo "AUTO_UPDATE=true" >> .env

# Restart to apply
docker-compose -f docker-compose.prebuilt.yml up -d updater
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AUTO_UPDATE` | `false` | Enable automatic updates |
| `UPDATE_CHECK_INTERVAL` | `3600` | Check interval in seconds (1 hour) |
| `DASHBOARD_API_KEY` | Required | API key for creating backups |

### Example `.env`

```env
# Enable auto-updates
AUTO_UPDATE=true

# Check every 6 hours
UPDATE_CHECK_INTERVAL=21600

# Dashboard API key (for backups)
DASHBOARD_API_KEY=your-api-key-here
```

## Manual Operations

### Force Update Now

```bash
docker exec waf-updater /usr/local/bin/updater.sh update
```

### Force Full Reset (Nuclear Option)

⚠️ **Warning**: This will destroy all data and start fresh!

```bash
# Creates backup, removes volumes, pulls latest, starts clean
docker exec waf-updater /usr/local/bin/updater.sh reset
```

### Check Logs

```bash
docker logs -f waf-updater
```

## How It Works

### Update Cycle

1. **Check**: Every `CHECK_INTERVAL` seconds, checks for new images
2. **Backup**: If updates found, creates database backup to `/backups`
3. **Pull**: Downloads latest Docker images
4. **Deploy**: Stops services, applies updates, starts services
5. **Cleanup**: Removes old Docker images and logs

### Log Cleanup

Runs automatically every cycle:

- **Fail2ban logs**: Truncate if >100MB, delete files >30 days
- **ModSecurity logs**: Truncate if >50MB, delete files >7 days
- **Nginx logs**: Delete access logs >90 days
- **Docker images**: Remove dangling images >7 days old

### Backup Retention

- Keeps last 5 backups in `/backups`
- Backups are standard ZIP files (can be imported via dashboard)
- Backups include: sites, settings, telemetry, logs, rules

## Safety Features

- ✅ **Pre-update backups**: Never lose data
- ✅ **Graceful shutdown**: Services stopped cleanly before update
- ✅ **Health checks**: Monitors updater process
- ✅ **Rollback ready**: Backups can be restored via dashboard
- ✅ **Manual control**: Auto-update is opt-in

## Troubleshooting

### Updater Not Running

```bash
# Check status
docker ps -a | grep updater

# View logs
docker logs waf-updater

# Restart
docker-compose -f docker-compose.prebuilt.yml restart updater
```

### Backups Failing

```bash
# Check API key
docker exec waf-updater env | grep DASHBOARD_API_KEY

# Test backup manually
curl -o test.zip "http://waf-dashboard/backup/export?token=YOUR_KEY"
```

### Updates Not Applying

```bash
# Check AUTO_UPDATE is enabled
docker exec waf-updater env | grep AUTO_UPDATE

# Should show: AUTO_UPDATE=true
```

## Advanced Usage

### Custom Update Schedule

```yaml
# docker-compose.override.yml
services:
  updater:
    environment:
      # Check every 12 hours
      CHECK_INTERVAL: 43200
```

### Disable Specific Cleanup

Edit `updater/updater.sh` and comment out cleanup sections you don't want.

### Notifications

Add webhook or email notifications to `updater.sh`:

```bash
# In perform_update() function
curl -X POST https://your-webhook.com/waf-updated
```

## Migration from Manual Updates

If you've been updating manually:

1. **Stop manual updates**: Remove any cron jobs or scripts
2. **Deploy updater**: Use docker-compose commands above
3. **Enable auto-update**: Set `AUTO_UPDATE=true` in `.env`
4. **Monitor first cycle**: Watch logs to ensure it works

## File Locations

| Path | Description |
|------|-------------|
| `/backups` | Backup ZIP files |
| `/compose` | Docker Compose files (read-only) |
| `/var/log` | Log files for cleanup |
| `/var/run/docker.sock` | Docker socket (for container management) |

## Security Considerations

- **Docker socket access**: Updater has full Docker control (by design)
- **API key exposure**: Store `DASHBOARD_API_KEY` securely
- **Network access**: Updater is on waf-network (can access dashboard)
- **Backup encryption**: Backups are unencrypted ZIP files

## Support

For issues or questions:
- GitHub Issues: https://github.com/smol-kitten/cat-waf/issues
- Documentation: Check main README.md
