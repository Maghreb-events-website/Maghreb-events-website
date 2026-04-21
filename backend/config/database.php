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

        // Giveaways
        $db->exec("
            CREATE TABLE IF NOT EXISTS giveaways (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                title         TEXT    NOT NULL,
                description   TEXT,
                prizes        TEXT    NOT NULL DEFAULT '[]',
                eligible_cids TEXT    NOT NULL DEFAULT '[]',
                status        TEXT    NOT NULL DEFAULT 'upcoming',
                winner_cid    TEXT,
                winner_name   TEXT,
                winner_prize  TEXT,
                created_by    INTEGER REFERENCES admins(id),
                created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            );
            CREATE TABLE IF NOT EXISTS giveaway_entries (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                giveaway_id INTEGER NOT NULL REFERENCES giveaways(id) ON DELETE CASCADE,
                cid         TEXT    NOT NULL,
                name        TEXT,
                entered_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(giveaway_id, cid)
            );
        ");

        // ── Bootstrap allowed CIDs ────────────────────────────────────────────
        $bootstrapCids = [
            ['1635257',  'Project owner'],
            ['1797446',  'Project owner'],
            ['10000000', 'VATSIM sandbox'],
            ['10000001', 'VATSIM sandbox'],
            ['10000002', 'VATSIM sandbox'],
            ['10000003', 'VATSIM sandbox'],
            ['10000004', 'VATSIM sandbox'],
            ['10000005', 'VATSIM sandbox'],
            ['10000006', 'VATSIM sandbox'],
            ['10000007', 'VATSIM sandbox'],
            ['10000008', 'VATSIM sandbox'],
            ['10000009', 'VATSIM sandbox'],
        ];
        $seedCid = $db->prepare("INSERT OR IGNORE INTO allowed_cids (cid, note) VALUES (?, ?)");
        foreach ($bootstrapCids as [$cid, $note]) {
            $seedCid->execute([$cid, $note]);
        }

        // ── Seed all allowed CIDs into admins table ──────────────────────────────
        // Placeholder rows updated on first real VATSIM login.
        // Random unusable password — login is VATSIM OAuth only.
        $allAdminCids = [
            ['1635257',  'Owner (1635257)',          'admin'],
            ['1797446',  'Owner (1797446)',          'admin'],
            ['10000000', 'Sandbox Admin (10000000)', 'admin'],
            ['10000001', 'Sandbox Admin (10000001)', 'admin'],
            ['10000002', 'Sandbox Admin (10000002)', 'admin'],
            ['10000003', 'Sandbox Admin (10000003)', 'admin'],
            ['10000004', 'Sandbox Admin (10000004)', 'admin'],
            ['10000005', 'Sandbox Admin (10000005)', 'admin'],
            ['10000006', 'Sandbox Admin (10000006)', 'admin'],
            ['10000007', 'Sandbox Admin (10000007)', 'admin'],
            ['10000008', 'Sandbox Admin (10000008)', 'admin'],
            ['10000009', 'Sandbox Admin (10000009)', 'admin'],
        ];
        $randomPw = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $seedAdmin = $db->prepare("
            INSERT OR IGNORE INTO admins (cid, name, email, password, role)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($allAdminCids as [$cid, $name, $role]) {
            $seedAdmin->execute([$cid, $name, "cid{$cid}@vatsim.placeholder", $randomPw, $role]);
        }

        // Upgrade any existing rows stuck at 'user' role to 'admin'
        $allCids = "'1635257','1797446','10000000','10000001','10000002','10000003','10000004','10000005','10000006','10000007','10000008','10000009'";
        $db->exec("UPDATE admins SET role = 'admin' WHERE cid IN ($allCids) AND role = 'user'");

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
