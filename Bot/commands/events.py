import discord
from discord import app_commands
from discord.ext import commands
from datetime import datetime, timezone
import urllib.request

from utils.constants import GUILD_ID


def register(bot: commands.Bot):

    @bot.tree.command(name="add_event", description="Create a scheduled event in the server", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin", "Vatsim Marketing")
    @app_commands.describe(
        title="The title of the event",
        description="The description of the event",
        location="Where the event will take place",
        start_time="Event start time in format: YYYY-MM-DD HH:MM (UTC)",
        end_time="Event end time in format: YYYY-MM-DD HH:MM (UTC)",
        banner_url="URL of the banner image for the event (optional)",
    )
    async def add_event(
        interaction: discord.Interaction,
        title: str,
        description: str,
        location: str,
        start_time: str,
        end_time: str,
        banner_url: str = None,
    ):
        print(f"add_event called by {interaction.user}")
        await interaction.response.defer(ephemeral=True)

        # Parse start and end times
        try:
            start_dt = datetime.strptime(start_time, "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc)
            end_dt   = datetime.strptime(end_time,   "%Y-%m-%d %H:%M").replace(tzinfo=timezone.utc)
        except ValueError:
            await interaction.followup.send(
                "❌ Invalid date format. Please use `YYYY-MM-DD HH:MM` (e.g. `2025-12-31 18:00`).",
                ephemeral=True
            )
            return

        if end_dt <= start_dt:
            await interaction.followup.send("❌ End time must be after start time.", ephemeral=True)
            return

        # Optionally download the banner image
        image_bytes = None
        if banner_url:
            try:
                with urllib.request.urlopen(banner_url, timeout=10) as resp:
                    image_bytes = resp.read()
            except Exception as e:
                await interaction.followup.send(
                    f"❌ Failed to download banner image: {e}\nPlease check the URL and try again.",
                    ephemeral=True
                )
                return

        # Build kwargs — only pass image if we actually have bytes
        event_kwargs = dict(
            name=title,
            description=description,
            location=location,
            start_time=start_dt,
            end_time=end_dt,
            entity_type=discord.EntityType.external,
            privacy_level=discord.PrivacyLevel.guild_only,
        )
        if image_bytes is not None:
            event_kwargs["image"] = image_bytes

        # Create the scheduled event
        try:
            event = await interaction.guild.create_scheduled_event(**event_kwargs)

            embed = discord.Embed(title="✅ Event Created", color=0xAD42F5)
            embed.add_field(name="Title",       value=event.name,        inline=False)
            embed.add_field(name="Description", value=event.description, inline=False)
            embed.add_field(name="Location",    value=location,          inline=True)
            embed.add_field(name="Start (UTC)", value=f"<t:{int(start_dt.timestamp())}:F>", inline=True)
            embed.add_field(name="End (UTC)",   value=f"<t:{int(end_dt.timestamp())}:F>",   inline=True)
            embed.add_field(
                name="Event Link",
                value=f"https://discord.com/events/{interaction.guild.id}/{event.id}",
                inline=False
            )
            if banner_url:
                embed.set_image(url=banner_url)

            await interaction.followup.send(embed=embed, ephemeral=True)

        except discord.Forbidden:
            await interaction.followup.send(
                "❌ I don't have permission to create events. "
                "Please make sure the bot has the **Manage Events** permission.",
                ephemeral=True
            )
        except discord.HTTPException as e:
            await interaction.followup.send(f"❌ Failed to create event: {e}", ephemeral=True)