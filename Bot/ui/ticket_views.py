import discord
from utils.helpers import get_log_channel, get_summary_channel, parse_created_at, build_topic


# ---------------------------------------------------------------------------
# Shared helper — sends the summary embed to the ticket summary channel
# ---------------------------------------------------------------------------

async def send_ticket_summary(
    interaction: discord.Interaction,
    ticket_owner: discord.Member | None,
    closed_by: discord.Member,
    reason: str,
    channel_name: str
):
    summary_channel = await get_summary_channel(interaction.guild)
    if not summary_channel:
        return

    created_at_raw = parse_created_at(interaction.channel)
    if created_at_raw:
        try:
            ts = int(created_at_raw)
            opened_value = f"<t:{ts}:F>"
        except ValueError:
            opened_value = "Unknown"
    else:
        opened_value = "Unknown"

    embed = discord.Embed(title="📋 Ticket Closed", color=0xFF0000, timestamp=discord.utils.utcnow())
    embed.add_field(name="Ticket Name", value=channel_name,                                          inline=False)
    embed.add_field(name="Opened By",   value=ticket_owner.mention if ticket_owner else "Unknown",  inline=True)
    embed.add_field(name="Closed By",   value=closed_by.mention,                                    inline=True)
    embed.add_field(name="Time Opened", value=opened_value,                                         inline=False)
    embed.add_field(name="Reason",      value=reason,                                               inline=False)

    await summary_channel.send(embed=embed)


# ---------------------------------------------------------------------------
# Close Ticket (persistent button + modal)
# ---------------------------------------------------------------------------

class CloseTicketView(discord.ui.View):
    def __init__(self):
        super().__init__(timeout=None)

    @discord.ui.button(
        label="Close Ticket",
        style=discord.ButtonStyle.danger,
        emoji="🔒",
        custom_id="close_ticket_button"
    )
    async def close_ticket(self, interaction: discord.Interaction, button: discord.ui.Button):
        ticket_owner = None
        topic = interaction.channel.topic or ""
        for part in topic.split("|"):
            part = part.strip()
            if part.startswith("owner:"):
                try:
                    owner_id = int(part.split("owner:")[1].strip())
                    ticket_owner = interaction.guild.get_member(owner_id)
                except (ValueError, IndexError):
                    pass
        await interaction.response.send_modal(CloseTicketModal(ticket_owner))


class CloseTicketModal(discord.ui.Modal, title="Close Ticket"):
    outcome = discord.ui.TextInput(
        label="Outcome",
        placeholder="Describe the outcome of this ticket...",
        style=discord.TextStyle.long,
        required=True
    )

    def __init__(self, ticket_owner: discord.Member = None):
        super().__init__()
        self.ticket_owner = ticket_owner

    async def on_submit(self, interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True)
        log_channel = await get_log_channel(interaction.guild)
        channel_name = interaction.channel.name

        if self.ticket_owner:
            try:
                dm_embed = discord.Embed(
                    title="Your Ticket Has Been Closed",
                    description=f"**Outcome:**\n{self.outcome.value}",
                    color=0xFF0000
                )
                await self.ticket_owner.send(embed=dm_embed)
            except discord.Forbidden:
                await interaction.followup.send(
                    "Could not DM the ticket owner — they may have DMs disabled.",
                    ephemeral=True
                )

        if log_channel:
            embed = discord.Embed(title="🔒 Ticket Closed", color=0xFF0000, timestamp=discord.utils.utcnow())
            embed.add_field(name="Ticket",       value=channel_name,                                               inline=True)
            embed.add_field(name="Closed By",    value=interaction.user.mention,                                   inline=True)
            embed.add_field(name="Ticket Owner", value=self.ticket_owner.mention if self.ticket_owner else "Unknown", inline=True)
            embed.add_field(name="Outcome",      value=self.outcome.value,                                         inline=False)
            embed.set_footer(text=f"Ticket Owner ID: {self.ticket_owner.id if self.ticket_owner else 'Unknown'}")
            await log_channel.send(embed=embed)
        else:
            print("Could not find log channel!")

        await send_ticket_summary(
            interaction,
            ticket_owner=self.ticket_owner,
            closed_by=interaction.user,
            reason=self.outcome.value,
            channel_name=channel_name
        )

        await interaction.followup.send("Ticket closed.", ephemeral=True)
        await interaction.channel.delete()


