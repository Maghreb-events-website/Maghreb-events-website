<?php
// ══════════════════════════════════════════════════════════════════════════
//  Maghreb Events — Site Configuration
// ══════════════════════════════════════════════════════════════════════════

// ── VATSIM OAuth ──────────────────────────────────────────────────────────
define('VATSIM_SANDBOX',       true);
define('VATSIM_CLIENT_ID',     '1383');
define('VATSIM_CLIENT_SECRET', '4UzF6OiKm940MbdKH6MH6LAdgJVN2l4h6nH9x5so');
define('VATSIM_REDIRECT_URI',  'https://jamie-datson.com/callback');
define('BOT_BRIDGE_TOKEN', 'assdffsdfdsfdsfsdfgebbsvsadfggnsdbsvagnbsdvavsbsdbsb');  // same value as above
define('BOT_BRIDGE_URL',   'http://127.0.0.1:5001/send');

// ── JWT secret ────────────────────────────────────────────────────────────
define('JWT_SECRET', 'MghrbEvts_k9mX2pQvL8rNwT5jYhD3bF7sC1uA6eG0oI4n_2024!');

// ── Database path (IMPORTANT — prevents data loss on deployment) ──────────
//
// Set this to a path OUTSIDE your web deployment folder so that uploading
// a new version of the site does not overwrite or delete your database.
//
// Recommended: a directory your web server user (e.g. www-data) can write to
// that is not inside public_html / httpdocs.
//
// Example paths:
//   define('MAGHREB_DB_PATH', '/home/jamie/data/maghreb.db');
//   define('MAGHREB_DB_PATH', '/var/data/maghreb/maghreb.db');
//
// Once set, SSH into your server, create the directory, and move your
// existing database there:
//   mkdir -p /home/jamie/data
//   mv /path/to/webapi.jamie-datson.com/data/maghreb.db /home/jamie/data/maghreb.db
//
// If this is not set, the database falls back to data/maghreb.db inside
// the project folder — which WILL be overwritten on each deployment.
//
define('MAGHREB_DB_PATH', '/var/www/vhosts/jamie-datson.co.uk/data/maghreb.db');
