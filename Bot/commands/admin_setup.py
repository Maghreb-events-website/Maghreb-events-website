import discord
from discord import app_commands
from discord.ext import commands

from utils.constants import (
    GUILD_ID,
    RULES_CHANNEL_ID,
    ROSTER_CHANNEL_ID,
    VERIFY_CHANNEL_ID,
    ROLE_MSG_CHANNEL_ID,
    STAFF_ROLE_CHANNEL_ID,
)
from ui.ticket_views import StaffRoleView


def register(bot: commands.Bot):

    @bot.tree.command(name="rules", description="Send the server rules", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def rules(interaction: discord.Interaction):
        channel = bot.get_channel(RULES_CHANNEL_ID)
        embed   = discord.Embed(
            title="Server Rules",
            description="Please read and follow the rules below to ensure a positive experience for everyone in the server.",
            color=0x00FF00
        )
        embed.add_field(name="1. Be Respectful",                    value="Treat all members with respect. Harassment, discrimination, or hate speech will not be tolerated.",              inline=False)
        embed.add_field(name="2. No Spamming",                      value="Avoid spamming messages, images, or reactions. Keep the chat clean and organized.",                              inline=False)
        embed.add_field(name="3. Follow Discord's Terms of Service",value="Ensure that your behavior complies with Discord's Terms of Service and Community Guidelines.",                   inline=False)
        embed.add_field(name="4. Use Appropriate Channels",         value="Post content in the appropriate channels to keep discussions organized.",                                        inline=False)
        embed.add_field(name="5. No NSFW Content",                  value="NSFW content is strictly prohibited in this server.",                                                            inline=False)
        embed.add_field(name="6. Follow VATSIM Code of Conduct",    value="You must follow the VATSIM Code of Conduct at all times, any breaches will result in moderation action being taken.", inline=False)
        embed.set_footer(text="These rules are subject to change at any time. Please check back regularly for updates.")
        embed.set_thumbnail(url="https://cdn.discordapp.com/attachments/1346041838527840256/1492524663191113921/image.png?ex=69dba569&is=69da53e9&hm=dd4559d44d52e19b5b953739c02b345e9d6068786d7965e4ec9db0fa441a643b&")
        try:
            msg = await channel.send(embed=embed)
            await msg.add_reaction("✅")
            await interaction.response.send_message("Rules sent!", ephemeral=True)
        except Exception as e:
            await interaction.response.send_message(f"Error: {e}", ephemeral=True)

    @bot.tree.command(name="acc_roster", description="Send the ATC roster", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def acc_roster(interaction: discord.Interaction):
        channel = bot.get_channel(ROSTER_CHANNEL_ID)
        embed   = discord.Embed(title="New Years in Morocco Roster", description="", color=0xAD42F5)
        embed.add_field(name="**GMMM ACC**",                      value="", inline=False)
        embed.add_field(name="`GMMM_N_CTR`",                      value="", inline=False)
        embed.add_field(name="`GMMM_E_CTR`",                      value="", inline=False)
        embed.add_field(name="`GMMM_S_CTR`",                      value="", inline=False)
        embed.add_field(name="====================================", value="", inline=False)
        embed.add_field(name="**GMAC ACC**",                      value="", inline=False)
        embed.add_field(name="`GMAC_CTR`",                        value="", inline=False)
        embed.add_field(name="`GMAC_X_CTR`",                      value="", inline=False)
        embed.add_field(name="====================================", value="", inline=False)
        embed.add_field(name="Google sheet roster can be found:", value="", inline=False)
        await channel.send(embed=embed)
        await interaction.response.send_message("Roster sent!", ephemeral=True)

    @bot.tree.command(name="verify_msg", description="Send the verification message", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def verify_msg(interaction: discord.Interaction):
        channel = bot.get_channel(VERIFY_CHANNEL_ID)
        embed   = discord.Embed(title="Verification", description="", color=0x00FF00)
        embed.add_field(name="Please send a message below with your name and VATSIM CID to gain access to the server", value="", inline=False)
        embed.add_field(name="Possible names include:", value="`CID only`, `First name + CID`, `First + Last name's + CID`", inline=False)
        embed.add_field(name="Example:",                value="**`Jamie Datson - 1635257`**", inline=False)
        await channel.send(embed=embed)
        await interaction.response.send_message("Verification message sent!", ephemeral=True)

    @bot.tree.command(name="role_msg", description="Send the role selection message", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def role_msg(interaction: discord.Interaction):
        channel = bot.get_channel(ROLE_MSG_CHANNEL_ID)
        embed   = discord.Embed(title="Role Selection", description="", color=0x00FF00)
        embed.add_field(name="Please react with the corresponding emojis below to be notified when changes occur.", value="", inline=False)
        embed.add_field(name="**`🇲🇦`  - Event Announcements**", value="", inline=False)
        embed.add_field(name="**`🎯`  - Hitsquad Announcements**", value="", inline=False)
        try:
            msg = await channel.send(embed=embed)
            await msg.add_reaction("🇲🇦")
            await msg.add_reaction("🎯")
            await interaction.response.send_message("Role message sent!", ephemeral=True)
        except Exception as e:
            await interaction.response.send_message(f"Error: {e}", ephemeral=True)

    @bot.tree.command(name="staff_roles_msg", description="Send the staff role request message", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def staff_roles_msg(interaction: discord.Interaction):
        channel = bot.get_channel(STAFF_ROLE_CHANNEL_ID)
        embed   = discord.Embed(title="⚒️ Staff Role Request", color=0x00FF00)
        embed.add_field(
            name="If you are a staff member of a VA that is affiliated with NYIM or would like to be, or VATSIM Marketing/Events staff, please open a staff request ticket with the button below",
            value="", inline=False
        )
        await channel.send(embed=embed, view=StaffRoleView())
        await interaction.response.send_message("Staff role message sent!", ephemeral=True)