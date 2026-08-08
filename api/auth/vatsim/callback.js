import jwt from 'jsonwebtoken'
import {
  getEnv,
  firstMissingEnvVar,
  buildProfile,
  sessionCookie,
  SESSION_MAX_AGE_SECONDS,
} from '../../_auth-helpers.js'

/**
 * Step 2 of the OAuth2 Authorization Code flow.
 * The Vue app redirected the user to VATSIM, VATSIM redirected them back to
 * /login?code=..., and the frontend POSTs that code here. We exchange it for
 * an access token using the client secret, which never leaves this function.
 */
export default async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST')
    return res.status(405).json({ error: 'method_not_allowed' })
  }

  const env = getEnv()
  const missing = firstMissingEnvVar(env)
  if (missing) {
    console.error(`Missing required env var: ${missing}. Set it in the Vercel project's Environment Variables.`)
    return res.status(500).json({ error: 'server_misconfigured', error_description: `Missing ${missing}` })
  }

  const { code } = req.body || {}
  if (!code || typeof code !== 'string') {
    return res.status(400).json({ error: 'invalid_request', error_description: 'Missing authorization code' })
  }

  try {
    const tokenResponse = await fetch(`${env.VATSIM_AUTH_BASE}/oauth/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code',
        client_id: env.VATSIM_CLIENT_ID,
        client_secret: env.VATSIM_CLIENT_SECRET,
        redirect_uri: env.VATSIM_REDIRECT_URI,
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
      console.error('VATSIM token exchange failed:', {
        status: tokenResponse.status,
        body: tokenData,
        sent_client_id: env.VATSIM_CLIENT_ID,
        sent_redirect_uri: env.VATSIM_REDIRECT_URI,
      })
      return res.status(400).json({
        error: tokenData.error || 'token_exchange_failed',
        error_description: tokenData.error_description || 'Could not exchange the authorization code',
      })
    }

    const { access_token } = tokenData

    const userResponse = await fetch(`${env.VATSIM_AUTH_BASE}/api/user`, {
      headers: { Authorization: `Bearer ${access_token}`, Accept: 'application/json' },
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

    // Only the public profile goes in the session cookie - never the
    // VATSIM access/refresh tokens themselves.
    const sessionToken = jwt.sign(profile, env.SESSION_SECRET, { expiresIn: '7d' })

    res.setHeader('Set-Cookie', sessionCookie(sessionToken, SESSION_MAX_AGE_SECONDS))
    return res.status(200).json({ user: profile })
  } catch (err) {
    console.error('VATSIM login error:', err)
    return res.status(502).json({ error: 'server_error', error_description: 'Could not reach VATSIM Connect' })
  }
}
