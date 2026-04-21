from dotenv import load_dotenv
import os
import discord
from discord.ext import commands
from discord import app_commands

from utils.constants import GUILD_ID
from utils.helpers import get_log_channel
from ui.ticket_views import StaffRoleView, CloseTicketView, SupportTicketView
from commands.announcements import register as register_announcements
from commands.admin_setup import register as register_admin_setup
from commands.tickets import register as register_tickets
from commands.events import register as register_events
from events.reactions import register as register_reactions
from events.audit import register as register_audit
from events.role_changes import register as register_role_changes
from http_bridge import start_bridge

load_dotenv()
token = os.getenv('DISCORD_TOKEN')
if not token:
    raise ValueError("DISCORD_TOKEN not found in .env")

intents = discord.Intents.default()
intents.members = True
intents.message_content = True
intents.guilds = True

bot = commands.Bot(command_prefix='!', intents=intents)

# Register all commands and events onto the bot
register_announcements(bot)
register_admin_setup(bot)
register_tickets(bot)
register_events(bot)
register_reactions(bot)
register_audit(bot)
register_role_changes(bot)


@bot.tree.error
async def on_app_command_error(interaction: discord.Interaction, error: app_commands.AppCommandError):
    cause = getattr(error, 'original', error)

    if isinstance(cause, discord.NotFound) and getattr(cause, 'code', None) == 10062:
        return

    try:
        if interaction.response.is_done():
            await interaction.followup.send(f"An error occurred: {error}", ephemeral=True)
            return
        if isinstance(error, app_commands.MissingAnyRole):
            await interaction.response.send_message("You don't have permission to use this command.", ephemeral=True)
            return
        await interaction.response.send_message(f"An error occurred: {error}", ephemeral=True)
    except (discord.NotFound, discord.HTTPException):
        print(f"Error in error handler (could not respond): {error}")


@bot.command(name="sync")
@commands.is_owner()
async def sync(ctx):
    synced = await bot.tree.sync(guild=GUILD_ID)
    await ctx.send(f"Synced {len(synced)} commands.")


@bot.command(name="clearglobal")
@commands.is_owner()
async def clearglobal(ctx):
    bot.tree.clear_commands(guild=None)
    await bot.tree.sync()
    await ctx.send("Global commands cleared.")


@bot.event
async def on_ready():
    bot.add_view(StaffRoleView())
    bot.add_view(CloseTicketView())
    bot.add_view(SupportTicketView())
    for g in bot.guilds:
        print(f'{bot.user} has connected to: {g.name} (id: {g.id})')
    print(f"Local commands loaded: {[cmd.name for cmd in bot.tree.get_commands(guild=GUILD_ID)]}")
    print("Ready! Run !sync to push commands to Discord.")
    print(f"Total Guilds: {len(bot.guilds)}")

    # Start the local HTTP bridge so the PHP admin panel can send announcements
    # through the bot (listens on 127.0.0.1:5001 — localhost only)
    await start_bridge(bot)


bot.run(token)
