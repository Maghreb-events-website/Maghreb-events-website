import discord
from discord import app_commands
from discord.ext import commands

from utils.constants import GUILD_ID, EVENT_TEST_CHANNEL_ID, EVENT_CHANNEL_ID, EVENT_ANNOUNCEMENTS_ROLE_ID


def register(bot: commands.Bot):

    @bot.tree.command(name="test_ann", description="Send a test announcement", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin", "Vatsim Marketing")
    @app_commands.describe(
        title="The title of the announcement",
        description="The 1st paragraph of the announcement",
        para2="The 2nd paragraph (optional)",
        para3="The 3rd paragraph (optional)",
        para4="The 4th paragraph (optional)",
        image_url="URL of an image to attach, or leave blank for none",
        location="Where to send the announcement"
    )
    @app_commands.choices(location=[
        app_commands.Choice(name="Event Channel",        value="event"),
        app_commands.Choice(name="Announcement Channel", value="announcement"),
    ])
    async def test_ann(
        interaction: discord.Interaction,
        title: str,
        description: str,
        location: app_commands.Choice[str],
        para2: str = None,
        para3: str = None,
        para4: str = None,
        image_url: str = None,
    ):
        if location.value == "event":
            channel = bot.get_channel(EVENT_TEST_CHANNEL_ID)
            embed   = discord.Embed(title=title, description=description, color=0xAD42F5)
            if para2: embed.add_field(name="", value=para2, inline=False)
            if para3: embed.add_field(name="", value=para3, inline=False)
            if para4: embed.add_field(name="", value=para4, inline=False)
            if image_url: embed.set_image(url=image_url)
            try:
                await channel.send(f"||<@&{EVENT_ANNOUNCEMENTS_ROLE_ID}>||")
                await channel.send(embed=embed)
                await interaction.response.send_message("Test announcement sent!", ephemeral=True)
            except Exception as e:
                await interaction.response.send_message(f"Error: {e}", ephemeral=True)
        else:
            await interaction.response.send_message(
                "This channel is currently unavailable. Please use the event channel instead.",
                ephemeral=True
            )

    @bot.tree.command(name="announcement", description="Send an announcement", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin", "Vatsim Marketing")
    @app_commands.describe(
        title="The title of the announcement",
        description="The 1st paragraph of the announcement",
        para2="The 2nd paragraph (optional)",
        para3="The 3rd paragraph (optional)",
        para4="The 4th paragraph (optional)",
        image_url="URL of an image to attach, or leave blank for none",
        location="Where to send the announcement"
    )
    @app_commands.choices(location=[
        app_commands.Choice(name="Event Channel",        value="event"),
        app_commands.Choice(name="Announcement Channel", value="announcement"),
    ])
    async def announcement(
        interaction: discord.Interaction,
        title: str,
        description: str,
        location: app_commands.Choice[str],
        para2: str = None,
        para3: str = None,
        para4: str = None,
        image_url: str = None,
    ):
        if location.value == "event":
            channel = bot.get_channel(EVENT_CHANNEL_ID)
            embed   = discord.Embed(title=title, description=description, color=0xAD42F5)
            if para2: embed.add_field(name="", value=para2, inline=False)
            if para3: embed.add_field(name="", value=para3, inline=False)
            if para4: embed.add_field(name="", value=para4, inline=False)
            if image_url: embed.set_image(url=image_url)
            try:
                await channel.send(f"||<@&{EVENT_ANNOUNCEMENTS_ROLE_ID}>||")
                await channel.send(embed=embed)
                await interaction.response.send_message("Announcement sent!", ephemeral=True)
            except Exception as e:
                await interaction.response.send_message(f"Error: {e}", ephemeral=True)
        else:
            await interaction.response.send_message(
                "This channel is currently unavailable. Please use the event channel instead.",
                ephemeral=True
            )