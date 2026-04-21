"""
http_bridge.py
==============
A lightweight local HTTP server that lets the PHP backend send Discord
announcements and create scheduled events through the bot.

Binds to 127.0.0.1:5001 (localhost only).

Endpoints:
  GET  /health          — liveness check
  POST /send            — send an announcement embed to a channel
  POST /create-event    — create a Discord scheduled event in the guild
"""

import asyncio
import os
import urllib.request

import discord
from aiohttp import web

BRIDGE_TOKEN = os.getenv("BOT_BRIDGE_TOKEN", "change_me_in_env")
BRIDGE_HOST  = os.getenv("BOT_BRIDGE_HOST", "127.0.0.1")
BRIDGE_PORT  = int(os.getenv("BOT_BRIDGE_PORT", "5001"))


def create_bridge(bot: discord.ext.commands.Bot) -> web.Application:
    app = web.Application()

    # ── Auth helper ────────────────────────────────────────────────────────
    def auth_ok(request: web.Request) -> bool:
        return request.headers.get("X-Bridge-Token", "") == BRIDGE_TOKEN

    # ── GET /health ────────────────────────────────────────────────────────
    async def handle_health(request: web.Request) -> web.Response:
        return web.json_response({
            "ok": True,
            "bot": str(bot.user) if bot.user else "not_ready",
        })

    # ── POST /send ─────────────────────────────────────────────────────────
    async def handle_send(request: web.Request) -> web.Response:
        if not auth_ok(request):
            return web.json_response({"error": "Unauthorized"}, status=401)

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
            return web.json_response({"error": "title and description are required"}, status=422)
        if channel_key not in ("event", "announcement"):
            return web.json_response({"error": "channel must be 'event' or 'announcement'"}, status=422)

        from utils.constants import (
            EVENT_CHANNEL_ID,
            EVENT_TEST_CHANNEL_ID,
            EVENT_ANNOUNCEMENTS_ROLE_ID,
        )

        if channel_key == "event":
            channel_id = EVENT_CHANNEL_ID
        else:
            channel_id = int(os.getenv("ANNOUNCEMENT_CHANNEL_ID", str(EVENT_TEST_CHANNEL_ID)))

        channel = bot.get_channel(channel_id)
        if channel is None:
            try:
                channel = await bot.fetch_channel(channel_id)
            except Exception:
                pass

        if channel is None:
            return web.json_response(
                {"error": f"Bot cannot find channel {channel_id}. Make sure the bot is in the server and has permission to see that channel."},
                status=503,
            )

        embed = discord.Embed(title=title, description=description, color=0xAD42F5)
        if para2:     embed.add_field(name="\u200b", value=para2, inline=False)
        if para3:     embed.add_field(name="\u200b", value=para3, inline=False)
        if para4:     embed.add_field(name="\u200b", value=para4, inline=False)
        if image_url: embed.set_image(url=image_url)

        try:
            if channel_key == "event":
                await channel.send(f"||<@&{EVENT_ANNOUNCEMENTS_ROLE_ID}>||")
            await channel.send(embed=embed)
        except discord.Forbidden:
            return web.json_response({"error": "Bot lacks permission to send messages in that channel"}, status=403)
        except Exception as exc:
            return web.json_response({"error": str(exc)}, status=500)

        return web.json_response({"ok": True, "message": "Announcement sent via bot"})

    # ── POST /create-event ─────────────────────────────────────────────────
    async def handle_create_event(request: web.Request) -> web.Response:
        if not auth_ok(request):
            return web.json_response({"error": "Unauthorized"}, status=401)

        try:
            body = await request.json()
        except Exception:
            return web.json_response({"error": "Invalid JSON body"}, status=400)

        title       = (body.get("title")       or "").strip()
        description = (body.get("description") or "").strip()
        location    = (body.get("location")    or "Online — VATSIM").strip()
        start_time  = (body.get("start_time")  or "").strip()
        end_time    = (body.get("end_time")    or "").strip()
        banner_url  = (body.get("banner_url")  or "").strip()

        if not title or not start_time or not end_time:
            return web.json_response({"error": "title, start_time and end_time are required"}, status=422)

        from datetime import datetime, timezone
        try:
            # Accept both "YYYY-MM-DD HH:MM:SS" and "YYYY-MM-DD HH:MM"
            for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%dT%H:%M"):
                try:
                    start_dt = datetime.strptime(start_time, fmt).replace(tzinfo=timezone.utc)
                    end_dt   = datetime.strptime(end_time,   fmt).replace(tzinfo=timezone.utc)
                    break
                except ValueError:
                    continue
            else:
                raise ValueError("No format matched")
        except ValueError:
            return web.json_response({"error": f"Invalid date format: '{start_time}'. Use YYYY-MM-DD HH:MM:SS"}, status=422)

        if end_dt <= start_dt:
            return web.json_response({"error": "end_time must be after start_time"}, status=422)

        from datetime import datetime, timezone as tz
        if start_dt <= datetime.now(tz=tz.utc):
            return web.json_response({"error": "start_time must be in the future — Discord does not allow creating scheduled events with a past start time"}, status=422)

        from utils.constants import GUILD_ID
        guild = bot.get_guild(GUILD_ID.id)
        if guild is None:
            return web.json_response({"error": "Bot cannot find the guild"}, status=503)

        # Download banner image if provided
        image_bytes = None
        if banner_url:
            try:
                with urllib.request.urlopen(banner_url, timeout=10) as resp:
                    image_bytes = resp.read()
            except Exception as e:
                # Non-fatal — create event without image
                print(f"[bridge] Warning: could not download banner {banner_url}: {e}")

        event_kwargs = dict(
            name=title,
            description=description or "Maghreb vACC Event",
            location=location,
            start_time=start_dt,
            end_time=end_dt,
            entity_type=discord.EntityType.external,
            privacy_level=discord.PrivacyLevel.guild_only,
        )
        if image_bytes is not None:
            event_kwargs["image"] = image_bytes

        try:
            event = await guild.create_scheduled_event(**event_kwargs)
        except discord.Forbidden:
            return web.json_response({"error": "Bot lacks Manage Events permission in the server"}, status=403)
        except discord.HTTPException as e:
            return web.json_response({"error": f"Discord API error {e.status}: {e.text}"}, status=500)

        event_url = f"https://discord.com/events/{guild.id}/{event.id}"
        return web.json_response({
            "ok": True,
            "message": "Discord scheduled event created",
            "event_id": str(event.id),
            "event_url": event_url,
        })

    app.router.add_get("/health",       handle_health)
    app.router.add_post("/send",        handle_send)
    app.router.add_post("/create-event", handle_create_event)
    return app


_bridge_runner: web.AppRunner | None = None


async def start_bridge(
    bot: discord.ext.commands.Bot,
    host: str = BRIDGE_HOST,
    port: int = BRIDGE_PORT,
) -> None:
    global _bridge_runner

    # Guard against on_ready firing multiple times (reconnects) re-binding the port.
    if _bridge_runner is not None:
        print("[bridge] Bridge already running — skipping start.")
        return

    app    = create_bridge(bot)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host, port)

    try:
        await site.start()
    except OSError as exc:
        await runner.cleanup()
        print(f"[bridge] WARNING: Could not bind on {host}:{port} — {exc}. "
              "Another process may already be using that port. "
              "The bot will continue without the HTTP bridge.")
        return

    _bridge_runner = runner
    print(f"[bridge] HTTP bridge listening on http://{host}:{port}/send")
    print(f"[bridge] Health check: http://{host}:{port}/health")
    print(f"[bridge] Create event: http://{host}:{port}/create-event")