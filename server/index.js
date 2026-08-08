import 'dotenv/config'
import express from 'express'
import cors from 'cors'
import cookieParser from 'cookie-parser'
import jwt from 'jsonwebtoken'

const trim = (v) => (typeof v === 'string' ? v.trim() : v)

const VATSIM_CLIENT_ID = trim(process.env.VATSIM_CLIENT_ID)
const VATSIM_CLIENT_SECRET = trim(process.env.VATSIM_CLIENT_SECRET)
const VATSIM_REDIRECT_URI = trim(process.env.VATSIM_REDIRECT_URI)
const VATSIM_AUTH_BASE = trim(process.env.VATSIM_AUTH_BASE) || 'https://auth-dev.vatsim.net'
const VATSIM_SCOPES = trim(process.env.VATSIM_SCOPES) || 'full_name email vatsim_details'
const SESSION_SECRET = trim(process.env.SESSION_SECRET)
const FRONTEND_ORIGIN = trim(process.env.FRONTEND_ORIGIN) || 'http://localhost:5173'
const PORT = process.env.PORT || 3001

// Fail fast if the server is misconfigured - better than a confusing 500 later.
for (const [name, value] of Object.entries({
  VATSIM_CLIENT_ID,
  VATSIM_CLIENT_SECRET,
  VATSIM_REDIRECT_URI,
  SESSION_SECRET,
})) {
  if (!value) {
    console.error(`Missing required env var: ${name}. Copy server/.env.example to server/.env and fill it in.`)
    process.exit(1)
  }
}

const TOKEN_URL = `${VATSIM_AUTH_BASE}/oauth/token`
const USER_URL = `${VATSIM_AUTH_BASE}/api/user`

const SESSION_COOKIE = 'maghreb_session'
const SESSION_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000 // 7 days, matches VATSIM access token lifetime

const app = express()
app.use(express.json())
app.use(cookieParser())
app.use(
  cors({
    origin: FRONTEND_ORIGIN,
    credentials: true,
  })
)

/**
 * Step 2 of the OAuth2 Authorization Code flow.
 * The Vue app redirected the user to VATSIM, VATSIM redirected them back to
 * /login?code=..., and the frontend sends us that code. We exchange it for
 * an access token using the client secret, which never leaves this server.
 */
app.post('/api/auth/vatsim/callback', async (req, res) => {
  const { code } = req.body

  if (!code || typeof code !== 'string') {
    return res.status(400).json({ error: 'invalid_request', error_description: 'Missing authorization code' })
  }

  try {
    const tokenResponse = await fetch(TOKEN_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: VATSIM_CLIENT_ID,
        client_secret: VATSIM_CLIENT_SECRET,
        redirect_uri: VATSIM_REDIRECT_URI,
        code,
      }),
    })

    const rawBody = await tokenResponse.text()
    let tokenData
    try {
      tokenData = JSON.parse(rawBody)
    } catch {
      tokenData = { error: 'unknown_error', error_description: rawBody }
    }

    if (!tokenResponse.ok) {
      // Verbose, server-side only logging to make invalid_client / invalid_grant
      // easy to diagnose without ever printing the secret itself.
      console.error('VATSIM token exchange failed:', {
        status: tokenResponse.status,
        body: tokenData,
        sent_client_id: VATSIM_CLIENT_ID,
        sent_redirect_uri: VATSIM_REDIRECT_URI,
        client_secret_length: VATSIM_CLIENT_SECRET.length,
      })
      return res.status(400).json({
        error: tokenData.error || 'token_exchange_failed',
        error_description: tokenData.error_description || 'Could not exchange the authorization code',
      })
    }

    const { access_token } = tokenData

    const userResponse = await fetch(USER_URL, {
      headers: {
        Authorization: `Bearer ${access_token}`,
        Accept: 'application/json',
      },
    })

    const userData = await userResponse.json()

    if (!userResponse.ok) {
      console.error('VATSIM user fetch failed:', userData)
      return res.status(400).json({
        error: 'user_fetch_failed',
        error_description: 'Authenticated with VATSIM but could not load the user profile',
      })
    }

    const profile = buildProfile(userData)

    // We only ever put the public profile in the session cookie, never the
    // VATSIM access/refresh tokens themselves.
    const sessionToken = jwt.sign(profile, SESSION_SECRET, { expiresIn: '7d' })

    res.cookie(SESSION_COOKIE, sessionToken, {
      httpOnly: true,
      secure: process.env.NODE_ENV === 'production',
      sameSite: 'lax',
      maxAge: SESSION_MAX_AGE_MS,
    })

    return res.json({ user: profile })
  } catch (err) {
    console.error('VATSIM login error:', err)
    return res.status(502).json({ error: 'server_error', error_description: 'Could not reach VATSIM Connect' })
  }
})

/** Returns the currently logged-in user (if any) based on the session cookie. */
app.get('/api/auth/me', (req, res) => {
  const token = req.cookies[SESSION_COOKIE]
  if (!token) return res.json({ user: null })

  try {
    const profile = jwt.verify(token, SESSION_SECRET)
    return res.json({ user: profile })
  } catch {
    res.clearCookie(SESSION_COOKIE)
    return res.json({ user: null })
  }
})

app.post('/api/auth/logout', (req, res) => {
  res.clearCookie(SESSION_COOKIE)
  return res.json({ ok: true })
})

function buildProfile(userData) {
  const data = userData?.data ?? {}
  return {
    cid: data.cid,
    firstName: data.personal?.name_first ?? null,
    lastName: data.personal?.name_last ?? null,
    fullName: data.personal?.name_full ?? null,
    email: data.personal?.email ?? null,
    country: data.personal?.country?.name ?? null,
    rating: data.vatsim?.rating?.long ?? null,
    division: data.vatsim?.division?.name ?? null,
    subdivision: data.vatsim?.subdivision?.name ?? null,
  }
}

app.listen(PORT, () => {
  console.log(`VATSIM auth server listening on http://localhost:${PORT}`)
})
