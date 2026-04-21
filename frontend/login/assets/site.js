/* ────────────────────────────────────────────────────────────────────────────
   Maghreb Events — shared client runtime
   - API base URL
   - Theme (dark/light) persistence + toggle
   - Header / nav injection (constant on every page, on the right)
   - Auth helpers
   ──────────────────────────────────────────────────────────────────────────── */

window.ME = window.ME || {};
(() => {
  const isFileOrigin = window.location.protocol === 'file:';
  const originApi = isFileOrigin ? '' : window.location.origin.replace(/\/$/, '') + '/api';
  const knownApi = 'https://webapi.jamie-datson.com/api';
  const configured = window.ME_API || localStorage.getItem('me_api');

  // Evict any stale cached API URL that isn't the canonical one.
  // This clears leftover values (e.g. api.jamie-datson.com) set by old code.
  if (configured && configured !== knownApi) {
    localStorage.removeItem('me_api');
  }

  ME.API_CANDIDATES = [knownApi];
  ME.API = knownApi;
})();

ME.ADMIN_CIDS = [
  '10000000','10000001','10000002','10000003','10000004',
  '10000005','10000006','10000007','10000008','10000009',
  '1635257','1797446'
];

// VATSIM OAuth public config — only the *client ID* lives client-side.
// The client secret stays on the backend (config/auth.php → env vars).
ME.VATSIM = {
  CLIENT_ID:    '1383',
  AUTHORIZE:    'https://auth-dev.vatsim.net/oauth/authorize',  // VATSIM Connect Sandbox
  REDIRECT_URI: 'https://jamie-datson.com/callback',            // Must match exactly what is registered on auth-dev.vatsim.net
  SCOPES:       'full_name email vatsim_details',
};

// ── Theme ──────────────────────────────────────────────────────────────────
ME.theme = {
  // Classic = Light (default), VDGS = Dark
  get()  { return localStorage.getItem('me_theme') || 'light'; },
  set(v) {
    localStorage.setItem('me_theme', v);
    document.documentElement.classList.toggle('light', v === 'light');
    document.documentElement.classList.toggle('dark',  v !== 'light');
    ME.theme._refreshLogos();
    ME.theme._refreshIcon();
  },
  toggle() { ME.theme.set(ME.theme.get() === 'light' ? 'dark' : 'light'); },
  _refreshLogos() {
    const dark  = ME.theme.get() !== 'light';
    document.querySelectorAll('img[data-me-logo]').forEach(img => {
      // Dark mode: use the white/light logo (visible on dark background)
      // Light mode: use the black/dark logo (visible on light background)
      img.src = dark
        ? 'assets/img/Maghreb_vACC_Full_white_trans_1.png'
        : 'assets/img/Maghreb_vACC_Full_black_trans.png';
    });
  },
  _refreshIcon() {
    const btn = document.getElementById('me-theme-btn');
    if (!btn) return;
    btn.title = ME.theme.get() === 'light' ? 'Switch to VDGS (Dark)' : 'Switch to Classic (Light)';
    btn.innerHTML = ME.theme.get() === 'light'
      ? '<span class="material-symbols-outlined" style="font-size:18px">dark_mode</span>'
      : '<span class="material-symbols-outlined" style="font-size:18px">light_mode</span>';
  },
};

