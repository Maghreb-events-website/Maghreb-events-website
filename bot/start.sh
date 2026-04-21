#!/bin/bash
# ── Maghreb Discord Bot — startup script ─────────────────────────────────────
# Run this on the server to keep the bot alive.
# Usage:  bash start.sh
# With nohup (background, persists after logout):
#         nohup bash start.sh &> bot.log &

cd "$(dirname "$0")"

# Install deps if needed
if ! python3 -c "import discord" 2>/dev/null; then
    echo "[start] Installing dependencies..."
    pip3 install -r requirements.txt --break-system-packages 2>/dev/null || \
    pip3 install discord.py aiohttp python-dotenv --break-system-packages
fi

echo "[start] Starting bot (restart on crash, 5s delay)..."
while true; do
    python3 main.py
    echo "[start] Bot exited — restarting in 5 seconds..."
    sleep 5
done
