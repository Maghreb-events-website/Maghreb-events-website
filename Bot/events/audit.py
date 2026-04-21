import discord
from discord.ext import commands
from utils.constants import AUDIT_LOG_CHANNEL_ID


def register(bot: commands.Bot):

    @bot.event
    async def on_interaction(interaction: discord.Interaction):
        # Work out what the user did
        if interaction.type == discord.InteractionType.application_command:
            action = f"Used slash command `/{interaction.data.get('name', 'unknown')}`"

        elif interaction.type == discord.InteractionType.component:
            custom_id = interaction.data.get("custom_id", "unknown")
            component_type = interaction.data.get("component_type", 0)
            if component_type == 2:
                action = f"Clicked button `{custom_id}`"
            elif component_type == 3:
                action = f"Used select menu `{custom_id}`"
            else:
                action = f"Used component `{custom_id}`"

        elif interaction.type == discord.InteractionType.modal_submit:
            custom_id = interaction.data.get("custom_id", "unknown")
            action = f"Submitted modal `{custom_id}`"

        else:
            action = f"Unknown interaction type `{interaction.type}`"

        channel = bot.get_channel(AUDIT_LOG_CHANNEL_ID)
        if channel is None:
            try:
                channel = await bot.fetch_channel(AUDIT_LOG_CHANNEL_ID)
            except (discord.NotFound, discord.Forbidden):
                return

        user = interaction.user
        location = f"<#{interaction.channel_id}>" if interaction.channel_id else "Unknown channel"

        embed = discord.Embed(color=0x5865F2, timestamp=discord.utils.utcnow())
        embed.set_author(name=f"{user.name} ({user.id})", icon_url=user.display_avatar.url)
        embed.add_field(name="Action",   value=action,   inline=False)
        embed.add_field(name="Channel",  value=location, inline=True)
        embed.set_footer(text=f"User ID: {user.id}")

        try:
            await channel.send(embed=embed)
        except discord.Forbidden:
            print(f"Cannot send to audit log channel {AUDIT_LOG_CHANNEL_ID}")