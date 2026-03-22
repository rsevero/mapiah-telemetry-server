# mapiah-telemetry-server

Anonymous usage telemetry server for [Mapiah](https://github.com/rsevero/mapiah).

Receives aggregated daily records from Mapiah clients, stores them in a two-tier
retention structure (daily for 366 days, then monthly forever), and exposes a
read-only admin dashboard.

API contract: see `openapi/telemetry.yaml` in the Mapiah client repository.

## Requirements

- PHP 8.1+
- MySQL 8.0+ or MariaDB 10.5+ (JSON column support required)
- Apache with `mod_rewrite` enabled

## First deploy

```bash
git clone git@github.com:rsevero/mapiah-telemetry-server.git
cd mapiah-telemetry-server

# 1. Create the database
mysql -u root -p -e "CREATE DATABASE mapiah_telemetry CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p mapiah_telemetry < schema/schema.sql

# 2. Configure credentials
cp config.php.example config.php
# Edit config.php with your DB host, name, user, and password.

# 3. Set document root to public/ in Hostinger control panel.

# 4. Update the AuthUserFile path in public/.htaccess to the absolute path of
#    public/admin/.htpasswd on your server.

# 5. Create the admin password
htpasswd -c -B public/admin/.htpasswd admin

# 6. Add the daily cron job in Hostinger (cron manager):
#    0 3 * * * php /home/YOUR_USER/mapiah-telemetry-server/cron/rollup.php
```

## Subsequent deploys

```bash
git pull
# Re-run schema/schema.sql only if there are schema changes (uses IF NOT EXISTS — safe to re-run).
```

## Security notes

- `config.php` and `public/admin/.htpasswd` are gitignored — never committed.
- Client IP addresses are never stored; the rate limiter stores SHA-256 hashes only.
- All DB queries use PDO prepared statements.
- Admin access requires HTTP Basic Auth over HTTPS.
