// Shared helpers used by the /api/auth/* serverless functions.
// Each function is deployed as its own isolated Vercel function, so this
// file just centralizes the bits they all need.

export const SESSION_COOKIE = 'maghreb_session'
export const SESSION_MAX_AGE_SECONDS = 7 * 24 * 60 * 60 // 7 days

export const trim = (v) => (typeof v === 'string' ? v.trim() : v)

export function getEnv() {
  return {
    VATSIM_CLIENT_ID: trim(process.env.VATSIM_CLIENT_ID),
    VATSIM_CLIENT_SECRET: trim(process.env.VATSIM_CLIENT_SECRET),
    VATSIM_REDIRECT_URI: trim(process.env.VATSIM_REDIRECT_URI),
    VATSIM_AUTH_BASE: trim(process.env.VATSIM_AUTH_BASE) || 'https://auth-dev.vatsim.net',
    SESSION_SECRET: trim(process.env.SESSION_SECRET),
  }
}

/** Returns the name of the first missing required var, or null if all present. */
export function firstMissingEnvVar(env) {
  for (const name of ['VATSIM_CLIENT_ID', 'VATSIM_CLIENT_SECRET', 'VATSIM_REDIRECT_URI', 'SESSION_SECRET']) {
    if (!env[name]) return name
  }
  return null
}

export function buildProfile(userData) {
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

/** Builds a Set-Cookie header value. Vercel functions run behind HTTPS, so `secure` is always safe/required. */
export function sessionCookie(value, maxAgeSeconds) {
  const parts = [
    `${SESSION_COOKIE}=${value}`,
    'Path=/',
    'HttpOnly',
    'Secure',
    'SameSite=Lax',
    `Max-Age=${maxAgeSeconds}`,
  ]
  return parts.join('; ')
}

export function parseCookies(cookieHeader) {
  const out = {}
  if (!cookieHeader) return out
  for (const part of cookieHeader.split(';')) {
    const idx = part.indexOf('=')
    if (idx === -1) continue
    const key = part.slice(0, idx).trim()
    const val = part.slice(idx + 1).trim()
    if (key) out[key] = decodeURIComponent(val)
  }
  return out
}