# ---------------------------------------------------------------------------
# Close Request (admin asks owner to confirm closure)
# ---------------------------------------------------------------------------

class CloseRequestView(discord.ui.View):
    def __init__(self, ticket_owner: discord.Member | None, requested_by: discord.Member, reason: str):
        super().__init__(timeout=300)
        self.ticket_owner  = ticket_owner
        self.requested_by  = requested_by
        self.reason        = reason

    @discord.ui.button(label="Accept & Close", style=discord.ButtonStyle.success, emoji="✅")
    async def accept(self, interaction: discord.Interaction, button: discord.ui.Button):
        if self.ticket_owner and interaction.user.id != self.ticket_owner.id:
            await interaction.response.send_message("❌ Only the ticket owner can respond to this.", ephemeral=True)
            return

        for child in self.children:
            child.disabled = True
        await interaction.response.edit_message(view=self)
        channel_name = interaction.channel.name

        if self.ticket_owner:
            try:
                dm_embed = discord.Embed(
                    title="Your ticket has been closed",
                    description=f"A close request was accepted.\n\n**Reason:** {self.reason}",
                    color=0xFF0000
                )
                await self.ticket_owner.send(embed=dm_embed)
            except discord.Forbidden:
                pass

        log_channel = await get_log_channel(interaction.guild)
        if log_channel:
            embed = discord.Embed(title="🔒 Ticket Closed", color=0xFF0000, timestamp=discord.utils.utcnow())
            embed.add_field(name="Ticket",       value=channel_name,                                               inline=True)
            embed.add_field(name="Closed By",    value=interaction.user.mention,                                   inline=True)
            embed.add_field(name="Requested By", value=self.requested_by.mention,                                  inline=True)
            embed.add_field(name="Ticket Owner", value=self.ticket_owner.mention if self.ticket_owner else "Unknown", inline=True)
            embed.add_field(name="Reason",       value=self.reason,                                               inline=False)
            await log_channel.send(embed=embed)

        await send_ticket_summary(
            interaction,
            ticket_owner=self.ticket_owner,
            closed_by=interaction.user,
            reason=self.reason,
            channel_name=channel_name
        )

        await interaction.channel.delete()
        self.stop()

    @discord.ui.button(label="Decline", style=discord.ButtonStyle.danger, emoji="❌")
    async def decline(self, interaction: discord.Interaction, button: discord.ui.Button):
        if self.ticket_owner and interaction.user.id != self.ticket_owner.id:
            await interaction.response.send_message("❌ Only the ticket owner can respond to this.", ephemeral=True)
            return

        for child in self.children:
            child.disabled = True
        await interaction.response.edit_message(view=self)
        await interaction.followup.send("Close request declined. Ticket will remain open.", ephemeral=False)
        self.stop()


class CloseRequestModal(discord.ui.Modal, title="Close Request"):
    reason = discord.ui.TextInput(
        label="Reason",
        placeholder="Why is this ticket being requested for closure?",
        style=discord.TextStyle.long,
        required=True
    )

    def __init__(self, owner_id: int | None):
        super().__init__()
        self.owner_id = owner_id

    async def on_submit(self, interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True)

        ticket_owner = None
        if self.owner_id:
            ticket_owner = interaction.guild.get_member(self.owner_id)
            if ticket_owner is None:
                try:
                    ticket_owner = await interaction.guild.fetch_member(self.owner_id)
                except (discord.NotFound, discord.HTTPException):
                    pass

        if not ticket_owner:
            await interaction.followup.send("❌ Could not determine ticket owner.", ephemeral=True)
            return

        await interaction.channel.send(
            f"{ticket_owner.mention}"
        )

        embed = discord.Embed(
            title="Close Request",
            description=(
                f"This ticket has been requested to be closed "
                f"by {interaction.user.mention}.\n\n"
                f"**Reason:** {self.reason.value}\n\n"
                "Please accept or decline using the buttons below."
            ),
            color=0xFF0000
        )
        await interaction.channel.send(
            embed=embed,
            view=CloseRequestView(ticket_owner, interaction.user, self.reason.value),
        )


