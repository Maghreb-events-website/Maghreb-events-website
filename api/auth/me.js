import jwt from 'jsonwebtoken'
import { getEnv, parseCookies, sessionCookie, SESSION_COOKIE } from '../_auth-helpers.js'

/** Returns the currently logged-in user (if any) based on the session cookie. */
export default function handler(req, res) {
  const { SESSION_SECRET } = getEnv()
  const cookies = parseCookies(req.headers.cookie)
  const token = cookies[SESSION_COOKIE]

  if (!token || !SESSION_SECRET) return res.status(200).json({ user: null })

  try {
    const profile = jwt.verify(token, SESSION_SECRET)
    return res.status(200).json({ user: profile })
  } catch {
    res.setHeader('Set-Cookie', sessionCookie('', 0))
    return res.status(200).json({ user: null })
  }
}