// ── Auth ───────────────────────────────────────────────────────────────────
ME.auth = {
  token() { return localStorage.getItem('me_token'); },
  user()  { try { return JSON.parse(localStorage.getItem('me_user') || 'null'); } catch { return null; } },
  isAdmin() {
    const u = ME.auth.user();
    if (!u) return false;
    const cid = String(u.cid || '').trim();
    const role = String(u.role || '').trim().toLowerCase();
    return role === 'admin' || role === 'superadmin' || ME.ADMIN_CIDS.includes(cid);
  },
  logout() { localStorage.removeItem('me_token'); localStorage.removeItem('me_user'); window.location.href = '/'; },
  normalizeUser(user) {
    const raw = (user && typeof user === 'object') ? user : {};
    const payload = ME.auth.tokenPayload() || {};
    const normalized = {
      id: raw.id ?? (payload.sub ? Number(payload.sub) : 0),
      cid: String(raw.cid ?? payload.cid ?? '').trim(),
      name: raw.name ?? payload.name ?? '',
      email: raw.email ?? payload.email ?? '',
      role: String(raw.role ?? payload.role ?? 'user').trim().toLowerCase(),
    };
    if (normalized.role === 'user' && ME.ADMIN_CIDS.includes(String(normalized.cid || ''))) {
      normalized.role = 'admin';
    }
    return normalized;
  },
  setUser(user) {
    if (!user) localStorage.removeItem('me_user');
    else localStorage.setItem('me_user', JSON.stringify(ME.auth.normalizeUser(user)));
  },
  tokenPayload() {
    const token = ME.auth.token();
    if (!token) return null;
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    try {
      const base64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
      const json = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
      return JSON.parse(json);
    } catch {
      return null;
    }
  },
  hydrateUserFromToken() {
    const user = ME.auth.user();
    if (user && user.role) return user;
    const payload = ME.auth.tokenPayload();
    if (!payload) return user || null;
    const hydrated = ME.auth.normalizeUser(payload);
    ME.auth.setUser(hydrated);
    return hydrated;
  },
  async syncUser() {
    const token = ME.auth.token();
    if (!token) {
      ME.auth.setUser(null);
      return null;
    }
    const hydrated = ME.auth.hydrateUserFromToken();
    for (const apiBase of ME.API_CANDIDATES || [ME.API]) {
      try {
        const res = await fetch(`${apiBase}/auth/me`, { headers: { 'Authorization': 'Bearer ' + token, 'X-Auth-Token': 'Bearer ' + token } });
        if (res.status === 401) {
          // Keep current token-derived session state even if /auth/me is blocked
          // by environment/CORS quirks (common in file:// style usage).
          return hydrated;
        }
        if (!res.ok) continue;
        const data = await res.json();
        if (data && data.admin) {
          ME.API = apiBase;
          localStorage.setItem('me_api', apiBase);
          ME.auth.setUser(data.admin);
          return data.admin;
        }
      } catch {
        // Try next API candidate
      }
    }
    return hydrated;
  },
  headers() {
    const t = ME.auth.token();
    return t ? { 'Authorization': 'Bearer ' + t, 'X-Auth-Token': 'Bearer ' + t } : {};
  },
  startVatsimOAuth() {
    if (!ME.VATSIM.CLIENT_ID || ME.VATSIM.CLIENT_ID === 'YOUR_SANDBOX_CLIENT_ID') {
      alert('VATSIM OAuth client ID is not configured.\n\nEdit assets/site.js and set ME.VATSIM.CLIENT_ID to your auth-dev.vatsim.net client id.');
      return;
    }
    const state = (crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2));
    sessionStorage.setItem('vatsim_oauth_state', state);
    const params = new URLSearchParams({
      response_type: 'code',
      client_id:     ME.VATSIM.CLIENT_ID,
      redirect_uri:  ME.VATSIM.REDIRECT_URI,
      scope:         ME.VATSIM.SCOPES,
      state,
    });
    window.location.href = ME.VATSIM.AUTHORIZE + '?' + params.toString();
  },
};

