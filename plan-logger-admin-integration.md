# Logger integration — `logger` module changes

## Context

The `admin` Angular app is getting a native "Logger" page (see `plan-logger-admin.md`) that calls `logger`'s `/admin/logs`, `/admin/errors`, `/admin/services` endpoints directly from the browser using a dedicated credential (`admin` is a static SPA with no server of its own, so this secret is unavoidably readable in the shipped JS bundle — accepted, since it's scoped to read-only admin access and the log content itself isn't sensitive).

Investigation of `logger/src` found this credential scoping doesn't exist yet: `app.use(cors())` in `logger/src/index.js` is wide open (any origin), and `/admin/logs`, `/admin/errors`, `/admin/services` (`logger/src/routes/admin.js`) have no admin-specific permission check — *any* valid service token from `config.json` can already call them today. This plan creates a dedicated, admin-scoped, read-only credential for `admin` to use, and locks `/admin/*` down so only that kind of credential can use it, from only the `admin` app's origin.

**This plan must be executed and deployed before `plan-logger-admin.md`** — it creates the credential and permission model that the `admin`-side work depends on.

## Steps

### 1. Add a scoped admin credential
`logger/config.json`: add an entry with a `readonly` flag — named explicitly for what it grants, rather than a generic `admin` flag:
```json
{ "service": "guia-admin", "secret": "<generate a strong random secret>", "name": "Admin Panel", "readonly": true }
```
Existing entries (e.g. `service-website`) get no `readonly` field, so they default to not having it (backward compatible — they already have full unscoped access to `/admin/*` today, which this change also tightens).

### 2. Embed the flag in the JWT
`logger/src/routes/auth.js`: change `jwt.sign({ service: user.service }, ...)` to `jwt.sign({ service: user.service, readonly: !!user.readonly }, ...)` so the permission travels with the token instead of requiring a config lookup on every request.

### 3. Add a permission check
`logger/src/middleware/auth.js`: after verifying the token, also set `req.readonly = payload.readonly === true` (alongside the existing `req.service = payload.service`). Add a new small middleware (e.g. `logger/src/middleware/require-readonly.js`) that 403s if `req.readonly` isn't true. Apply `authenticate, requireReadonly` to all three routes in `logger/src/routes/admin.js` (currently just `authenticate`) — all three (`/logs`, `/errors`, `/services`) are GET-only anyway, so "readonly" fully describes what this credential can do.

### 4. Restrict CORS for `/admin/*` only
`logger/src/index.js` currently does `app.use(cors())` (wide open) before mounting any routes, which also serves other legitimate cross-origin callers (e.g. `admin`'s own `StatusCheckService` hits `logger.guia.lol/health-check` from the browser today). Don't touch that global policy — instead mount a *stricter* `cors()` instance scoped to the `/admin` path, registered **before** the blanket one, so it wins for that path only.

The allowed origin list is kept in its own config file rather than hardcoded in `index.js`, so it can be edited/deployed without touching code — `logger/cors-origins.json`:
```json
{
  "admin": [
    "https://admin.guia.lol",
    "http://localhost:4200"
  ]
}
```
(`http://localhost:4200` is the default Angular dev-server origin, for local testing; drop it if you don't want dev-mode calls to work directly against production `logger.guia.lol`.)

`logger/src/index.js`:
```js
const corsOrigins = require('../cors-origins.json');

app.use('/admin', cors({ origin: corsOrigins.admin }), adminRoutes);
app.use(cors());
// ... other app.use('/login', ...) etc as today
```

No other `logger` route or file needs to change. `GET /admin` (serving `admin.html`) is unaffected since it's a same-origin static page, not a cross-origin API call.

### 5. Version bump
Bump `package.json` and the root `version` file from `1.0.0` to `1.1.0`, and add a `changelog.md` entry describing the change (note: `changelog.md` already has a `### 1.1.0` heading at the top with a "Rise and Shine" entry, while `package.json`/`version` are still at `1.0.0` — that entry predates this bump and doesn't describe it; reconcile by extending that existing `1.1.0` section with this change rather than creating a duplicate heading), e.g.:
```
### 1.1.0
2026-07
	- **new:** Rise and Shine
	- **new:** scoped read-only admin credential + per-route CORS restriction on /admin/*, for the guia.lol admin app integration
```

## Verification
- Restart the `logger` service after the changes.
- `curl -X POST https://logger.guia.lol/login -d '{"service":"guia-admin","secret":"..."}'` returns a token.
- That token succeeds on `GET /admin/logs`.
- A non-readonly service's token (e.g. `service-website`) now gets a 403 on `/admin/logs` (previously it would have succeeded — confirms the tightening didn't just add a no-op check).
- A cross-origin request to `/admin/logs` from an origin not listed in `cors-origins.json` is rejected by CORS; `/health-check` still works from any origin (confirms the global policy wasn't accidentally narrowed).
- The existing `logger.guia.lol/admin` static tool still logs in and works (it uses whatever credential is entered at its login screen — verify with an existing non-readonly or the new `guia-admin` credential, both should still work end-to-end there since that page doesn't rely on CORS at all).
