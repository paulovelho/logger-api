# src-node — archived Node implementation

The logger used to be a Node/Express + MongoDB app. It was rewritten in PHP (MagratheaPHP2 + MariaDB, see `../src/`).
This folder is kept **for reference only**. It is not deployed and not maintained.

Things that no longer line up if you try to run it:
- `index.js` serves static files from `../public/` and `../openapi.yaml`, which moved to `../src/app/`.
- The admin dashboard (`../src/app/admin.js`) now expects the PHP `{success, data}` envelope.
- Logs written here went to MongoDB; the PHP version uses the `logs` table in MariaDB (`../database/logs.sql`). Nothing was migrated.
