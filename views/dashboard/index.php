<div class="dashboard">
    <div class="dashboard-header">
        <h1>My Boards</h1>
        <?php if (Auth::isAdmin()): ?>
        <button class="btn btn-primary" id="createBoardBtn">+ New Board</button>
        <?php endif; ?>
    </div>
    <div class="board-grid" id="boardGrid">
        <div class="empty-state" id="boardsLoading">
            <div class="spinner"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    const grid = document.getElementById('boardGrid');

    const actionVerbs = {
        card_created:      'created',
        card_updated:      'updated',
        card_archived:     'archived',
        comment_added:     'commented on',
        attachment_added:  'attached a file to',
    };

    function renderUpdate(boardId, u) {
        const verb = actionVerbs[u.action] || 'updated';
        return `
            <a class="board-update" href="index.php?page=board&id=${boardId}&card=${u.card_id}">
                <div class="board-update-line"><strong>${App.escapeHtml(u.actor_name)}</strong> ${verb}</div>
                <div class="board-update-card">
                    <span class="board-update-card-title">${App.escapeHtml(u.card_title)}</span>
                    <span class="board-update-time">${App.formatDate(u.created_at)}</span>
                </div>
            </a>
        `;
    }

    try {
        const [boardsRes, updatesRes] = await Promise.all([
            App.api('boards.list', {}, 'GET'),
            App.api('boards.recent_updates', {}, 'GET'),
        ]);
        const boards = boardsRes.boards || [];
        const updatesByBoard = updatesRes.updates || {};

        if (boards.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <h3>No boards yet</h3>
                    <p>${<?php echo Auth::isAdmin() ? 'true' : 'false'; ?> ? 'Create your first board to get started.' : 'Ask an administrator to add you to a board.'}</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = boards.map(b => {
            const updates = updatesByBoard[b.id] || [];
            const updatesHtml = updates.length
                ? `<div class="board-updates">${updates.map(u => renderUpdate(b.id, u)).join('')}</div>`
                : '';
            return `
                <div class="board-tile-wrap">
                    <a href="index.php?page=board&id=${b.id}" class="board-tile" style="background-color:${App.escapeHtml(b.background_color)}">
                        <div class="board-tile-title">${App.escapeHtml(b.title)}</div>
                        <div class="board-tile-meta">${b.member_count} member${b.member_count != 1 ? 's' : ''}</div>
                    </a>
                    ${updatesHtml}
                </div>
            `;
        }).join('');
    } catch (e) {
        grid.innerHTML = '<div class="empty-state"><p>Failed to load boards.</p></div>';
    }

    // Create board modal
    document.getElementById('createBoardBtn')?.addEventListener('click', () => {
        const colors = ['#0079BF','#D29034','#519839','#B04632','#89609E','#CD5A91','#4BBF6B','#00AECC','#838C91'];
        const colorOpts = colors.map(c =>
            `<label class="color-option" style="background:${c}">
                <input type="radio" name="board_color" value="${c}" ${c === '#0079BF' ? 'checked' : ''}>
                <span class="color-check">&#10003;</span>
            </label>`
        ).join('');

        App.createModal('createBoardModal', 'Create Board', `
            <div class="form-group">
                <label>Board Title</label>
                <input type="text" id="newBoardTitle" maxlength="255" autofocus placeholder="Enter board title...">
            </div>
            <div class="form-group">
                <label>Description (optional)</label>
                <textarea id="newBoardDesc" rows="3" placeholder="What is this board for?"></textarea>
            </div>
            <div class="form-group">
                <label>Background Color</label>
                <div class="color-picker">${colorOpts}</div>
            </div>
        `, `<button class="btn btn-primary" id="submitCreateBoard">Create Board</button>`);

        document.getElementById('newBoardTitle').focus();

        document.getElementById('submitCreateBoard').addEventListener('click', async () => {
            const title = document.getElementById('newBoardTitle').value.trim();
            if (!title) { App.showToast('Board title is required', 'error'); return; }
            const desc = document.getElementById('newBoardDesc').value.trim();
            const color = document.querySelector('input[name="board_color"]:checked')?.value || '#0079BF';

            try {
                const res = await App.api('boards.create', {
                    title, description: desc, background_color: color
                });
                if (res.success) {
                    window.location.href = `index.php?page=board&id=${res.board.id}`;
                }
            } catch(e) {
                App.showToast(e.message, 'error');
            }
        });
    });
});
</script>
