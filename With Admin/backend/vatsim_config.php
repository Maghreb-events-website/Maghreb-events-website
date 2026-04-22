<?php
// ══════════════════════════════════════════════════════════════════════════
//  Maghreb Events — Site Configuration
// ══════════════════════════════════════════════════════════════════════════

// ── VATSIM OAuth ──────────────────────────────────────────────────────────
define('VATSIM_SANDBOX',       true);
define('VATSIM_CLIENT_ID',     '1383');
define('VATSIM_CLIENT_SECRET', '4UzF6OiKm940MbdKH6MH6LAdgJVN2l4h6nH9x5so');
define('VATSIM_REDIRECT_URI',  'https://jamie-datson.com/callback');

// ── JWT secret ────────────────────────────────────────────────────────────
define('JWT_SECRET', 'MghrbEvts_k9mX2pQvL8rNwT5jYhD3bF7sC1uA6eG0oI4n_2025v2!');

// ── Database path ─────────────────────────────────────────────────────────
//
// Uses a path relative to this file so it always resolves correctly regardless
// of where on the server the vhost root is. The data/ folder is blocked from
// public access by .htaccess.
//
// If you want the DB outside the web root (recommended for production so it
// survives re-deployments), change this to an absolute path your server user
// can write to, e.g.:
//   define('MAGHREB_DB_PATH', '/var/www/vhosts/jamie-datson.co.uk/private/maghreb.db');
//
define('MAGHREB_DB_PATH', __DIR__ . '/data/maghreb.db');

// ── Discord Bot Bridge ────────────────────────────────────────────────────
define('BOT_BRIDGE_TOKEN', 'assdffsdfdsfdsfsdfgebbsvsadfggnsdbsvagnbsdvavsbsdbsb');
define('BOT_BRIDGE_URL',   'http://127.0.0.1:5001/send');
