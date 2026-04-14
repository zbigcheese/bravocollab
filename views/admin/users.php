<?php if (!Auth::isAdmin()) { header('Location: index.php?page=dashboard'); exit; } ?>

<div class="admin-page">
    <div class="dashboard-header">
        <h1>User Management</h1>
        <button class="btn btn-primary" id="inviteUserBtn">+ Invite User</button>
    </div>

    <div class="admin-card">
        <table class="admin-table" id="usersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <tr><td colspan="6" style="text-align:center;"><div class="spinner"></div></td></tr>
            </tbody>
        </table>
    </div>

    <div class="admin-card" style="margin-top:24px;">
        <h2 style="font-size:16px;margin-bottom:12px;">Pending Invitations</h2>
        <div id="invitationsList"><div class="spinner"></div></div>
    </div>
</div>

<style>
.admin-page { max-width: 960px; margin: 0 auto; }
.admin-card { background: var(--color-white); border-radius: var(--radius-md); padding: 20px; box-shadow: var(--shadow-sm); }
.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th, .admin-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); font-size: 14px; }
.admin-table th { font-weight: 600; color: var(--color-text-light); font-size: 12px; text-transform: uppercase; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; }
.status-active { background: #E3FCEF; color: #006644; }
.status-inactive { background: #FFF4F4; color: #BF2600; }
.role-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; }
.role-admin { background: #E4F0F6; color: #0079BF; }
.role-member { background: var(--color-bg); color: var(--color-text-light); }
.invite-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--color-bg); font-size: 14px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    // Load users
    async function loadUsers() {
        const res = await App.api('users.list', {}, 'GET');
        const users = res.users || [];
        const tbody = document.getElementById('usersTableBody');

        tbody.innerHTML = users.map(u => `
            <tr>
                <td><strong>${App.escapeHtml(u.display_name)}</strong></td>
                <td>${App.escapeHtml(u.email)}</td>
                <td>
                    <select class="role-select" data-user-id="${u.id}" style="padding:4px 8px;border:1px solid var(--color-border);border-radius:4px;font-size:13px;">
                        <option value="member" ${u.role === 'member' ? 'selected' : ''}>Member</option>
                        <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Admin</option>
                    </select>
                </td>
                <td><span class="status-badge ${u.is_active ? 'status-active' : 'status-inactive'}">${u.is_active ? 'Active' : 'Inactive'}</span></td>
                <td>${u.last_login_at ? App.formatDate(u.last_login_at) : 'Never'}</td>
                <td>
                    <button class="btn btn-sm btn-secondary toggle-active" data-user-id="${u.id}">
                        ${u.is_active ? 'Deactivate' : 'Activate'}
                    </button>
                </td>
            </tr>
        `).join('');

        // Role change
        tbody.querySelectorAll('.role-select').forEach(sel => {
            sel.addEventListener('change', async () => {
                try {
                    await App.api('users.update_role', { user_id: parseInt(sel.dataset.userId), role: sel.value });
                    App.showToast('Role updated', 'success');
                } catch (e) {
                    App.showToast(e.message, 'error');
                    loadUsers();
                }
            });
        });

        // Toggle active
        tbody.querySelectorAll('.toggle-active').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await App.api('users.toggle_active', { user_id: parseInt(btn.dataset.userId) });
                    App.showToast('Status updated', 'success');
                    loadUsers();
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });
        });
    }

    await loadUsers();

    // Load all boards for pickers
    let allBoards = [];
    try {
        const bRes = await App.api('boards.list', {}, 'GET');
        allBoards = bRes.boards || [];
    } catch(e) {}

    function boardCheckboxes(selectedIds = []) {
        if (allBoards.length === 0) return '<p class="text-muted text-sm">No boards yet.</p>';
        return allBoards.map(b => `
            <label style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:13px;cursor:pointer;">
                <input type="checkbox" value="${b.id}" class="board-checkbox" ${selectedIds.includes(parseInt(b.id)) ? 'checked' : ''}>
                <span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:${App.escapeHtml(b.background_color)};flex-shrink:0;"></span>
                ${App.escapeHtml(b.title)}
            </label>
        `).join('');
    }

    function getCheckedBoardIds(container) {
        return Array.from(container.querySelectorAll('.board-checkbox:checked')).map(cb => parseInt(cb.value));
    }

    // Load pending invitations
    async function loadInvitations() {
        const container = document.getElementById('invitationsList');
        try {
            const res = await App.api('users.invitations', {}, 'GET');
            const invitations = res.invitations || [];
            if (invitations.length === 0) {
                container.innerHTML = '<p class="text-muted text-sm">No pending invitations.</p>';
                return;
            }
            container.innerHTML = invitations.map(inv => {
                const boardNames = (inv.boards || []).map(b => App.escapeHtml(b.title));
                const boardBadges = boardNames.length > 0
                    ? boardNames.map(n => `<span style="display:inline-block;background:var(--color-primary-light);color:var(--color-primary);padding:1px 6px;border-radius:3px;font-size:11px;margin-right:4px;">${n}</span>`).join('')
                    : '<span class="text-muted text-sm">No boards</span>';
                return `
                    <div class="invite-item" style="flex-wrap:wrap;gap:8px;">
                        <div style="flex:1;min-width:200px;">
                            <strong>${App.escapeHtml(inv.email)}</strong>
                            <span class="text-muted text-sm" style="margin-left:8px;">by ${App.escapeHtml(inv.invited_by_name)}</span>
                            <div style="margin-top:4px;">${boardBadges}</div>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span class="role-badge role-${inv.role}">${inv.role}</span>
                            <button class="btn btn-sm btn-secondary edit-inv-boards" data-inv-id="${inv.id}" data-board-ids="${(inv.boards||[]).map(b=>b.id).join(',')}">Boards</button>
                        </div>
                    </div>
                `;
            }).join('');

            // Bind board edit buttons
            container.querySelectorAll('.edit-inv-boards').forEach(btn => {
                btn.addEventListener('click', () => {
                    const invId = parseInt(btn.dataset.invId);
                    const currentIds = btn.dataset.boardIds ? btn.dataset.boardIds.split(',').map(Number).filter(Boolean) : [];

                    const modal = App.createModal('editInvBoardsModal', 'Assign Boards', `
                        <p class="text-sm text-muted" style="margin-bottom:12px;">Select boards this user will be added to when they accept the invitation.</p>
                        <div id="invBoardCheckboxes">${boardCheckboxes(currentIds)}</div>
                    `, `<button class="btn btn-primary" id="saveInvBoards">Save</button>`);

                    document.getElementById('saveInvBoards').addEventListener('click', async () => {
                        const boardIds = getCheckedBoardIds(document.getElementById('invBoardCheckboxes'));
                        try {
                            await App.api('users.update_invitation_boards', { invitation_id: invId, board_ids: boardIds });
                            App.showToast('Boards updated', 'success');
                            modal.remove();
                            loadInvitations();
                        } catch(e) {
                            App.showToast(e.message, 'error');
                        }
                    });
                });
            });
        } catch (e) {
            container.innerHTML = '<p class="text-muted text-sm">Failed to load invitations.</p>';
        }
    }
    await loadInvitations();

    // Invite user
    document.getElementById('inviteUserBtn').addEventListener('click', () => {
        App.createModal('inviteModal', 'Invite User', `
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" id="inviteEmail" placeholder="user@example.com" autofocus>
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="inviteRole" style="width:100%;padding:8px;border:2px solid var(--color-border);border-radius:4px;">
                    <option value="member">Member</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="form-group">
                <label>Assign to Boards</label>
                <div id="inviteBoardCheckboxes" style="max-height:200px;overflow-y:auto;border:1px solid var(--color-border);border-radius:4px;padding:8px;">
                    ${boardCheckboxes()}
                </div>
            </div>
        `, `<button class="btn btn-primary" id="submitInvite">Send Invitation</button>`);

        document.getElementById('inviteEmail').focus();

        document.getElementById('submitInvite').addEventListener('click', async () => {
            const email = document.getElementById('inviteEmail').value.trim();
            const role = document.getElementById('inviteRole').value;
            const boardIds = getCheckedBoardIds(document.getElementById('inviteBoardCheckboxes'));
            if (!email) { App.showToast('Email is required', 'error'); return; }

            try {
                const res = await App.api('users.invite', { email, role, board_ids: boardIds });
                App.showToast(res.message, 'success');
                if (res.invite_url) {
                    prompt('Email failed. Share this link manually:', res.invite_url);
                }
                App.closeModal('inviteModal');
                loadInvitations();
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });
    });
});
</script>