# ---------------------------------------------------------------------------
# Staff ticket creation flow
# ---------------------------------------------------------------------------

class StaffRoleView(discord.ui.View):
    """Persistent button posted in the staff-roles channel."""
    def __init__(self):
        super().__init__(timeout=None)

    @discord.ui.button(
        label="Open Staff Request Ticket",
        style=discord.ButtonStyle.success,
        emoji="🎫",
        custom_id="open_staff_ticket_button"
    )
    async def staff_request(self, interaction: discord.Interaction, button: discord.ui.Button):
        try:
            await interaction.response.send_message(
                "Please select your staff type:",
                view=StaffTypeView(interaction.user),
                ephemeral=True
            )
        except (discord.NotFound, discord.HTTPException):
            pass


class StaffTypeView(discord.ui.View):
    def __init__(self, ticket_owner: discord.Member):
        super().__init__(timeout=60)
        self.ticket_owner = ticket_owner

    @discord.ui.button(label="VATSIM Staff", style=discord.ButtonStyle.primary, emoji="🌐")
    async def vatsim_staff(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.send_modal(StaffDetailsModal(self.ticket_owner, staff_type="VATSIM"))

    @discord.ui.button(label="VA Staff", style=discord.ButtonStyle.secondary, emoji="✈️")
    async def va_staff(self, interaction: discord.Interaction, button: discord.ui.Button):
        await interaction.response.send_modal(StaffDetailsModal(self.ticket_owner, staff_type="VA"))


class StaffDetailsModal(discord.ui.Modal):
    def __init__(self, ticket_owner: discord.Member, staff_type: str):
        super().__init__(title=f"{staff_type} Staff Request")
        self.ticket_owner = ticket_owner
        self.staff_type   = staff_type

        self.vatsim_cid = discord.ui.TextInput(
            label="VATSIM CID",
            placeholder="e.g. 1635257",
            required=True
        )
        self.role = discord.ui.TextInput(
            label="Your Role",
            placeholder=f"Your role within {'VATSIM' if staff_type == 'VATSIM' else 'your VA'}",
            required=True
        )
        self.va_name = discord.ui.TextInput(
            label="VA Name" if staff_type == "VA" else "Division / Department",
            placeholder="e.g. British Airways VA" if staff_type == "VA" else "e.g. VATSIM Marketing",
            required=True
        )
        self.roster = discord.ui.TextInput(
            label="Roster Link",
            placeholder="Link to the roster where we can verify you",
            required=True
        )
        self.additional = discord.ui.TextInput(
            label="Additional Information",
            placeholder="Anything else you'd like to add...",
            style=discord.TextStyle.long,
            required=False
        )
        self.add_item(self.vatsim_cid)
        self.add_item(self.role)
        self.add_item(self.va_name)
        self.add_item(self.roster)
        self.add_item(self.additional)

    async def on_submit(self, interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True)
        from utils.constants import TICKET_CATEGORY_ID, STAFF_ROLE_ID

        guild      = interaction.guild
        category   = discord.utils.get(guild.categories, id=TICKET_CATEGORY_ID)
        staff_role = guild.get_role(STAFF_ROLE_ID)
        channel_name = f"VATSIM-{interaction.user.name}" if self.staff_type == "VATSIM" else f"VA-{interaction.user.name}"

        channel = await guild.create_text_channel(
            name=channel_name,
            category=category,
            topic=build_topic(interaction.user.id),
            overwrites={
                guild.default_role: discord.PermissionOverwrite(view_channel=False),
                interaction.user:   discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                guild.me:           discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                staff_role:         discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
            }
        )

        embed = discord.Embed(
            title=f"{'🌐 VATSIM' if self.staff_type == 'VATSIM' else '✈️ VA'} Staff Role Request",
            color=0x00FF00
        )
        embed.add_field(name="Applicant",   value=interaction.user.mention, inline=True)
        embed.add_field(name="Staff Type",  value=self.staff_type,          inline=True)
        embed.add_field(name="VATSIM CID",  value=self.vatsim_cid.value,    inline=True)
        embed.add_field(name="Role",        value=self.role.value,          inline=True)
        embed.add_field(
            name="VA Name" if self.staff_type == "VA" else "Division / Department",
            value=self.va_name.value, inline=True
        )
        embed.add_field(name="Roster Link", value=self.roster.value, inline=False)
        if self.additional.value:
            embed.add_field(name="Additional Information", value=self.additional.value, inline=False)

        await channel.send(
            content=f"{interaction.user.mention} {staff_role.mention}\nUse the 🔒 button below or `/close_ticket` to close this ticket.",
            embed=embed,
            view=CloseTicketView()
        )
        await interaction.followup.send(f"Your ticket has been opened: {channel.mention}", ephemeral=True)


# ---------------------------------------------------------------------------
# Support / misconduct ticket flow
# ---------------------------------------------------------------------------

class SupportTicketView(discord.ui.View):
    """Persistent button posted in the support channel."""
    def __init__(self):
        super().__init__(timeout=None)

    @discord.ui.button(
        label="Create a Ticket",
        style=discord.ButtonStyle.primary,
        emoji="🎫",
        custom_id="open_support_ticket_button"
    )
    async def support_request(self, interaction: discord.Interaction, button: discord.ui.Button):
        try:
            await interaction.response.send_modal(SupportTicketModal())
        except (discord.NotFound, discord.HTTPException):
            pass


class SupportTicketModal(discord.ui.Modal, title="Create a Ticket"):
    cid = discord.ui.TextInput(
        label="VATSIM CID",
        placeholder="e.g. 1635257",
        required=True
    )
    issue = discord.ui.TextInput(
        label="Issue / Question",
        placeholder="Please describe your issue or question in detail...",
        style=discord.TextStyle.long,
        required=True
    )

    async def on_submit(self, interaction: discord.Interaction):
        await interaction.response.defer(ephemeral=True)
        from utils.constants import TICKET_CATEGORY_ID, STAFF_ROLE_ID

        guild      = interaction.guild
        category   = discord.utils.get(guild.categories, id=TICKET_CATEGORY_ID)
        staff_role = guild.get_role(STAFF_ROLE_ID)
        channel_name = f"support-{interaction.user.name}"

        channel = await guild.create_text_channel(
            name=channel_name,
            category=category,
            topic=build_topic(interaction.user.id),
            overwrites={
                guild.default_role: discord.PermissionOverwrite(view_channel=False),
                interaction.user:   discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                guild.me:           discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
                staff_role:         discord.PermissionOverwrite(view_channel=True, send_messages=True, read_message_history=True),
            }
        )

        embed = discord.Embed(title="🎫 Support Ticket", color=0x5865F2)
        embed.add_field(name="Opened By",        value=interaction.user.mention, inline=True)
        embed.add_field(name="VATSIM CID",       value=self.cid.value,           inline=True)
        embed.add_field(name="Issue / Question", value=self.issue.value,         inline=False)

        log_channel = await get_log_channel(guild)
        if log_channel:
            log_embed = discord.Embed(title="🎫 Support Ticket Opened", color=0x5865F2, timestamp=discord.utils.utcnow())
            log_embed.add_field(name="Ticket",           value=channel_name,             inline=True)
            log_embed.add_field(name="Opened By",        value=interaction.user.mention, inline=True)
            log_embed.add_field(name="VATSIM CID",       value=self.cid.value,           inline=True)
            log_embed.add_field(name="Issue / Question", value=self.issue.value,         inline=False)
            log_embed.set_footer(text=f"User ID: {interaction.user.id}")
            await log_channel.send(embed=log_embed)

        await channel.send(
            content=f"{interaction.user.mention} {staff_role.mention}\nUse the 🔒 button below or `/close_ticket` to close this ticket.",
            embed=embed,
            view=CloseTicketView()
        )
        await interaction.followup.send(f"Your ticket has been opened: {channel.mention}", ephemeral=True)