// ── Header / nav ───────────────────────────────────────────────────────────
ME.nav = {
  links: [
    { href: '/',    label: 'Home',          key: 'home' },
    { href: '/partners', label: 'Partners',      key: 'partners' },
    { href: '/airlines', label: 'Airlines',      key: 'airlines' },
    { href: '/planning', label: 'Planning Team', key: 'planning' },
    { href: '/hitsquad', label: 'Hitsquad',      key: 'hitsquad' },
    { href: '/briefing', label: 'Pilot Briefing', key: 'briefing' },
    { href: '/giveaway', label: 'Giveaways',      key: 'giveaway' },
  ],
  inject(activeKey) {

    ME.auth.hydrateUserFromToken();
    const u = ME.auth.user();
    const isAdmin = u && ME.auth.isAdmin();
    const isSignedIn = (!!u && !!ME.auth.token()) || !!ME.auth.tokenPayload();

    let cta;
    if (isAdmin) {
      cta = `<a href="/admin" class="me-nav-cta">Admin Panel</a>
             <a href="#" id="me-logout" class="me-nav-cta" style="background:transparent;border:1px solid var(--me-border);color:var(--me-fg-muted) !important">Logout</a>`;
    } else if (isSignedIn) {
      // Signed in but not an admin — show CID and logout
      cta = `<span class="me-nav-cta" style="background:transparent;border:1px solid var(--me-border);color:var(--me-fg-muted);cursor:default;pointer-events:none">CID ${u.cid}</span>
             <a href="#" id="me-logout" class="me-nav-cta" style="background:transparent;border:1px solid var(--me-border);color:var(--me-fg-muted) !important">Logout</a>`;
    } else {
      cta = `<a href="/login" class="me-nav-cta">Login</a>`;
    }

    const linksHTML = ME.nav.links.map(l => `
      <a href="${l.href}" class="${l.key === activeKey ? 'active' : ''}">${l.label}</a>
    `).join('');

    const header = `
      <header class="me-header">
        <a class="me-brand" href="/">
          <img data-me-logo alt="Maghreb vACC" src="assets/img/Maghreb_vACC_Full_white_trans_1.png"/>
        </a>
        <nav class="me-nav">
          ${linksHTML}
          <button id="me-theme-btn" class="me-theme-toggle" type="button" aria-label="Toggle theme"></button>
          ${cta}
        </nav>
        <button class="me-hamburger" id="me-hamburger" type="button" aria-label="Menu">
          <span class="material-symbols-outlined">menu</span>
        </button>
      </header>
      <div class="me-mobile-panel" id="me-mobile-panel">
        ${ME.nav.links.map(l => `<a href="${l.href}" class="${l.key === activeKey ? 'active' : ''}">${l.label}</a>`).join('')}
        ${isAdmin
          ? `<a href="/admin">Admin Panel</a>`
          : isSignedIn
            ? `<span style="color:var(--me-fg-muted);padding:8px 0;font-size:13px">CID ${u.cid}</span>`
            : `<a href="/login">Login</a>`}
        ${isSignedIn ? '<a href="#" id="me-logout-mobile">Logout</a>' : ''}
        <button id="me-theme-btn-mobile" class="me-btn me-btn--ghost" style="margin-top:4px">Toggle Classic / VDGS</button>
      </div>
    `;

    // Remove any pre-existing header from the original markup — we own this now.
    document.querySelectorAll('body > header').forEach(h => h.remove());
    document.body.insertAdjacentHTML('afterbegin', header);

    document.getElementById('me-theme-btn').addEventListener('click', ME.theme.toggle);
    document.getElementById('me-theme-btn-mobile')?.addEventListener('click', ME.theme.toggle);
    document.getElementById('me-hamburger').addEventListener('click', () => {
      document.getElementById('me-mobile-panel').classList.toggle('open');
    });
    document.getElementById('me-logout')?.addEventListener('click', e => { e.preventDefault(); ME.auth.logout(); });
    document.getElementById('me-logout-mobile')?.addEventListener('click', e => { e.preventDefault(); ME.auth.logout(); });
  },
};

// ── Bootstrap ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  // Apply persisted theme as soon as possible
  ME.theme.set(ME.theme.get());
  if (ME.auth.token()) {
    await ME.auth.syncUser();
  }
  // Pages can opt out by setting <body data-no-nav>
  if (!document.body.hasAttribute('data-no-nav')) {
    ME.nav.inject(document.body.dataset.activeNav || '');
    ME.theme._refreshIcon();
    ME.theme._refreshLogos();
    // Push content below fixed header if the page didn't add the spacer class
    if (!document.querySelector('.me-page')) {
      document.body.style.paddingTop = '80px';
    }
  }
});
