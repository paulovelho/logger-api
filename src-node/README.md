# src-node — archived Node implementation

The logger used to be a Node/Express app (MongoDB, later MariaDB with `logger_logs`/`logger_errors` — see `database/schema.sql`); this folder holds its last version, 1.1.2. It was rewritten in PHP (MagratheaPHP2 + MariaDB, see `../src/`).
This folder is kept **for reference only**. It is not deployed and not maintained.

Things that no longer line up if you try to run it:
- `index.js` serves static files from `../public/` and `../openapi.yaml`, which moved to `../src/app/`.
- The admin dashboard (`../src/app/admin.js`) now expects the PHP `{success, data}` envelope.
- Its admin UI is in `public/`, its API reference in `openapi.yaml` and `skill.md`, its DB setup in `scripts/install.sh`.
- Logs written here went to `logger_logs`/`logger_errors`; the PHP version uses the `logs` table in MariaDB (`../database/logs.sql`). Nothing was migrated.
