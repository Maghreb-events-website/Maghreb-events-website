<?php

class Database {
    private static ?PDO $instance = null;

    /**
     * Resolve the database path.
     *
     * Priority:
     *  1. MAGHREB_DB_PATH environment variable — set this in your server config
     *     (e.g. Apache VirtualHost: SetEnv MAGHREB_DB_PATH /var/data/maghreb/maghreb.db)
     *     This keeps the DB outside the web root so it survives deployments.
     *  2. Falls back to data/maghreb.db inside the project (legacy / local dev).
     */
    private static function getDbPath(): string {
        // 1. Constant defined in vatsim_config.php (recommended)
        if (defined('MAGHREB_DB_PATH') && trim(MAGHREB_DB_PATH) !== '') {
            return trim(MAGHREB_DB_PATH);
        }
        // 2. Server environment variable (Apache SetEnv / .env)
        $envPath = getenv('MAGHREB_DB_PATH');
        if ($envPath && trim($envPath) !== '') {
            return trim($envPath);
        }
        // 3. Fallback — inside project (will be overwritten on deployment!)
        //    Log a prominent warning so this is never silently used in production.
        $fallback = dirname(__DIR__) . '/data/maghreb.db';
        error_log(
            '[MAGHREB WARNING] Database is using the fallback path inside the ' .
            'deployment directory (' . $fallback . '). ' .
            'This file WILL BE DELETED on the next deployment. ' .
            'Set MAGHREB_DB_PATH in vatsim_config.php to a persistent path outside the web root.'
        );
        return $fallback;
    }

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dbPath = self::getDbPath();
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON;');
            self::$instance->exec('PRAGMA journal_mode = WAL;');

            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                cid         TEXT    NOT NULL UNIQUE,
                name        TEXT    NOT NULL,
                email       TEXT    NOT NULL UNIQUE,
                password    TEXT    NOT NULL,
                role        TEXT    NOT NULL DEFAULT 'admin',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS events (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                description  TEXT,
                start_time   TEXT    NOT NULL,
                end_time     TEXT    NOT NULL,
                status       TEXT    NOT NULL DEFAULT 'upcoming',
                banner_url   TEXT,
                created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS partners (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL,
                type         TEXT    NOT NULL,
                status       TEXT    NOT NULL DEFAULT 'pending',
                website      TEXT,
                logo_url     TEXT,
                description  TEXT,
                contact_email TEXT,
                created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS airlines (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL,
                icao         TEXT    NOT NULL UNIQUE,
                callsign     TEXT,
                status       TEXT    NOT NULL DEFAULT 'observer',
                logo_url     TEXT,
                description  TEXT,
                pilot_count  INTEGER NOT NULL DEFAULT 0,
                created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id          TEXT    PRIMARY KEY,
                admin_id    INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
                expires_at  TEXT    NOT NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id    INTEGER REFERENCES admins(id),
                action      TEXT    NOT NULL,
                entity_type TEXT,
                entity_id   INTEGER,
                detail      TEXT,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS planning_team (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                role        TEXT,
                cid         TEXT,
                photo_url   TEXT,
                bio         TEXT,
                subsection  TEXT    NOT NULL DEFAULT 'other',
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS hitsquad (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                role        TEXT,
                cid         TEXT,
                photo_url   TEXT,
                bio         TEXT,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS pilot_briefs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                title       TEXT,
                file_path   TEXT    NOT NULL,
                uploaded_by INTEGER REFERENCES admins(id),
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            );
        ");

        // Separate migration for allowed_cids — isolated so it doesn't affect
        // the existing tables exec block on servers with strict multi-statement PDO
        $db->exec("
            CREATE TABLE IF NOT EXISTS allowed_cids (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                cid        TEXT    NOT NULL UNIQUE,
                note       TEXT,
                added_by   INTEGER REFERENCES admins(id),
                created_at TEXT    NOT NULL DEFAULT (datetime('now'))
            );
        ");

        // App-wide settings (key/value store)
        $db->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                updated_by INTEGER REFERENCES admins(id),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        // Test-mode override data (mirrors production tables, isolated)
        $db->exec("
            CREATE TABLE IF NOT EXISTS test_events (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                title      TEXT NOT NULL,
                description TEXT,
                start_time TEXT,
                end_time   TEXT,
                status     TEXT DEFAULT 'upcoming',
                banner_url TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS test_partners (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT NOT NULL,
                type          TEXT NOT NULL,
                status        TEXT DEFAULT 'pending',
                website       TEXT,
                logo_url      TEXT,
                description   TEXT,
                contact_email TEXT,
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS test_airlines (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                icao        TEXT NOT NULL,
                callsign    TEXT,
                status      TEXT DEFAULT 'active',
                logo_url    TEXT,
                pilot_count INTEGER DEFAULT 0,
                description TEXT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS test_planning (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                role       TEXT,
                cid        TEXT,
                photo_url  TEXT,
                bio        TEXT,
                subsection TEXT NOT NULL DEFAULT 'other',
                sort_order INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS test_hitsquad (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                role       TEXT,
                cid        TEXT,
                photo_url  TEXT,
                bio        TEXT,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        // Seed default settings
        $db->exec("INSERT OR IGNORE INTO app_settings (key, value) VALUES ('test_mode', '0')");

        // Migration: add subsection column to planning_team for older deployments.
        // SQLite ignores 'IF NOT EXISTS' on ADD COLUMN, so we check pragma first.
        try {
            $cols = $db->query("PRAGMA table_info(planning_team)")->fetchAll();
            $hasSubsection = false;
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === 'subsection') { $hasSubsection = true; break; }
            }
            if (!$hasSubsection && count($cols) > 0) {
                $db->exec("ALTER TABLE planning_team ADD COLUMN subsection TEXT NOT NULL DEFAULT 'other'");
            }
        } catch (\Throwable $e) {
            error_log('[MAGHREB] Failed to migrate planning_team.subsection: ' . $e->getMessage());
        }

        // Seed default allowed CIDs
        try {
            // Always upsert the bootstrap list so existing deployments that
            // already have rows still receive missing defaults.
            $defaultCids = ['10000000','10000001','10000002','10000003','10000004',
                            '10000005','10000006','10000007','10000008','10000009',
                            '1635257','1797446'];
            $ins = $db->prepare("INSERT OR IGNORE INTO allowed_cids (cid, note) VALUES (?, 'Default')");
            foreach ($defaultCids as $c) { $ins->execute([$c]); }
        } catch (\Throwable $e) {
            // Table may not exist yet on first deploy — will be created above
        }

        // Seed default admin if none exists
        $count = $db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count == 0) {
            $hash = password_hash('admin1234', PASSWORD_BCRYPT);
            $db->prepare("
                INSERT INTO admins (cid, name, email, password, role)
                VALUES (?, ?, ?, ?, ?)
            ")->execute(['10000004', 'Admin User', 'admin@maghrebevents.com', $hash, 'superadmin']);
        }

        // Seed demo data
        $eventCount = $db->query("SELECT COUNT(*) FROM events")->fetchColumn();
        if ($eventCount == 0) {
            $db->exec("
                INSERT INTO events (title, description, start_time, end_time, status)
                VALUES
                  ('New Year''s in Morocco', 'Celebrate the new year with a flight to Morocco', '2025-01-01 06:00:00', '2025-01-01 10:00:00', 'upcoming'),
                  ('Cross Algeria', 'Transit flight across the Algerian airspace', '2025-01-13 14:00:00', '2025-01-13 19:00:00', 'upcoming');

                INSERT INTO partners (name, type, status, description)
                VALUES
                  ('VATSIM MENA', 'Regional Division', 'verified', 'The VATSIM Middle East & North Africa regional division.'),
                  ('Maghreb vACC', 'Air Traffic Control', 'verified', 'Virtual Area Control Centre for the Maghreb region.');

                INSERT INTO airlines (name, icao, callsign, status, description, pilot_count)
                VALUES
                  ('Royal Air Maroc Virtual', 'RAM-V', 'MAROCAIR', 'active', 'The flag carrier of Morocco virtual sky.', 340),
                  ('Air Algérie Virtual', 'DAH-V', 'TASSILI', 'active', 'Connecting Algeria to the world.', 280),
                  ('Tunisair Virtual', 'TAR-V', 'TUNAIR', 'active', 'Experience the hospitality of Tunisia.', 220),
                  ('Nouvelair Virtual', 'BJ-V', 'NOUVELAIR', 'observer', 'Tunisian private airline for European leisure routes.', 180),
                  ('Maghreb Cargo VA', 'MCG-V', 'MAGHREB', 'active', 'Dedicated heavy logistics virtual airline.', 120);
            ");
        }
    }
}
