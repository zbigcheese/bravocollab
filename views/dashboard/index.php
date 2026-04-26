<div class="dashboard" id="dashboard">
    <div class="dashboard-header">
        <h1>My Boards</h1>
        <div class="dashboard-header-actions">
            <label class="archived-toggle archived-toggle-dark" title="Show archived boards">
                <input type="checkbox" id="showArchivedBoardsToggle">
                <span class="archived-toggle-slider"></span>
                <span class="archived-toggle-label">Show archived</span>
            </label>
            <?php if (Auth::isAdmin()): ?>
            <button class="btn btn-primary" id="createBoardBtn">+ New Board</button>
            <?php endif; ?>
        </div>
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
            <a class="board-update" data-activity-id="${u.id}" href="index.php?page=board&id=${boardId}&card=${u.card_id}">
                <div class="board-update-line"><strong>${App.escapeHtml(u.actor_name)}</strong> ${verb}</div>
                <div class="board-update-card">
                    <span class="board-update-card-title">${App.escapeHtml(u.card_title)}</span>
                    <span class="board-update-time">${App.formatDate(u.created_at)}</span>
                </div>
            </a>
        `;
    }

    // Animate updates for a single board tile to reflect the new list.
    // Smooth transitions for enter (new at top), leave (collapse), and move (FLIP).
    function applyUpdatesToBoard(wrap, boardId, updates) {
        let container = wrap.querySelector('.board-updates');

        if (updates.length === 0) {
            if (container) {
                container.style.transition = 'opacity 0.3s';
                container.style.opacity = '0';
                setTimeout(() => container.remove(), 300);
            }
            return;
        }

        if (!container) {
            container = document.createElement('div');
            container.className = 'board-updates';
            container.innerHTML = updates.map(u => renderUpdate(boardId, u)).join('');
            wrap.appendChild(container);
            return;
        }

        // Capture old positions for FLIP.
        const existing = Array.from(container.querySelectorAll('.board-update'));
        const oldRects = new Map();
        existing.forEach(el => oldRects.set(el.dataset.activityId, el.getBoundingClientRect()));

        const newIds = new Set(updates.map(u => String(u.id)));
        const oldIds = new Set(existing.map(el => el.dataset.activityId));

        // Collapse outgoing items; keep in DOM during the animation so FLIP math is clean.
        const leaving = existing.filter(el => !newIds.has(el.dataset.activityId));
        leaving.forEach(el => {
            const h = el.getBoundingClientRect().height;
            el.style.height       = h + 'px';
            el.style.overflow     = 'hidden';
            /* force reflow */ el.offsetHeight;
            el.classList.add('upd-leaving');
            requestAnimationFrame(() => {
                el.style.height        = '0';
                el.style.paddingTop    = '0';
                el.style.paddingBottom = '0';
                el.style.opacity       = '0';
                el.style.borderBottomWidth = '0';
            });
            setTimeout(() => el.remove(), 360);
        });

        // Re-append everything in new order — appendChild moves existing nodes.
        updates.forEach(u => {
            const idStr = String(u.id);
            let el;
            if (oldIds.has(idStr)) {
                el = existing.find(e => e.dataset.activityId === idStr);
            } else {
                const tmp = document.createElement('div');
                tmp.innerHTML = renderUpdate(boardId, u);
                el = tmp.firstElementChild;
                el.classList.add('upd-entering');
            }
            container.appendChild(el);
        });

        // FLIP kept items + release entering items on the next frame.
        requestAnimationFrame(() => {
            updates.forEach(u => {
                const idStr = String(u.id);
                if (!oldIds.has(idStr)) return;
                const el = container.querySelector(`.board-update[data-activity-id="${idStr}"]`);
                if (!el) return;
                const oldRect = oldRects.get(idStr);
                const newRect = el.getBoundingClientRect();
                const dy = oldRect.top - newRect.top;
                if (Math.abs(dy) < 1) return;
                el.style.transition = 'none';
                el.style.transform  = `translateY(${dy}px)`;
                /* flush */ el.offsetHeight;
                el.style.transition = 'transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1)';
                el.style.transform  = '';
            });
            container.querySelectorAll('.upd-entering').forEach(el => {
                /* flush initial styles */ el.offsetHeight;
                el.classList.remove('upd-entering');
            });
        });
    }

    // Signature of the currently-displayed updates per board, for change detection.
    const updateSignatures = {};
    const signatureOf = (arr) => arr.map(u => u.id).join(',');

    async function refreshUpdates() {
        if (document.hidden) return;
        try {
            const res = await App.api('boards.recent_updates', {}, 'GET');
            const byBoard = res.updates || {};
            document.querySelectorAll('.board-tile-wrap').forEach(wrap => {
                const bid = wrap.dataset.boardId;
                const updates = byBoard[bid] || [];
                const sig = signatureOf(updates);
                if (updateSignatures[bid] === sig) return;
                updateSignatures[bid] = sig;
                applyUpdatesToBoard(wrap, bid, updates);
            });
        } catch (e) { /* silent */ }
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
            const isPersonal = b.is_personal == 1;
            // Personal board never carries an updates list (it's single-user)
            // and its tile stretches to match the visual height of a regular
            // board tile + its 3 updates so the grid stays even.
            const updates = isPersonal ? [] : (updatesByBoard[b.id] || []);
            updateSignatures[b.id] = signatureOf(updates);
            const updatesHtml = updates.length
                ? `<div class="board-updates">${updates.map(u => renderUpdate(b.id, u)).join('')}</div>`
                : '';
            const archivedCls = b.is_archived == 1 ? ' board-tile-wrap-archived' : '';
            const personalCls = isPersonal ? ' board-tile-wrap-personal' : '';
            const archivedPrefix = b.is_archived == 1
                ? '<span class="board-tile-archived-tag">(Archived)&nbsp;</span>' : '';
            const metaHtml = isPersonal
                ? ''
                : `<div class="board-tile-meta">${b.member_count} member${b.member_count != 1 ? 's' : ''}</div>`;
            const tileExtraCls = isPersonal ? ' board-tile-personal' : '';
            return `
                <div class="board-tile-wrap${archivedCls}${personalCls}" data-board-id="${b.id}">
                    <a href="index.php?page=board&id=${b.id}" class="board-tile${tileExtraCls}" style="background-color:${App.escapeHtml(b.background_color)}">
                        <div class="board-tile-title">${archivedPrefix}${App.escapeHtml(b.title)}</div>
                        ${metaHtml}
                    </a>
                    ${updatesHtml}
                </div>
            `;
        }).join('');

        // Show-archived toggle (persisted across sessions)
        const archivedToggle = document.getElementById('showArchivedBoardsToggle');
        const dashEl = document.getElementById('dashboard');
        if (archivedToggle && dashEl) {
            const key = 'showArchivedBoards';
            const on = localStorage.getItem(key) === '1';
            archivedToggle.checked = on;
            dashEl.classList.toggle('show-archived', on);
            archivedToggle.addEventListener('change', () => {
                const v = archivedToggle.checked;
                dashEl.classList.toggle('show-archived', v);
                try { localStorage.setItem(key, v ? '1' : '0'); } catch (e) {}
            });
        }

        // Poll for live updates while the tab is visible.
        setInterval(refreshUpdates, 10000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshUpdates();
        });
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
