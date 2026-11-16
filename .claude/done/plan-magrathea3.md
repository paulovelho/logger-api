# Plan: Upgrade to MagratheaPHP 3.0

Agreed with Paulo on 2026-11-16, on branch `update/magratheaphp3`. **Status: hops 1 and 2 done and green on 3.0.0 (74 tests), not committed: both hops land in one diff, ready for Paulo to commit. What's left is the deploy (section 4, Paulo's).** The vendor-admin checks (2.6, 3.8) moved to the deploy checklist (section 4), Paulo's decision.

Guides (self-contained, followed section by section):
- `/mnt/Rincewind/MagratheaPHP3/docs/mds/migrations/migrate-2.4.0.md` (hop 1)
- `/mnt/Rincewind/MagratheaPHP3/docs/mds/migrations/migrate-3.0.md` (hop 2; ships in `src/vendor/platypustechnology/magratheaphp/docs/mds/migrations/` after the swap)
- 2.3.x changelog: `/mnt/Rincewind/MagratheaPHP2/src/changelog.md` (`### 2.3.4`, `### 2.3.5`)
- Writing PHP: the `magrathea-php2` skill during hop 1, the `magrathea-php` skill from 3.1 on.

"In the container" = `docker exec -w /var/www/logger/src logger_php …` (`./scripts/reboot.sh` first).

---

## 0. Starting point

