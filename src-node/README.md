# src-node — archived Node implementation

The logger used to be a Node/Express app (MongoDB, later MariaDB with `logger_logs`/`logger_errors` — see `database/schema.sql`); this folder holds its last version, 1.1.2. It was rewritten in PHP (MagratheaPHP2, see `../src/`) as 2.0.0, on the same tables and the same contract, with every response wrapped in the Magrathea `{success, data}` envelope.
This folder is kept **for reference only**. It is not deployed and not maintained.

Things that no longer line up if you try to run it:
- `index.js` serves static files from `../public/` and `../openapi.yaml`, which moved to `../src/app/`.
- The admin dashboard (`../src/app/admin.js`) now expects the PHP `{success, data}` envelope.
- Its admin UI is in `public/`, its API reference in `openapi.yaml` and `skill.md`, its DB setup in `scripts/install.sh`.
