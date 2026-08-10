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
