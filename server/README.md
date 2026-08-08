# VATSIM auth server

This is a small Express server whose only job is to complete the VATSIM
Connect OAuth2 "authorization code" exchange. It exists because that step
requires the **client secret**, and a secret used in the Vue app (or any
code that ships to the browser) would be visible to anyone who opens dev
tools. The secret lives only in `server/.env`, which is git-ignored.

## Flow

1. Vue app (`Login.vue`) redirects the browser to
   `https://auth-dev.vatsim.net/oauth/authorize?...&client_id=1496&redirect_uri=http://localhost:5173/login`.
2. VATSIM redirects back to `http://localhost:5173/login?code=...&state=...`.
3. Vue reads `code` from the URL and POSTs it to this server at
   `POST /api/auth/vatsim/callback`.
4. This server exchanges the code for an access token
   (`POST https://auth-dev.vatsim.net/oauth/token`, includes the client
   secret), then fetches the user's profile
   (`GET https://auth-dev.vatsim.net/api/user`).
5. This server issues its own signed session cookie (httpOnly) and returns
   the public profile to the frontend. The VATSIM access/refresh tokens
   never reach the browser.
6. On future page loads, Vue calls `GET /api/auth/me` (cookie sent
   automatically) to check if the user is still logged in.

## Running it

```bash
cd server
npm install
npm run dev      # or: npm start
```

This starts the server on `http://localhost:3001`. The Vue dev server
(`npm run dev` in the project root) proxies `/api/*` requests to it, so run
both at the same time in two terminals:

```bash
# terminal 1
cd server && npm run dev

# terminal 2 (project root)
npm run dev
```

## Configuration

Copy `server/.env.example` to `server/.env` and fill in real values. A
working `server/.env` has already been created for you locally for the
VATSIM sandbox - **do not commit it** (it's covered by `.gitignore`).

| Variable | Purpose |
|---|---|
| `VATSIM_CLIENT_ID` | Public client ID (`1496` for this app) |
| `VATSIM_CLIENT_SECRET` | Secret - server-side only, never sent to the browser |
| `VATSIM_REDIRECT_URI` | Must exactly match what's registered with VATSIM |
| `VATSIM_AUTH_BASE` | `https://auth-dev.vatsim.net` for sandbox, `https://auth.vatsim.net` for production |
| `SESSION_SECRET` | Random string used to sign the session cookie |
| `FRONTEND_ORIGIN` | Where the Vue app runs, for CORS |

## Going to production

- Move `VATSIM_AUTH_BASE` to `https://auth.vatsim.net` and register a
  production client with VATSIM (sandbox credentials/organizations are
  separate from production ones).
- Update `VATSIM_REDIRECT_URI` (and the frontend's `VITE_VATSIM_REDIRECT_URI`)
  to your real domain.
- Set `NODE_ENV=production` so the session cookie is marked `secure` (HTTPS
  only).
- Deploy this server somewhere (it can be tiny - a single small instance or
  serverless function is enough) and point the frontend's `VITE_API_BASE`
  at it, or reverse-proxy `/api` to it from the same domain as the frontend.
