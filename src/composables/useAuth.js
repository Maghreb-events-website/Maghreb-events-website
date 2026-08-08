import { ref } from 'vue'

// Relative path - Vite's dev proxy (see vite.config.js) forwards /api/* to
// the backend on http://localhost:3001. In production, point this at your
// deployed backend, or serve it behind the same reverse proxy as the app.
// This endpoint only ever receives the authorization `code`, never the
// client secret - the secret lives exclusively in server/.env.
const API_BASE = import.meta.env.VITE_API_BASE || ''

const VATSIM_AUTH_BASE = import.meta.env.VITE_VATSIM_AUTH_BASE || 'https://auth-dev.vatsim.net'
const CLIENT_ID = import.meta.env.VITE_VATSIM_CLIENT_ID || '1496'
const REDIRECT_URI = import.meta.env.VITE_VATSIM_REDIRECT_URI || `${window.location.origin}/login`
const SCOPES = import.meta.env.VITE_VATSIM_SCOPES || 'full_name email vatsim_details'

const STATE_STORAGE_KEY = 'vatsim_oauth_state'

// Shared, module-level state so every component using this composable sees
// the same logged-in user without needing a store library.
const user = ref(null)
const isLoading = ref(false)
const error = ref(null)
const isInitialized = ref(false)

function randomState() {
  return crypto.randomUUID()
}

/** Kicks off the OAuth2 Authorization Code flow by redirecting to VATSIM Connect. */
function loginWithVatsim() {
  const state = randomState()
  sessionStorage.setItem(STATE_STORAGE_KEY, state)

  const params = new URLSearchParams({
    response_type: 'code',
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    scope: SCOPES,
    state,
  })

  window.location.href = `${VATSIM_AUTH_BASE}/oauth/authorize?${params.toString()}`
}

/**
 * Called on the /login page after VATSIM redirects back with ?code=...&state=...
 * Verifies state, then hands the code to our backend to complete the exchange.
 */
async function completeLogin(code, returnedState) {
  const expectedState = sessionStorage.getItem(STATE_STORAGE_KEY)
  sessionStorage.removeItem(STATE_STORAGE_KEY)

  if (!expectedState || returnedState !== expectedState) {
    error.value = 'Login could not be verified (state mismatch). Please try again.'
    return false
  }

  isLoading.value = true
  error.value = null

  try {
    const response = await fetch(`${API_BASE}/api/auth/vatsim/callback`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include', // send/receive the httpOnly session cookie
      body: JSON.stringify({ code }),
    })

    const data = await response.json()

    if (!response.ok) {
      error.value = data.error_description || 'Login with VATSIM failed.'
      return false
    }

    user.value = data.user
    return true
  } catch {
    error.value = 'Could not reach the login server.'
    return false
  } finally {
    isLoading.value = false
  }
}

/** Restores the session on page load by asking the backend who the cookie belongs to. */
async function fetchCurrentUser() {
  try {
    const response = await fetch(`${API_BASE}/api/auth/me`, {
      credentials: 'include',
    })
    if (response.ok) {
      const data = await response.json()
      user.value = data.user
    } else {
      user.value = null
    }
  } catch {
    user.value = null
  } finally {
    isInitialized.value = true
  }
}

async function logout() {
  try {
    await fetch(`${API_BASE}/api/auth/logout`, {
      method: 'POST',
      credentials: 'include',
    })
  } finally {
    user.value = null
  }
}

export function useAuth() {
  return {
    user,
    isLoading,
    error,
    isInitialized,
    loginWithVatsim,
    completeLogin,
    fetchCurrentUser,
    logout,
  }
}
