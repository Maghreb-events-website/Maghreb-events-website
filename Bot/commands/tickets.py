import discord
from discord import app_commands
from discord.ext import commands

from utils.constants import GUILD_ID, TICKET_CATEGORY_ID, STAFF_ROLE_ID
from utils.helpers import parse_owner_id, build_topic
from ui.ticket_views import CloseTicketModal, CloseRequestModal, CloseTicketView, SupportTicketView
from utils.constants import SUPPORT_MSG_CHANNEL_ID


def register(bot: commands.Bot):

    @bot.tree.command(name="close_ticket", description="Close a ticket and send the outcome to the user", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def close_ticket_cmd(interaction: discord.Interaction):
        name = interaction.channel.name.lower()
        if "ticket" not in name and "vatsim-" not in name and "va-" not in name and "support-" not in name:
            await interaction.response.send_message("This command can only be used in a ticket channel.", ephemeral=True)
            return

        ticket_owner = None
        owner_id = parse_owner_id(interaction.channel)
        if owner_id:
            ticket_owner = interaction.guild.get_member(owner_id)

        # Fallback: try matching by username in channel name
        if not ticket_owner and "-" in interaction.channel.name:
            owner_name = interaction.channel.name.split("-", 1)[1]
            ticket_owner = discord.utils.get(interaction.guild.members, name=owner_name)

        await interaction.response.send_modal(CloseTicketModal(ticket_owner))

    @bot.tree.command(name="close_request", description="Request to close a ticket (owner must confirm)", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def close_request(interaction: discord.Interaction):
        name = interaction.channel.name.lower()
        if "vatsim-" not in name and "va-" not in name and "support-" not in name:
            await interaction.response.send_message("❌ This is not a ticket channel.", ephemeral=True)
            return

        owner_id = parse_owner_id(interaction.channel)
        if not owner_id:
            await interaction.response.send_message("❌ Could not determine ticket owner.", ephemeral=True)
            return

        await interaction.response.send_modal(CloseRequestModal(owner_id))

    @bot.tree.command(name="admin_ticket", description="Create a ticket on behalf of a user", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    @app_commands.describe(
        user="The user to open a ticket for",
        staff_type="Type of staff ticket"
    )
    @app_commands.choices(staff_type=[
        app_commands.Choice(name="VATSIM",   value="VATSIM"),
        app_commands.Choice(name="VA",       value="VA"),
        app_commands.Choice(name="Support",  value="Support"),
    ])
    async def admin_ticket(
        interaction: discord.Interaction,
        user: discord.Member,
        staff_type: app_commands.Choice[str]
    ):
        await interaction.response.defer(ephemeral=True)
        guild      = interaction.guild
        category   = discord.utils.get(guild.categories, id=TICKET_CATEGORY_ID)
        staff_role = guild.get_role(STAFF_ROLE_ID)

        def chan_name(name):
            base = "vatsim" if staff_type.value == "VATSIM" else "va" if staff_type.value == "VA" else "support"
            return f"{base}-{name}"

        channel_name = chan_name(user.name)

        channel = await guild.create_text_channel(
            name=channel_name,
            category=category,
            topic=build_topic(user.id),
            overwrites={
                guild.default_role: discord.PermissionOverwrite(view_channel=False),
                user:               discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                interaction.user:   discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                guild.me:           discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                staff_role:         discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
            }
        )

        embed = discord.Embed(
            title=f"{'🌐 VATSIM' if staff_type.value == 'VATSIM' else '✈️ VA'} Staff Role Request",
            color=0x00FF00
        )
        embed.add_field(name="Applicant",          value=user.mention,               inline=True)
        embed.add_field(name="Opened By (Admin)",  value=interaction.user.mention,   inline=True)
        embed.add_field(name="Staff Type",         value=staff_type.value,           inline=True)
        embed.add_field(name="VATSIM CID",         value="N/A",                      inline=True)
        embed.add_field(name="Role",               value="N/A",                      inline=True)
        embed.add_field(
            name="VA Name" if staff_type.value == "VA" else "Division / Department",
            value="N/A", inline=True
        )
        embed.add_field(name="Roster Link",            value="N/A", inline=False)
        embed.add_field(name="Additional Information", value="N/A", inline=False)

        await channel.send(
            content=(
                f"{user.mention} {staff_role.mention}\n"
                "This ticket was opened by an admin.\n"
                "Use the 🔒 button below or `/close_ticket` to close this ticket."
            ),
            embed=embed,
            view=CloseTicketView()
        )
        await interaction.followup.send(f"✅ Ticket created for {user.mention}: {channel.mention}", ephemeral=True)

    @bot.tree.command(name="add_user", description="Add a user to the current ticket channel", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    @app_commands.describe(user="The user to add to this ticket")
    async def add_user(interaction: discord.Interaction, user: discord.Member):
        name = interaction.channel.name.lower()
        if "vatsim-" not in name and "va-" not in name and "support-" not in name:
            await interaction.response.send_message("❌ This command can only be used in a ticket channel.", ephemeral=True)
            return

        await interaction.response.defer(ephemeral=False)
        await interaction.channel.set_permissions(
            user,
            view_channel=True,
            send_messages=True,
            read_message_history=True
        )
        await interaction.followup.send(f"{user.mention} has been added to this ticket.", ephemeral=False)


    @bot.tree.command(name="remove_user", description="Remove a user from the current ticket channel", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    @app_commands.describe(user="The user to remove from this ticket")
    async def remove_user(interaction: discord.Interaction, user: discord.Member):
        name = interaction.channel.name.lower()
        if "vatsim-" not in name and "va-" not in name and "support-" not in name:
            await interaction.response.send_message("❌ This command can only be used in a ticket channel.", ephemeral=True)
            return

        owner_id = parse_owner_id(interaction.channel)
        if owner_id and user.id == owner_id:
            await interaction.response.send_message("❌ You cannot remove the ticket owner.", ephemeral=True)
            return

        await interaction.response.defer(ephemeral=False)
        await interaction.channel.set_permissions(user, overwrite=None)
        await interaction.followup.send(f"{user.mention} has been removed from this ticket.", ephemeral=False)

    @bot.tree.command(name="support_msg", description="Send the support ticket message", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    async def support_msg(interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True)
        channel = bot.get_channel(SUPPORT_MSG_CHANNEL_ID)
        embed = discord.Embed(title="Create a ticket", color=0x5865F2)
        embed.add_field(
            name="",
            value=(
                "If you have experienced any misconduct from any member or would like your "
                "question answered, please create a ticket with the button below and a member "
                "of staff will sort it."
            ),
            inline=False
        )
        await channel.send(embed=embed, view=SupportTicketView())
        await interaction.followup.send("Support message sent!", ephemeral=True)

    @bot.tree.command(name="ticket_name", description="Rename the current ticket channel", guild=GUILD_ID)
    @app_commands.checks.has_any_role("Admin")
    @app_commands.describe(name="The new name for this ticket channel")
    async def ticket_name(interaction: discord.Interaction, name: str):
        channel = interaction.channel
        channel_name = channel.name.lower()
        if "vatsim-" not in channel_name and "va-" not in channel_name and "support-" not in channel_name:
            await interaction.response.send_message("❌ This command can only be used in a ticket channel.", ephemeral=True)
            return

        await interaction.response.defer(ephemeral=True)
        old_name = channel.name
        sanitised = name.lower().replace(" ", "-")
        await channel.edit(name=sanitised)
        await interaction.followup.send(f"✅ Ticket renamed from `{old_name}` to `{sanitised}`.", ephemeral=True)