- Installed: **MagratheaPHP2 2.3.3**, pinned exactly (`"2.3.3"` in `src/composer.json`). The latest 2.x is 2.4.0. The project inherits 2.3.4, 2.3.5 and every 2.4.0 change. PHP 8.4 (Docker).
- Bootstrap: `src/app/_inc.php`, next to `src/composer.json`. That's the CLI's default path (`app/_inc.php`), so no `extra.magrathea.bootstrap` is needed (M24). `src/configs/` is outside the docroot (`src/app`) ✓ (M27).
- 2 models, `Log` and `ErrorLog`, with generated `Base` classes. Their types (`int`/`string`/`text`/`datetime`) are all valid in 3.0. **Nothing instantiates them**: `LogControl` does every read and write in raw SQL, and extends `LogControlBase` only nominally. `logger_logs`/`logger_errors` have no `created_at`/`updated_at`, which 3.0's `check`/`models:verify` report as an ERROR (M13).
- No custom admin features: `magrathea.php` is `StartDefault("Logger Admin")`. `setup.php` is dead (`die;` on its first line).
- Clients already get real HTTP statuses (since 1.2.1): `LoggerApi::ReturnError()`/`ReturnFail()` do what 2.4 #16 does. They also log non-API exceptions and answer with a generic message. 3.0's own `ReturnError()` would put the exception object in `error`, and `MagratheaDBException`'s public `query`/`values`/`fullMessage` would reach the client. **They stay.**
- `LoggerAuth` overrides `jwtEncode()`/`jwtDecode()` so tokens are signed with the raw `jwt_key` (Node-issued tokens). 3.0 still `strtr`s the key (M5), so those overrides stay too. 2.3.5's `jwtDecode()` change doesn't reach it.
- Checked against the 3.0 source:
  - no name collisions with new framework methods (`LoggerApi`'s private `Cors`/`General`/`Logs`/`Admin`, `LogControl`'s statics, `LoggerAuth`'s `Login`/`Token`/`Input`/`Verify`);
  - the overrides are signature-compatible (`ReturnError()` is now `?int $status = null`);
  - none of `Query::Clean`, `ShowAll`, `LoadVendor`, the query builder, `mysqli_report`, `QueryTransaction`, `AddAcceptHeaders`, `GetById` on input, or duplicate basenames.
- Timezone: `timezone = "UTC"` is set in `[dev]` and `[production]` (other instances may use another zone). `time_zone` in `[general]` is the old key, and it was always ignored. Columns are `DATETIME(3)` and every value comes from PHP's clock (1.2.3), so 2.4 #34's session sync changes no stored or returned date.
- Prod (`logger.guia.lol`) uses `guia_lol`, the api's database, with the `guia_lol` user. The api is also on 2.3.3 and migrates separately, so both admins share the `_magrathea_*` tables until then.
- Tests: the PHPUnit 12 integration suite (`./scripts/test.sh`, inside the container). Its result before step 2.1 is the baseline.
  - **Baseline (2.3.3): OK, 71 tests, 306 assertions.** It first needed two fixes to the local, gitignored `.env`: `JWT_SECRET` was 30 bytes (every login failed the ≥ 32-byte check), replaced with a random 48-byte one; `DB_HOST` was `guia_lol_sql`, now `logger_db`. The old `.env` is in the session scratchpad.
  - Locally `MYSQL_ROOT_PASSWORD` isn't in `.env`, so `logger_db`'s root login doesn't work; the app user has `ALL` on its database, which is enough for the migrations.

## 1. Decisions

| Question | Decision |
|---|---|
| One hop or two? | **Two hops, one deploy.** 2.3.3 → 2.4, suite green, Paulo commits; then 2.4 → 3.0. Only the 3.0 result is deployed: neither hop changes the API contract. |
| The tables have no `created_at`/`updated_at` (M13) | **Add the columns** to both tables, with a new re-runnable migration and in `schema.sql`. Keep the models. The columns belong to the database (column defaults): the logger never writes them and the API never returns them. No backfill: existing rows get the migration's time. |
| `$strictTypes` (M9) | **No pin (strict, the 3.0 default).** No code loads the models, so nothing changes in PHP or JSON. |
| Vendor admin (`magrathea.php`) | **Kept.** Its users can reach all of `guia_lol` through it. That's already true on 2.x, and 3.0 adds the query editor and dump import. M1 applies. |
| `setup.php` | **Deleted.** It's a 2.x wizard entry file (M27), and the logger doesn't use the wizard. |
| 2.3 workarounds in `LogControl` | **Removed in hop 1** (2.4 #27, #30; M17a): the output buffer and null-id check around `PrepareAndExecute()`, `Query() === false`, and the count-then-delete in `Purge()` (now `LastAffectedRows()`). |
| `LoggerApi::ReturnError()`/`ReturnFail()` | **Kept** (see 0), only aligned with 3.0's signature. |
| MySQL session timezone sync (2.4 #34) | **On** (no `db_sync_timezone`). No effect on the logger's `DATETIME` data. It also puts the new `created_at`/`updated_at` defaults in Magrathea's zone, like `timestamp`. |
| CORS (M29) | **Keep `cors-origins.json` + `Allow()`.** `AllowFromFile()` reads `configs/cors-origins.txt`, which would mean a different file and format on every instance, and there's no CORS admin page to replace. `Allow()` now also sends `Vary: Origin`. |
| `backups_path` | **Set it explicitly**, outside the docroot. Without it, the admin's backups/dumps of `guia_lol` land in `src/backups/`, inside the git checkout and not ignored. Also add `backups/` to `.gitignore`. |
| `_magrathea_*` cleanups (M3 step 6, M13 `last_login`) | **Not on `guia_lol`** until the api is on 3.0: its 2.x admin still reads `code_path`/`code_structure`/`code_namespace`. |
| Version | **1.3.0**: a framework upgrade with no API contract change. |
| Composer | `magratheaphp2: ^2.4` (hop 1), then `magratheaphp: ^3.0` (hop 2). |
| Backups / staging | **Paulo does them** on the deploy. |

## 2. Hop 1: 2.3.3 → 2.4

### 2.1 Composer
- `src/composer.json`: change `"platypustechnology/magratheaphp2": "2.3.3"` to `"^2.4"`, then run `composer update platypustechnology/magratheaphp2` in the container. Confirm `src/vendor/platypustechnology/magratheaphp2/src/version` says `2.4.x`.

### 2.2 Bootstrap and entry points
- `src/app/_inc.php`:
  - remove the `try/catch` + `p_r($ex)`: the bootstrap neither prints nor catches;
  - `MinVersion("2.4.0")`;
  - keep `->Prod()`.
- `src/app/index.php`: catch `\Throwable` around the include, `new LoggerApi()` and `Run()`, `error_log()` it, and answer HTTP 500 with the envelope `{"success": false, "data": {"message": "Internal server error"}}`. Never print the exception. (A config the framework can't load still gets its own error page, from `Load()`.)
- `src/app/magrathea.php`: the same catch, answering a plain-text 500 (it's a page).
- Delete `src/app/setup.php`. In `src/app/.htaccess`, the CSP rule `m#^/(magrathea|setup)\.php#` becomes `m#^/magrathea\.php#`; fix its comment too.

### 2.3 Config (`src/configs/magrathea.conf.example`; the local `magrathea.conf` and each instance's by hand)
- Remove `time_zone = "UTC"` from `[general]` (2.4 #33). It's ignored; `timezone` in each section is the real key.
- Add `backups_path`:
  - `[dev]`: `/var/lib/logger/backups`, and add it to the `mkdir`/`chown` in `docker/Dockerfile`;
  - `[production]`: `<path>/backups`, next to the logs and cache folders.
- No `db_sync_timezone`, `db_port` or `bootstrap_unlocked`.
- `.gitignore`: add `backups/`.

### 2.4 `LogControl`: drop the 2.3 workarounds
- `InsertRows()`: remove `ob_start()`/`ob_end_clean()` and the `!is_int($id)` check, and return `(int)$id`. `PrepareAndExecute()` now throws `MagratheaDBException` on any failure (#27), and `LoggerApi::ReturnError()` logs it and answers 500. The message changes from `Failed to save log` to `Internal server error`; the status is the same.
- `Purge()`: run the `DELETE`, then read `Database::Instance()->LastAffectedRows()` right after it (#30), before the audit `Write()`. This replaces the `SELECT COUNT(*)` that ran first. Drop the `=== false` check: `Query()` throws now. Keep the single cutoff.
- Class docblock: drop "Query::Clean is too weak to rely on, and PrepareAndExecute() can't return rows". Keep "every input is validated before it reaches SQL".
- Remove the unused `use logger\ErrorLog\ErrorLog;`.
- `src/tests/BatchTest.php::testInsertFailureRollsBackEveryRow`: expect a `MagratheaDBException` only.
- New `src/tests/PurgeTest.php`, calling `LogControl::Purge()` directly:
  - set up rows with an old `timestamp` (through `InsertRows()`) under a throwaway service (`phpunit-<run>`), so no real service's rows are touched, plus a few newer ones;
  - after the purge, `deleted` matches the old rows, those rows are gone, the newer ones stay, and the audit row exists;
  - cleanup deletes the throwaway service's rows and its audit row (`JSON_VALUE(data, '$.targetService')`).

### 2.5 The 2.3.x and 2.4.0 items without their own step
These were checked and need no change. Record them in the report:
- **2.3.4** (query builder escaping) and **2.3.5** (`jwtDecode()`, `IsLogged()`): not used, or overridden.
- **#3, #14** (raw mode): the suite calls over HTTP.
- **#16–#19**: the statuses are already real. `IsService()` answers a missing token with its own 401. #18 turns PHP `\Error`s into a logged JSON 500 (new, and an improvement).
- **#21** (`_debug`): the bootstrap always runs `->Prod()`.
- **#23, #25, #29**: no model writes.
- **#28**: DB errors arrive as `MagratheaDBException`, with the errno as their code. `LoggerApi::ReturnError()` maps any code outside 400–599 to 500 and keeps the exception out of the body ✓.
- **#30**: no long-running workers. The connection is now one per request, so the "per-query connections" reason in CLAUDE.md's timezone note goes. The "never `NOW()`" rule stays, because the DB clock still isn't trusted.
- **#32** no `db_port`; **#35** no duplicate basenames; **#36** no `catch (MagratheaException)` around class use.
- Admin (`StartDefault`): **#2** first-run setup only with zero users; **#5** the File Editor is gone (there's no subclass, and it isn't needed); **#43** the Query Tester is gone; **#44** backups go through `proc_open()`; **#46–#48** no custom pages.

### 2.6 Verify
- `./scripts/reboot.sh`, then `./scripts/test.sh`: same result as the baseline, plus `PurgeTest`.
- `curl`: an unknown route gives the 404 envelope, `/health-check` says `database: ok`, `/version` answers, and `POST /log` without a token gives 401.
- `magrathea.php`: log in; the Backups page writes a dump into `backups_path` (delete it afterwards).
- Paulo commits hop 1. Nothing is deployed.

**Done 2026-11-16:** 2.4.0 installed; suite OK (74 tests, 317 assertions: the baseline's 71 + 3 `PurgeTest`); `curl`: unknown route 404 envelope, `/health-check` `database: ok`, `/version` 200, `POST /log` without a token 401, `/setup.php` 404; no test rows left behind; `AdminDatabase::LoadBackupFolder()` resolves `/var/lib/logger/backups` (`www-data`-owned).
**Not run locally:** the `magrathea.php` login and Backups dump. The vendor admin was never installed on `logger_db` (no `_magrathea_*` tables, so `magrathea.php` shows the vendor's own 500 page, as on 2.3.3), and the container has no `mysqldump`. **Decided:** this check and the admin checks in 3.8 move to the deploy checklist (section 4, step 6). Nothing is installed locally, and `docker/Dockerfile` gets no `mariadb-client`.

## 3. Hop 2: 2.4 → 3.0

### 3.1 Composer (section 2 of the guide)
- **Blocked until `platypustechnology/magratheaphp` is on Packagist.** On 2026-11-16 the GitHub repo was public with `v3.0.0` tagged and pushed, but Packagist answered 404 (it was never submitted). Paulo submits it. The first attempt ran the `remove` after the failed `require` and uninstalled 2.4; `composer.json`/`composer.lock` were rebuilt from HEAD + step 2.1, and the suite was green again (74 tests). Steps 3.4 and section 5 don't need the package, so they went first.
- In the container: `composer require "platypustechnology/magratheaphp:^3.0" && composer remove platypustechnology/magratheaphp2` (the `&&` keeps 2.4 installed if the require fails).
- `src/composer.json` needs no `extra.magrathea.bootstrap` (the default path matches) and has no app `autoload` mapping to check.

### 3.2 Rename (M2)
- Replace `Magrathea2` with `Magrathea` in all PHP files, with a plain text substitution. That's 18 files: 12 in `src/app` (`setup.php` is already gone) and 6 in `src/tests` (`bootstrap.php` and hop 1's `PurgeTest.php` included).
- Non-PHP hits: `src/composer.lock` (regenerated), `.claude/done/plan-php-migration.md` (history, leave it) and this plan.
- Run `composer dump-autoload`. Both searches (`Magrathea2`, `magratheaphp2`) must come back empty outside `vendor/`, `.git/`, `.claude/` and `changelog.md`.

### 3.3 Generator leftovers and models (M3, M6, M7, M9)
- There's no `magrathea_objects.conf` ✓.
- Strip the `## FILE GENERATED BY MAGRATHEA.` header from the 4 Base files (it's 2 lines here).
- `LogBase`/`ErrorLogBase`:
  - remove `implements iMagratheaModel` and its `use` line;
  - delete the constructor, `public $created_at, $updated_at;` (with its "not columns" comment, which 3.4 makes false) and `protected $autoload = null;`. `MagratheaModel` provides all three (M6);
  - keep `MagratheaStart()` and `GetControl()`.
- `Log`/`ErrorLog`: delete their constructors (they only call the parent's).
- The types are valid (M7). No `$strictTypes` pin (see 1).

### 3.4 Timestamp columns (M11, M13)
- New `database/migrations/1.3.0-timestamps.sql`, re-runnable (`ADD COLUMN IF NOT EXISTS`), for both tables:
  - `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`;
  - `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.

  This is M13's declaration, the same one `check` proposes. The header says it needs `ALTER` (run it as root) and that it works with 1.2.3 code.
- `database/schema.sql`: the same two columns in both `CREATE TABLE`s. Header comment: framework bookkeeping, filled by the database, never read by the API.
- Nothing else changes. `InsertRows()`, `Search()`, `Services()`, `FormatRow()` and the tests all name their columns, so the new ones never reach a response.
- Local: `docker compose exec -T logger_db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/1.3.0-timestamps.sql` (the app user: local root has no password set in `.env`, see 0). The init scripts only run on an empty volume.
- `deploy.md` step 3: list the new migration. The `GRANT`s don't change: the logger never updates a row.

**Done 2026-11-16 (before 3.1, still on 2.4):** `database/migrations/1.3.0-timestamps.sql` written and applied to `logger_db` twice (the second run is a no-op; `SHOW CREATE TABLE` shows M13's declaration); `schema.sql` updated, and its syntax checked on temporary copies of the tables; `deploy.md` step 3 lists both migrations in order and says 1.3.0's can run before the pull. The suite on 2.4 with the new columns: OK, 74 tests.

### 3.5 Bootstrap on 3.0 (M5)
- `_inc.php`:
  - `MinVersion("3.0.0")`;
  - add `->Connect()` after `Load()`. It only stores the credentials; the connection opens on the first query.
- Remove `StartDb()` from `LoggerApi::Initialize()` and from `src/tests/bootstrap.php`, and fix that file's comment.

### 3.6 `LoggerApi`
- `ReturnError($code=500, $message="", $data=null, ?int $status=null)`, testing `$status === null` instead of `== 200`.
- Rewrite the comments of both overrides. The vendor now sends real statuses; the overrides log non-API exceptions and keep their internals (SQL, values) out of the body.
- `ReturnFail()`, `LoggerAuth` and `Cors()`: nothing beyond the rename.

### 3.7 Vendor admin (M1, M18–M23, M27)
- Every admin has to log in again after the deploy.
- Inactive users can't log in: `SELECT id, email, active FROM _magrathea_users WHERE active IS NULL OR active = 0;`. On `guia_lol` that table is the api's, so those users keep their api admin access but lose the logger's. Paulo checks the list.
- The default menu gains the Database section (explorer, queries, query editor, dumps, checks). Dumps writes to `backups_path` (2.3).
- `configs/` is outside the docroot ✓; there's no `bootstrap_unlocked` ✓.

### 3.8 Verify and document
- In the container: `vendor/bin/magrathea check` and `vendor/bin/magrathea models:verify` report no ERROR. Expected: OK for both models and the four timestamp columns; locally, a WARNING if there's no `_magrathea_config` (the admin isn't installed there).
- `./scripts/test.sh` is green, and the `curl` checks from 2.6 pass.
- `magrathea.php`: not checked locally (Paulo's decision, see 2.6); its checks are in section 4, step 6.
- The logs (`/var/lib/logger/logs`) show no `Unknown field type`, `Call to undefined method`, `Class … not found` or `Access level`.
- No M10–M12 checks: nothing inserts or updates through the models, and `updated_at` never changes because the logger never updates a row.
- `changelog.md`: a `### 1.3.0` entry covering:
  - MagratheaPHP 3.0, with no API change; DB failures are still a generic 500;
  - the `created_at`/`updated_at` columns and their migration;
  - `setup.php` removed;
  - the vendor admin's new Database section, and every admin logs in again;
  - `Vary: Origin`.
- `version` and `src/app/openapi.yaml` `info.version` → `1.3.0`.
- `CLAUDE.md`:
  - stack line: MagratheaPHP 3.0, the `magrathea-php` skill, `src/vendor/platypustechnology/magratheaphp/skills.MD` and `docs/mds/index.md`;
  - project structure: the new migration, no `setup.php`, `magrathea.php` listed, `src-node/` gone;
  - design notes: rewrite the `PrepareAndExecute()` paragraph (it throws; purge uses `LastAffectedRows()`), the timezone note (#30), the reason for the `ReturnError`/`ReturnFail` overrides, the timestamp columns, `vendor/bin/magrathea check`.
- `README.md:5` and `deploy.md:3`: "MagratheaPHP2" → "MagratheaPHP 3". The root `../CLAUDE.md` logger section gets the same change, and drop its `src-node/` sentence. `.dockerignore`: drop `src-node`.
- Paulo commits hop 2.

**Done 2026-11-16 (3.1–3.3, 3.5–3.8):** Packagist served v3.0.0; `composer require … && remove …` installed 3.0.0 and removed 2.4.0. 18 PHP files renamed; no `Magrathea2`/`magratheaphp2` left outside history. Base files: header, `iMagratheaModel`, constructor, timestamp/`$autoload` declarations and the dead `// >>> relations:` marker removed; `Log`/`ErrorLog` constructors gone. `StartDb()` → `->Connect()`. `ReturnError(…, ?int $status = null)`. Suite OK (74 tests, 317 assertions). `check`: 19 OK, 1 WARNING (no `_magrathea_config` locally), no ERROR; `models:verify`: 6 OK. `curl`: same as 2.6, plus `Vary: Origin`, `/version` 1.3.0. Logs: none of the 3.8 strings; `magrathea.php` 500 is the missing `_magrathea_users` table, as on 2.x. Docs: changelog 1.3.0, `version`, `openapi.yaml`, `CLAUDE.md`, `README.md`, `deploy.md`, `../CLAUDE.md` (logger section), `.dockerignore`.

## 4. Deploy checklist (Paulo, per instance)

1. Back up the logger tables: `mysqldump <database> logger_logs logger_errors > logger-$(date +%F).sql`.
2. Check the table sizes: `SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('logger_logs', 'logger_errors');`. Then, as root, run `database/migrations/1.3.0-timestamps.sql` when a rebuild of that size is acceptable. It can block writes while it runs, and it's safe with 1.2.3 still deployed, so it can go first.
3. `magrathea.conf` by hand:
   - delete `time_zone` from `[general]`;
   - add `backups_path` (a writable folder outside the docroot);
   - leave `timezone` alone.
4. Deploy (`./scripts/deploy.sh` → `restart.sh`: git pull, `composer install --no-dev`, `build.sh`). The server's CLI and web PHP are both 8.1 or later.
5. Check:
   - `cd src && vendor/bin/magrathea check`: no ERROR. On `guia_lol`, the api's tables show up as INFO "No model maps…", which is expected;
   - `/health-check` says `database: ok`, and `/version` says `1.3.0`;
   - a fresh crawler/api entry shows up in `/admin`.
6. Admins log in again on `magrathea.php`. On `guia_lol`, skip the `_magrathea_config` DELETE (M3) and the `last_login` ALTER (M13) until the api is on 3.0. These admin checks were never run locally (no vendor admin on `logger_db`), so run them here:
   - log in, then open Database → Explorer and Checks (same findings as the CLI's `check`);
   - Dumps: export a dump into `backups_path`, then delete it (without `mysqldump` on the server, `Dump` uses its PHP driver);
   - the browser console shows no CSP violation. If it does, adjust the `magrathea.php` CSP in `src/app/.htaccess` and `site.caddy.example`.
7. Clients and the `logger-api` skill need nothing: the contract is unchanged.

## 5. Extra: `reboot.sh` waits for the database

Found during hop 1, independent of the framework upgrade (local tooling only, no API change). It can go in either hop.

- **Problem:** `scripts/reboot.sh` prints "Ready" as soon as `/health-check` answers 200. That route answers 200 even with `"database": "fail"`, and `docker compose down/up` restarts `logger_db` too, so "Ready" can come while MariaDB is still starting. A `./scripts/test.sh` run right after then fails with `Connection refused`.
- **Fix:** in the wait loop, require `"database":"ok"` in the body: `curl -fs "http://localhost:$PORT/health-check" | grep -q '"database":"ok"'`. The timeout message names both causes (PHP not answering, or the DB not ready) and points at `docker compose logs logger_php logger_db`.
- **Verify:** `./scripts/reboot.sh && ./scripts/test.sh` passes on the first try, several times in a row.
- `changelog.md` 1.3.0: a `**dev:**` bullet.

**Done 2026-11-16 (in hop 2, before 3.1):** `scripts/reboot.sh` greps the body for `"database":"ok"`, and its timeout message names both causes. Three `reboot.sh && test.sh` rounds in a row: "Ready", then OK (74 tests) on the first try each time. With `logger_db` stopped, `/health-check` answered 200 with `"database":"fail"` and the check kept waiting. The changelog bullet is written with the rest of 1.3.0 in 3.8.
