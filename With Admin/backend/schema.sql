
CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cid TEXT UNIQUE,
  name TEXT NOT NULL,
  email TEXT,
  password TEXT,
  role TEXT DEFAULT 'admin' CHECK(role IN ('admin','superadmin')),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS allowed_cids (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cid TEXT UNIQUE NOT NULL,
  note TEXT,
  added_by INTEGER,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_id INTEGER,
  target_type TEXT,
  target_id INTEGER,
  action TEXT,
  details TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS partners (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL,
  status TEXT DEFAULT 'pending',
  website TEXT,
  logo_url TEXT,
  description TEXT,
  contact_email TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS airlines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  icao TEXT NOT NULL,
  callsign TEXT,
  status TEXT DEFAULT 'active',
  logo_url TEXT,
  pilot_count INTEGER DEFAULT 0,
  description TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS planning (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  role TEXT,
  cid TEXT,
  photo_url TEXT,
  bio TEXT,
  subsection TEXT NOT NULL DEFAULT 'other',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS hitsquad (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  role TEXT,
  cid TEXT,
  photo_url TEXT,
  bio TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT,
  start_time TEXT,
  end_time TEXT,
  status TEXT DEFAULT 'upcoming',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS briefings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT,
  pdf_path TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- SEED TEST DATA
INSERT OR IGNORE INTO allowed_cids (cid, note) VALUES ('10000004', 'Project owner - superadmin');
INSERT OR IGNORE INTO admins (cid, name, email, password, role) VALUES ('10000004', 'Admin User', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin');

INSERT OR IGNORE INTO partners (name, type, status, logo_url, website) VALUES 
  ('RAM Virtual', 'VA', 'verified', 'https://example.com/ram-logo.png', 'https://ramvirtual.org'),
  ('Maghreb vACC', 'ATC', 'verified', 'assets/img/Maghreb_vACC_Full_white_trans_1.png', 'https://maghrebvacci.org');

INSERT OR IGNORE INTO airlines (name, icao, callsign, status, pilot_count) VALUES 
  ('Royal Air Maroc Virtual', 'RAM-V', 'ROYAL', 'active', 45);

INSERT OR IGNORE INTO planning (name, role, cid, photo_url) VALUES 
  ('John Doe', 'Event Director', '1234567', 'https://example.com/photo.jpg');
