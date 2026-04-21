import discord
from utils.constants import LOG_CHANNEL_ID, TICKET_SUMMARY_CHANNEL_ID


async def get_log_channel(guild: discord.Guild) -> discord.TextChannel | None:
    """Fetch the log channel, trying cache first then API."""
    channel = guild.get_channel(LOG_CHANNEL_ID)
    if channel is None:
        try:
            channel = await guild.fetch_channel(LOG_CHANNEL_ID)
        except (discord.NotFound, discord.Forbidden) as e:
            print(f"Cannot access log channel: {e!r}")
    return channel


async def get_summary_channel(guild: discord.Guild) -> discord.TextChannel | None:
    """Fetch the ticket summary channel, trying cache first then API."""
    channel = guild.get_channel(TICKET_SUMMARY_CHANNEL_ID)
    if channel is None:
        try:
            channel = await guild.fetch_channel(TICKET_SUMMARY_CHANNEL_ID)
        except (discord.NotFound, discord.Forbidden) as e:
            print(f"Cannot access ticket summary channel: {e!r}")
    return channel


def parse_owner_id(channel: discord.TextChannel) -> int | None:
    """Extract the ticket owner's ID from a channel topic."""
    topic = channel.topic or ""
    for part in topic.split("|"):
        part = part.strip()
        if part.startswith("owner:"):
            try:
                return int(part.split("owner:")[1].strip())
            except (ValueError, IndexError):
                pass
    return None


def parse_created_at(channel: discord.TextChannel) -> str | None:
    """Extract the created_at unix timestamp from a channel topic."""
    topic = channel.topic or ""
    for part in topic.split("|"):
        part = part.strip()
        if part.startswith("created_at:"):
            return part.split("created_at:")[1].strip()
    return None


def build_topic(owner_id: int) -> str:
    """Build a channel topic string with owner ID and creation timestamp."""
    ts = int(discord.utils.utcnow().timestamp())
    return f"owner:{owner_id} | created_at:{ts}"