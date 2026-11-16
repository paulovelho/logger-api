# Plan — Scheduled purge of old entries

The logger keeps every entry forever: the purge routes only run when someone calls them. Services whose privacy policy promises a retention period need that purge to run on a schedule. First user: **social**, 180 days (Social's privacy policy, "Registros técnicos").

## What already exists

- `DELETE /admin/logs?service=<s>&olderThanDays=<n>` and `DELETE /admin/errors?…` (`src/app/api/Controls/AdminApi.php:31-53`). They delete entries received (`timestamp`) more than n days ago. `olderThanDays` must be a positive whole number; leaving it out means 365 (`LogControl::DEFAULT_PURGE_DAYS`).
- Both routes need the **readonly credential's** Bearer token (`"readonly": true` in `config.json`). Tokens have no `exp` (`src/app/api/Authentication/LoggerAuth.php:10`): one login, valid until the service entry is removed from `config.json`.
- Each purge writes an audit entry under the `logger` service.

## Steps

1. **Token file**, created once on the server:

   ```bash
   curl -s -X POST https://logger.guia.lol/login -H "Content-Type: application/json" \
     -d '{"service":"<readonly-service>","secret":"<its-secret>"}' \
     | sed 's/.*"token":"\([^"]*\)".*/\1/' | sudo tee /etc/logger/purge.token >/dev/null
   sudo chown www-data:www-data /etc/logger/purge.token
   sudo chmod 600 /etc/logger/purge.token
   ```

   This token can read every service's logs, so it stays readable by the cron user only.

2. **`scripts/purge.sh <service> <days>`**: calls both routes, prints one line per table, and exits non-zero when either call fails, so the purge log shows it.

   ```bash
   #!/usr/bin/env bash
   set -euo pipefail
   SERVICE="${1:?usage: purge.sh <service> <days>}"
   DAYS="${2:?usage: purge.sh <service> <days>}"
   URL="${LOGGER_URL:-https://logger.guia.lol}"
   TOKEN="$(cat "${LOGGER_TOKEN_FILE:-/etc/logger/purge.token}")"
   for table in logs errors; do
     result="$(curl -fsS -X DELETE -H "Authorization: Bearer $TOKEN" \
       "$URL/admin/$table?service=$SERVICE&olderThanDays=$DAYS")"
     echo "$(date -u +%FT%TZ) $SERVICE $table $result"
   done
   ```

3. **Cron**: one line per service, daily, off-peak:

   ```cron
   # /etc/cron.d/logger-purge
   SHELL=/bin/sh
   30 3 * * * www-data /var/www/guia.lol/logger/scripts/purge.sh social 180 >> /var/www/guia.lol/logger/logs/purge.log 2>&1
   ```

   Rotate `purge.log` like any other log:

   ```
   # /etc/logrotate.d/logger-purge
   /var/www/guia.lol/logger/logs/purge.log {
       weekly
       rotate 8
       compress
       missingok
       notifempty
       su www-data www-data
   }
   ```

4. **`deploy.md`**, under Maintenance: the token file, the script, the cron line, and a table of services with their retention periods:

   | Service | Retention | Promised in |
   |---|---|---|
   | social | 180 days | `social.guia.lol/privacidade` |

   Each new row needs a matching promise in that service's privacy policy, and vice versa.

5. **Changelog** and version bump.

## Test

- Run `./scripts/purge.sh social 180` by hand: both lines print `{"deleted": n}`.
- With a bad token, the script exits 1 (curl `-f`) and the purge log shows the error.
- `olderThanDays=0` gets a 400 (the route already validates it).
- The audit entries appear under the `logger` service.

## Notes

- Audit entries pile up at two per service per day. They're small, but if that matters later, purge the `logger` service too, with a longer period.
- If the readonly credential's entry is removed or renamed in `config.json`, the token stops working and the purge fails loudly. Recreate the token file.
- Social also keeps local fallback log files (`api/logs/log_YYYYMM.log`). Those are deleted by Social itself, not by this purge.
