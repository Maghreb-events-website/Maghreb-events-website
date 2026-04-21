"""
http_bridge.py
==============
A lightweight local HTTP server that lets the PHP backend send Discord
announcements through the bot itself — not a webhook.

It binds to 127.0.0.1:5001 (localhost only; never exposed to the internet).
The PHP backend posts JSON here; this file builds the Discord embed and sends
it through the already-connected bot client.

Required .env variable:
    BOT_BRIDGE_TOKEN=some_long_random_secret

Required in vatsim_config.php (or as server env vars):
    define('BOT_BRIDGE_TOKEN', 'some_long_random_secret');
    define('BOT_BRIDGE_URL',   'http://127.0.0.1:5001/send');

POST /send
Headers: X-Bridge-Token: <BOT_BRIDGE_TOKEN>
Body JSON:
{
  "channel":     "event" | "announcement",
  "title":       "...",
  "description": "...",
  "para2":       "..."   (optional),
  "para3":       "..."   (optional),
  "para4":       "..."   (optional),
  "image_url":   "..."   (optional)
}
"""

import asyncio
import os

import discord
from aiohttp import web

BRIDGE_TOKEN = os.getenv("BOT_BRIDGE_TOKEN", "change_me_in_env")
BRIDGE_HOST  = os.getenv("BOT_BRIDGE_HOST", "127.0.0.1")
BRIDGE_PORT  = int(os.getenv("BOT_BRIDGE_PORT", "5001"))


def create_bridge(bot: discord.ext.commands.Bot) -> web.Application:
    """Build and return the aiohttp Application, with the bot injected."""

    app = web.Application()

    async def handle_send(request: web.Request) -> web.Response:

        # ── Authenticate ────────────────────────────────────────────────────
        if request.headers.get("X-Bridge-Token", "") != BRIDGE_TOKEN:
            return web.json_response({"error": "Unauthorized"}, status=401)

        # ── Parse body ──────────────────────────────────────────────────────
        try:
            body = await request.json()
        except Exception:
            return web.json_response({"error": "Invalid JSON body"}, status=400)

        channel_key = (body.get("channel") or "").strip()
        title       = (body.get("title")   or "").strip()
        description = (body.get("description") or "").strip()
        para2       = (body.get("para2")    or "").strip()
        para3       = (body.get("para3")    or "").strip()
        para4       = (body.get("para4")    or "").strip()
        image_url   = (body.get("image_url") or "").strip()

        if not title or not description:
            return web.json_response(
                {"error": "title and description are required"}, status=422
            )
        if channel_key not in ("event", "announcement"):
            return web.json_response(
                {"error": "channel must be 'event' or 'announcement'"}, status=422
            )

        # ── Resolve target Discord channel ──────────────────────────────────
        from utils.constants import (
            EVENT_CHANNEL_ID,
            EVENT_TEST_CHANNEL_ID,
            EVENT_ANNOUNCEMENTS_ROLE_ID,
        )

        if channel_key == "event":
            channel_id = EVENT_CHANNEL_ID
        else:
            # Use ANNOUNCEMENT_CHANNEL_ID from .env if set, otherwise fall back
            # to the test channel so nothing breaks until you set it.
            channel_id = int(
                os.getenv("ANNOUNCEMENT_CHANNEL_ID", str(EVENT_TEST_CHANNEL_ID))
            )

        channel = bot.get_channel(channel_id)
        if channel is None:
            return web.json_response(
                {
                    "error": (
                        f"Bot cannot find channel {channel_id}. "
                        "Make sure the bot is in the server and has cached that channel."
                    )
                },
                status=503,
            )

        # ── Build Discord embed ─────────────────────────────────────────────
        embed = discord.Embed(
            title=title,
            description=description,
            color=0xAD42F5,
        )
        if para2:     embed.add_field(name="", value=para2, inline=False)
        if para3:     embed.add_field(name="", value=para3, inline=False)
        if para4:     embed.add_field(name="", value=para4, inline=False)
        if image_url: embed.set_image(url=image_url)

        # ── Send via bot ────────────────────────────────────────────────────
        try:
            await channel.send(f"||<@&{EVENT_ANNOUNCEMENTS_ROLE_ID}>||")
            await channel.send(embed=embed)
        except discord.Forbidden:
            return web.json_response(
                {"error": "Bot lacks permission to send messages in that channel"},
                status=403,
            )
        except Exception as exc:
            return web.json_response({"error": str(exc)}, status=500)

        return web.json_response({"ok": True, "message": "Announcement sent via bot"})

    app.router.add_post("/send", handle_send)
    return app


async def start_bridge(
    bot: discord.ext.commands.Bot,
    host: str = BRIDGE_HOST,
    port: int = BRIDGE_PORT,
) -> None:
    """Start the aiohttp runner in the background. Call from on_ready."""
    app    = create_bridge(bot)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host, port)
    await site.start()
    print(f"[bridge] HTTP bridge listening on http://{host}:{port}/send")
