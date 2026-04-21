import discord
from discord.ext import commands

ROLE_LOG_CHANNEL_ID = 1492896809880785016


def register(bot: commands.Bot):

    @bot.event
    async def on_member_update(before: discord.Member, after: discord.Member):
        # Check if roles changed
        before_roles = set(before.roles)
        after_roles  = set(after.roles)

        added   = after_roles - before_roles
        removed = before_roles - after_roles

        if not added and not removed:
            return

        channel = bot.get_channel(ROLE_LOG_CHANNEL_ID)
        if channel is None:
            try:
                channel = await bot.fetch_channel(ROLE_LOG_CHANNEL_ID)
            except (discord.NotFound, discord.Forbidden):
                return

        embed = discord.Embed(
            title="Role Update",
            color=0xAD42F5,
            timestamp=discord.utils.utcnow()
        )
        embed.set_author(
            name=f"{after.name} ({after.id})",
            icon_url=after.display_avatar.url
        )
        embed.add_field(name="Member", value=after.mention, inline=False)

        if added:
            embed.add_field(
                name="Roles Added",
                value=" ".join(r.mention for r in added),
                inline=False
            )
        if removed:
            embed.add_field(
                name="Roles Removed",
                value=" ".join(r.mention for r in removed),
                inline=False
            )

        embed.set_footer(text=f"User ID: {after.id}")

        try:
            await channel.send(embed=embed)
        except discord.Forbidden:
            print(f"Cannot send to role log channel {ROLE_LOG_CHANNEL_ID}")