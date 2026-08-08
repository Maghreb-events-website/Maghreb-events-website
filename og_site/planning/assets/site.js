/* ────────────────────────────────────────────────────────────────────────────
   Maghreb Events — shared client runtime (static / hardcoded version)
   - No API calls — all data is hardcoded in each page
   - Theme (dark/light) persistence + toggle
   - Header / nav injection
   ──────────────────────────────────────────────────────────────────────────── */

window.ME = window.ME || {};

ME.ADMIN_CIDS = [
  '10000000','10000001','10000002','10000003','10000004',
  '10000005','10000006','10000007','10000008','10000009',
  '1635257','1797446'
];

// ── Theme ──────────────────────────────────────────────────────────────────
ME.theme = {
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

// ── Auth (stripped — no login/admin in static mode) ────────────────────────
ME.auth = {
  token()   { return null; },
  user()    { return null; },
  isAdmin() { return false; },
  logout()  { window.location.href = '/'; },
  headers() { return {}; },
};

// ── Header / nav ───────────────────────────────────────────────────────────
ME.nav = {
  links: [
    { href: '/',         label: 'Home',          key: 'home' },
    { href: '/partners', label: 'Partners',       key: 'partners' },
    { href: '/airlines', label: 'Airlines',       key: 'airlines' },
    { href: '/planning', label: 'Planning Team',  key: 'planning' },
    { href: '/hitsquad', label: 'Hitsquad',       key: 'hitsquad' },
    { href: '/briefing', label: 'Pilot Briefing', key: 'briefing' },
    { href: '/giveaway', label: 'Giveaways',      key: 'giveaway' },
  ],
  inject(activeKey) {
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
        </nav>
        <button class="me-hamburger" id="me-hamburger" type="button" aria-label="Menu">
          <span class="material-symbols-outlined">menu</span>
        </button>
      </header>
      <div class="me-mobile-panel" id="me-mobile-panel">
        ${ME.nav.links.map(l => `<a href="${l.href}" class="${l.key === activeKey ? 'active' : ''}">${l.label}</a>`).join('')}
        <button id="me-theme-btn-mobile" class="me-btn me-btn--ghost" style="margin-top:4px">Toggle Classic / VDGS</button>
      </div>
    `;

    document.querySelectorAll('body > header').forEach(h => h.remove());
    document.body.insertAdjacentHTML('afterbegin', header);

    document.getElementById('me-theme-btn').addEventListener('click', ME.theme.toggle);
    document.getElementById('me-theme-btn-mobile')?.addEventListener('click', ME.theme.toggle);
    document.getElementById('me-hamburger').addEventListener('click', () => {
      document.getElementById('me-mobile-panel').classList.toggle('open');
    });
  },
};

// ── Bootstrap ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  ME.theme.set(ME.theme.get());
  if (!document.body.hasAttribute('data-no-nav')) {
    ME.nav.inject(document.body.dataset.activeNav || '');
    ME.theme._refreshIcon();
    ME.theme._refreshLogos();
    if (!document.querySelector('.me-page')) {
      document.body.style.paddingTop = '80px';
    }
  }
});
