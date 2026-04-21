from discord.ext import commands
from utils.constants import EMOJI_ROLE_MAP, ROLE_REACTION_MESSAGE_ID


def register(bot: commands.Bot):

    async def _update_role(payload, add: bool):
        if payload.user_id == bot.user.id:
            return
        if payload.message_id != ROLE_REACTION_MESSAGE_ID:
            return

        role_id = EMOJI_ROLE_MAP.get(str(payload.emoji))
        if not role_id:
            return

        guild  = bot.get_guild(payload.guild_id)
        member = guild.get_member(payload.user_id)
        role   = guild.get_role(role_id)

        if add:
            await member.add_roles(role)
        else:
            await member.remove_roles(role)

    @bot.event
    async def on_raw_reaction_add(payload):
        await _update_role(payload, add=True)

    @bot.event
    async def on_raw_reaction_remove(payload):
        await _update_role(payload, add=False)