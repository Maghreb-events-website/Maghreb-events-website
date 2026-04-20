#!/usr/bin/env bash
# =============================================================================
#  deploy.sh — Safe deployment helper for Maghreb Events backend
#
#  What this does
#  ──────────────
#  1. Reads MAGHREB_DB_PATH from vatsim_config.php (or the env var).
#  2. If the DB file currently lives inside data/ (the dangerous default),
#     it moves it to the configured persistent path automatically.
#  3. Verifies the persistent path is writable by the web server.
#  4. Rsync's the new code into place WITHOUT touching the data/ directory.
#
#  Usage
#  ─────
#  bash deploy.sh [SOURCE_DIR] [DEST_DIR]
#
#  Example (from your local machine with rsync over SSH):
#    bash deploy.sh ./backend-out/ user@server:/var/www/webapi.example.com/
#
#  Or run it directly on the server after uploading files:
#    bash deploy.sh /tmp/backend-out/ /var/www/webapi.example.com/
# =============================================================================

set -euo pipefail

SOURCE="${1:-./backend-out}"
DEST="${2:-/var/www/webapi}"

# ── 1. Read the configured DB path ───────────────────────────────────────────
DEST_CONFIG="$DEST/vatsim_config.php"

if [[ ! -f "$DEST_CONFIG" ]]; then
    echo "ℹ  vatsim_config.php not found at destination — fresh install assumed."
    CONFIGURED_PATH=""
else
    CONFIGURED_PATH=$(php -r "
        require '$DEST_CONFIG';
        echo defined('MAGHREB_DB_PATH') ? MAGHREB_DB_PATH : '';
    " 2>/dev/null || true)
fi

FALLBACK_DB="$DEST/data/maghreb.db"

# ── 2. Auto-migrate if DB is still in data/ ──────────────────────────────────
if [[ -n "$CONFIGURED_PATH" && "$CONFIGURED_PATH" != "$FALLBACK_DB" ]]; then
    PERSISTENT_DIR=$(dirname "$CONFIGURED_PATH")

    if [[ -f "$FALLBACK_DB" && ! -f "$CONFIGURED_PATH" ]]; then
        echo "⚠  Database found inside deployment folder — moving to persistent path…"
        mkdir -p "$PERSISTENT_DIR"
        mv "$FALLBACK_DB" "$CONFIGURED_PATH"
        echo "✅  Moved: $FALLBACK_DB → $CONFIGURED_PATH"
    fi

    # Verify writable
    if [[ -f "$CONFIGURED_PATH" ]]; then
        if [[ ! -w "$CONFIGURED_PATH" ]]; then
            echo "❌  ERROR: $CONFIGURED_PATH exists but is not writable."
            echo "    Fix: chown www-data:www-data \"$CONFIGURED_PATH\""
            exit 1
        fi
        echo "✅  Persistent database OK: $CONFIGURED_PATH"
    else
        echo "ℹ  No existing database at $CONFIGURED_PATH — will be created on first request."
        mkdir -p "$PERSISTENT_DIR"
    fi
else
    if [[ -z "$CONFIGURED_PATH" ]]; then
        echo "⚠  WARNING: MAGHREB_DB_PATH is not set in vatsim_config.php."
        echo "   The database will be stored inside the deployment folder"
        echo "   and WILL BE LOST on the next deployment."
        echo "   Edit vatsim_config.php and set MAGHREB_DB_PATH to a persistent path."
    fi
fi

# ── 3. Rsync new code, excluding the data/ directory ─────────────────────────
echo ""
echo "📦  Syncing code to $DEST …"
rsync -av --checksum \
    --exclude='data/maghreb.db' \
    --exclude='data/db.sqlite' \
    --exclude='data/*.db' \
    --exclude='data/*.sqlite' \
    "$SOURCE/" "$DEST/"

echo ""
echo "🚀  Deployment complete."

if [[ -z "$CONFIGURED_PATH" ]]; then
    echo ""
    echo "─────────────────────────────────────────────────────────────────────"
    echo "  ACTION REQUIRED"
    echo "  Edit $DEST/vatsim_config.php and set:"
    echo "    define('MAGHREB_DB_PATH', '/home/youruser/data/maghreb.db');"
    echo "─────────────────────────────────────────────────────────────────────"
fi
