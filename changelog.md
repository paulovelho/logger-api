### 1.1.2
2026-09
	- **new:** `DELETE /admin/logs` and `DELETE /admin/errors` — purge a service's logs/errors older than `olderThanDays` (default 7); each purge writes a self-audit entry into `logger_logs` under the reserved `service: 'logger'`
	- **new:** `GET /admin/services` now includes a synthetic `Logger (self)` entry so the audit trail's service is selectable without a `config.json` credential

### 1.1.1
2026-08
	- **new:** optional `active` flag on `config.json` service credentials — inactive services are blocked from `POST /log` and `POST /error`, and excluded from `GET /admin/services`
	- **fix:** admin dashboard `services` tab no longer crashes on a failed `/admin/services` request

### 1.1.0
2026-08
	- **new:** scoped read-only admin credential + global CORS allowlist, for the guia.lol admin app integration

### 1.0.0
2026-07
	- **new:** Rise and Shine
