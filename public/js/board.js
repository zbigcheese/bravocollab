/**
 * BravoCollab - Board View
 */
const Board = {
    boardId: null,
    data: null,
    listSortable: null,
    cardSortables: [],

    async init() {
        const wrapper = document.getElementById('boardWrapper');
        if (!wrapper) return;

        this.boardId = parseInt(wrapper.dataset.boardId);
        await this.load();
        this.bindEvents();
    },

    async load() {
        try {
            const res = await App.api('boards.get', { id: this.boardId }, 'GET');
            this.data = res.board;
            this.render();
            this.initSortable();
            SSEClient.connect(this.boardId);
        } catch (e) {
            document.getElementById('listsContainer').innerHTML =
                '<div class="empty-state"><p>Failed to load board.</p></div>';
        }
    },

    render() {
        const board = this.data;
        const wrapper = document.getElementById('boardWrapper');

        // Set background
        wrapper.style.backgroundColor = board.background_color;
        wrapper.setAttribute('data-bg', '1');

        // Title
        document.getElementById('boardTitle').textContent = board.title;

        // Members preview
        const preview = document.getElementById('boardMembersPreview');
        preview.innerHTML = board.members.slice(0, 5).map(m => App.avatarHtml(m.display_name, 'sm')).join('');
        if (board.members.length > 5) {
            preview.innerHTML += `<span class="avatar avatar-sm" style="background:#666">+${board.members.length - 5}</span>`;
        }

        // Lists
        this.renderLists();
    },

    renderLists() {
        const container = document.getElementById('listsContainer');
        container.innerHTML = this.data.lists.map(list => this.listHtml(list)).join('');

        // Reinit sortables
        this.initSortable();
    },

    listHtml(list) {
        const cardsHtml = list.cards.map(card => this.cardHtml(card)).join('');

        return `
            <div class="list-column" data-list-id="${list.id}">
                <div class="list-header">
                    <div class="list-title" contenteditable="false" data-list-id="${list.id}">${App.escapeHtml(list.title)}</div>
                    <button class="btn-icon list-menu-btn" data-list-id="${list.id}" title="List actions">&#8943;</button>
                </div>
                <div class="list-cards" data-list-id="${list.id}">
                    ${cardsHtml}
                </div>
                <button class="add-card-btn" data-list-id="${list.id}">+ Add a card</button>
                <div class="add-card-form" data-list-id="${list.id}" style="display:none;">
                    <textarea placeholder="Enter a title for this card..." data-list-id="${list.id}"></textarea>
                    <div class="add-card-actions">
                        <button class="btn btn-primary btn-sm add-card-submit" data-list-id="${list.id}">Add Card</button>
                        <button class="btn-icon add-card-cancel" data-list-id="${list.id}">&times;</button>
                    </div>
                </div>
            </div>
        `;
    },

    cardHtml(card) {
        let badges = '';
        let labelsHtml = '';

        // Labels
        if (card.labels && card.labels.length > 0) {
            labelsHtml = '<div class="card-labels">' +
                card.labels.map(l =>
                    `<span class="card-label" style="background:${l.color}" title="${App.escapeHtml(l.name || '')}">${App.escapeHtml(l.name || '')}</span>`
                ).join('') + '</div>';
        }

        // Due date
        if (card.due_date) {
            const due = App.formatDueDate(card.due_date);
            const cls = card.due_complete ? 'complete' : due.class;
            badges += `<span class="card-badge ${cls}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                ${due.text}</span>`;
        }

        // Description indicator
        if (card.description) {
            badges += `<span class="card-badge" title="Has description">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
            </span>`;
        }

        // Comments
        if (card.comment_count > 0) {
            badges += `<span class="card-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                ${card.comment_count}</span>`;
        }

        // Attachments
        if (card.attachment_count > 0) {
            badges += `<span class="card-badge">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                ${card.attachment_count}</span>`;
        }

        // Checklist progress
        if (card.checklist_progress && card.checklist_progress !== '0/0') {
            const [done, total] = card.checklist_progress.split('/');
            const cls = done === total && total !== '0' ? 'complete' : '';
            badges += `<span class="card-badge ${cls}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                ${done}/${total}</span>`;
        }

        // Assignees
        let assigneesHtml = '';
        if (card.assignees && card.assignees.length > 0) {
            assigneesHtml = '<div class="card-assignees">' +
                card.assignees.slice(0, 3).map(a => App.avatarHtml(a.display_name, 'sm')).join('') +
                '</div>';
        }

        const hasBadges = badges || assigneesHtml;

        return `
            <div class="card-item" data-card-id="${card.id}" data-list-id="${card.list_id}">
                ${labelsHtml}
                <div class="card-title">${App.escapeHtml(card.title)}</div>
                ${hasBadges ? `<div class="card-badges">${badges}${assigneesHtml}</div>` : ''}
            </div>
        `;
    },

    initSortable() {
        // Destroy existing
        if (this.listSortable) this.listSortable.destroy();
        this.cardSortables.forEach(s => s.destroy());
        this.cardSortables = [];

        // List sorting (horizontal)
        const listsContainer = document.getElementById('listsContainer');
        if (listsContainer) {
            this.listSortable = new Sortable(listsContainer, {
                animation: 150,
                handle: '.list-header',
                draggable: '.list-column',
                ghostClass: 'sortable-ghost',
                onEnd: (evt) => this.onListReorder(evt),
            });
        }

        // Card sorting (within and between lists)
        document.querySelectorAll('.list-cards').forEach(el => {
            const s = new Sortable(el, {
                group: 'cards',
                animation: 150,
                draggable: '.card-item',
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd: (evt) => this.onCardMove(evt),
            });
            this.cardSortables.push(s);
        });
    },

    async onListReorder(evt) {
        const columns = document.querySelectorAll('.list-column');
        const positions = Array.from(columns).map(c => parseInt(c.dataset.listId));

        try {
            await App.api('lists.reorder', {
                board_id: this.boardId,
                positions: positions,
            });
        } catch (e) {
            App.showToast('Failed to reorder lists', 'error');
            this.renderLists();
        }
    },

    async onCardMove(evt) {
        const cardId = parseInt(evt.item.dataset.cardId);
        const targetListId = parseInt(evt.to.dataset.listId);

        // Get new position based on surrounding cards
        const cards = Array.from(evt.to.querySelectorAll('.card-item'));
        const cardIds = cards.map(c => parseInt(c.dataset.cardId));
        const newIndex = cardIds.indexOf(cardId);

        // Calculate position
        let position;
        if (cards.length === 1) {
            position = POSITION_GAP;
        } else if (newIndex === 0) {
            const nextCard = this.findCardData(cardIds[1]);
            position = nextCard ? Math.floor(nextCard.position / 2) : POSITION_GAP;
        } else if (newIndex === cards.length - 1) {
            const prevCard = this.findCardData(cardIds[newIndex - 1]);
            position = prevCard ? prevCard.position + POSITION_GAP : (newIndex + 1) * POSITION_GAP;
        } else {
            const prevCard = this.findCardData(cardIds[newIndex - 1]);
            const nextCard = this.findCardData(cardIds[newIndex + 1]);
            if (prevCard && nextCard) {
                position = Math.floor((prevCard.position + nextCard.position) / 2);
            } else {
                position = (newIndex + 1) * POSITION_GAP;
            }
        }

        // Update local data
        this.updateCardInData(cardId, targetListId, position);

        try {
            await App.api('cards.move', {
                card_id: cardId,
                target_list_id: targetListId,
                position: position,
            });
        } catch (e) {
            App.showToast('Failed to move card', 'error');
            this.renderLists();
        }
    },

    findCardData(cardId) {
        cardId = parseInt(cardId);
        for (const list of this.data.lists) {
            const card = list.cards.find(c => parseInt(c.id) === cardId);
            if (card) return card;
        }
        return null;
    },

    updateCardInData(cardId, newListId, newPosition) {
        cardId = parseInt(cardId);
        newListId = parseInt(newListId);
        // Remove from old list
        for (const list of this.data.lists) {
            const idx = list.cards.findIndex(c => parseInt(c.id) === cardId);
            if (idx !== -1) {
                const [card] = list.cards.splice(idx, 1);
                card.list_id = newListId;
                card.position = newPosition;
                // Add to new list
                const targetList = this.data.lists.find(l => parseInt(l.id) === newListId);
                if (targetList) {
                    targetList.cards.push(card);
                    targetList.cards.sort((a, b) => a.position - b.position);
                }
                break;
            }
        }
    },

    bindEvents() {
        const container = document.getElementById('boardWrapper');

        // Add list
        document.getElementById('addListBtn')?.addEventListener('click', () => {
            document.getElementById('addListBtn').style.display = 'none';
            document.getElementById('addListForm').style.display = 'block';
            document.getElementById('newListTitle').focus();
        });

        document.getElementById('cancelNewList')?.addEventListener('click', () => {
            document.getElementById('addListBtn').style.display = 'block';
            document.getElementById('addListForm').style.display = 'none';
            document.getElementById('newListTitle').value = '';
        });

        document.getElementById('submitNewList')?.addEventListener('click', () => this.addList());
        document.getElementById('newListTitle')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); this.addList(); }
            if (e.key === 'Escape') document.getElementById('cancelNewList').click();
        });

        // Delegated events on board container
        container.addEventListener('click', (e) => {
            // Card click -> open modal
            const cardEl = e.target.closest('.card-item');
            if (cardEl && !e.target.closest('.card-label')) {
                CardModal.open(parseInt(cardEl.dataset.cardId));
                return;
            }

            // Add card button
            const addCardBtn = e.target.closest('.add-card-btn');
            if (addCardBtn) {
                const listId = addCardBtn.dataset.listId;
                addCardBtn.style.display = 'none';
                const form = document.querySelector(`.add-card-form[data-list-id="${listId}"]`);
                form.style.display = 'block';
                form.querySelector('textarea').focus();
                return;
            }

            // Cancel add card
            const cancelBtn = e.target.closest('.add-card-cancel');
            if (cancelBtn) {
                const listId = cancelBtn.dataset.listId;
                document.querySelector(`.add-card-btn[data-list-id="${listId}"]`).style.display = 'block';
                const form = document.querySelector(`.add-card-form[data-list-id="${listId}"]`);
                form.style.display = 'none';
                form.querySelector('textarea').value = '';
                return;
            }

            // Submit add card
            const submitBtn = e.target.closest('.add-card-submit');
            if (submitBtn) {
                this.addCard(parseInt(submitBtn.dataset.listId));
                return;
            }

            // List menu
            const menuBtn = e.target.closest('.list-menu-btn');
            if (menuBtn) {
                this.showListMenu(parseInt(menuBtn.dataset.listId), menuBtn);
                return;
            }

            // Close any context menus (unless clicking board menu or list menu buttons)
            if (!e.target.closest('#boardMenuBtn') && !e.target.closest('.list-menu-btn')) {
                document.querySelectorAll('.context-menu').forEach(m => m.remove());
            }
        });

        // Add card on Enter
        container.addEventListener('keydown', (e) => {
            if (e.target.matches('.add-card-form textarea') && e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.addCard(parseInt(e.target.dataset.listId));
            }
            if (e.target.matches('.add-card-form textarea') && e.key === 'Escape') {
                const listId = e.target.dataset.listId;
                document.querySelector(`.add-card-btn[data-list-id="${listId}"]`).style.display = 'block';
                const form = document.querySelector(`.add-card-form[data-list-id="${listId}"]`);
                form.style.display = 'none';
                form.querySelector('textarea').value = '';
            }
        });

        // List title editing
        container.addEventListener('dblclick', (e) => {
            const titleEl = e.target.closest('.list-title');
            if (titleEl) {
                titleEl.contentEditable = 'true';
                titleEl.focus();
                // Select all text
                const range = document.createRange();
                range.selectNodeContents(titleEl);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });

        container.addEventListener('blur', (e) => {
            if (e.target.matches('.list-title')) {
                e.target.contentEditable = 'false';
                const listId = parseInt(e.target.dataset.listId);
                const title = e.target.textContent.trim();
                if (title) {
                    App.api('lists.update', { id: listId, title }).catch(() => {});
                }
            }
        }, true);

        container.addEventListener('keydown', (e) => {
            if (e.target.matches('.list-title') && e.key === 'Enter') {
                e.preventDefault();
                e.target.blur();
            }
        });

        // Board title editing
        const boardTitle = document.getElementById('boardTitle');
        boardTitle?.addEventListener('dblclick', () => {
            boardTitle.contentEditable = 'true';
            boardTitle.classList.add('editing');
            boardTitle.focus();
        });

        boardTitle?.addEventListener('blur', () => {
            boardTitle.contentEditable = 'false';
            boardTitle.classList.remove('editing');
            const title = boardTitle.textContent.trim();
            if (title && title !== this.data.title) {
                App.api('boards.update', { id: this.boardId, title }).catch(() => {});
                this.data.title = title;
            }
        });

        boardTitle?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); boardTitle.blur(); }
        });

        // Manage members
        document.getElementById('manageMembersBtn')?.addEventListener('click', () => this.showMembersModal());

        // Board menu
        document.getElementById('boardMenuBtn')?.addEventListener('click', () => this.showBoardMenu());

        // Close context menus on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.context-menu') && !e.target.closest('.list-menu-btn') && !e.target.closest('#boardMenuBtn')) {
                document.querySelectorAll('.context-menu').forEach(m => m.remove());
            }
        });
    },

    async addList() {
        const input = document.getElementById('newListTitle');
        const title = input.value.trim();
        if (!title) return;

        try {
            const res = await App.api('lists.create', {
                board_id: this.boardId,
                title: title,
            });
            if (res.success) {
                this.data.lists.push(res.list);
                this.renderLists();
                input.value = '';
                input.focus();
            }
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async addCard(listId) {
        const textarea = document.querySelector(`.add-card-form[data-list-id="${listId}"] textarea`);
        const title = textarea.value.trim();
        if (!title) return;

        try {
            const res = await App.api('cards.create', {
                list_id: listId,
                title: title,
            });
            if (res.success) {
                const list = this.data.lists.find(l => l.id === listId);
                if (list) {
                    list.cards.push(res.card);
                }
                // Re-render just this list's cards
                const cardsContainer = document.querySelector(`.list-cards[data-list-id="${listId}"]`);
                cardsContainer.innerHTML = list.cards.map(c => this.cardHtml(c)).join('');
                this.initSortable();
                textarea.value = '';
                textarea.focus();
            }
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    showListMenu(listId, anchor) {
        document.querySelectorAll('.context-menu').forEach(m => m.remove());

        const menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.innerHTML = `
            <button class="context-menu-item" data-action="archive">Archive List</button>
        `;
        menu.style.top = (anchor.offsetTop + anchor.offsetHeight + 4) + 'px';
        menu.style.right = '8px';

        anchor.closest('.list-column').appendChild(menu);

        menu.querySelector('[data-action="archive"]').addEventListener('click', async () => {
            try {
                await App.api('lists.archive', { id: listId });
                this.data.lists = this.data.lists.filter(l => l.id !== listId);
                this.renderLists();
                App.showToast('List archived', 'info');
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });
    },

    async showMembersModal() {
        try {
            const usersRes = await App.api('users.list', {}, 'GET');
            const allUsers = usersRes.users || [];
            const currentMemberIds = this.data.members.map(m => m.id);

            let membersHtml = this.data.members.map(m => `
                <div class="member-list-item">
                    ${App.avatarHtml(m.display_name)}
                    <div class="member-info">
                        <div class="member-name">${App.escapeHtml(m.display_name)}</div>
                        <div class="member-email">${App.escapeHtml(m.email)}</div>
                    </div>
                    <button class="btn btn-sm btn-danger remove-member-btn" data-user-id="${m.id}">Remove</button>
                </div>
            `).join('');

            const nonMembers = allUsers.filter(u => !currentMemberIds.includes(u.id));
            let addHtml = '';
            if (nonMembers.length > 0) {
                addHtml = `
                    <div style="margin-top:16px;border-top:1px solid var(--color-border);padding-top:16px;">
                        <label style="font-weight:600;margin-bottom:8px;display:block;">Add Member</label>
                        <select id="addMemberSelect" style="width:100%;padding:8px;border:2px solid var(--color-border);border-radius:4px;">
                            <option value="">Select a user...</option>
                            ${nonMembers.map(u => `<option value="${u.id}">${App.escapeHtml(u.display_name)} (${App.escapeHtml(u.email)})</option>`).join('')}
                        </select>
                        <button class="btn btn-primary btn-sm" id="addMemberBtn" style="margin-top:8px;">Add to Board</button>
                    </div>
                `;
            }

            const modal = App.createModal('membersModal', 'Board Members', membersHtml + addHtml);

            // Add member
            modal.querySelector('#addMemberBtn')?.addEventListener('click', async () => {
                const userId = parseInt(modal.querySelector('#addMemberSelect').value);
                if (!userId) return;
                try {
                    await App.api('boards.add_member', { board_id: this.boardId, user_id: userId });
                    App.showToast('Member added', 'success');
                    modal.remove();
                    await this.load();
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });

            // Remove member
            modal.querySelectorAll('.remove-member-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const userId = parseInt(btn.dataset.userId);
                    try {
                        await App.api('boards.remove_member', { board_id: this.boardId, user_id: userId });
                        App.showToast('Member removed', 'info');
                        modal.remove();
                        await this.load();
                    } catch (e) {
                        App.showToast(e.message, 'error');
                    }
                });
            });
        } catch (e) {
            App.showToast('Failed to load members', 'error');
        }
    },

    showBoardMenu() {
        document.querySelectorAll('.context-menu').forEach(m => m.remove());

        const btn = document.getElementById('boardMenuBtn');
        const menu = document.createElement('div');
        menu.className = 'context-menu';
        menu.style.position = 'fixed';
        const rect = btn.getBoundingClientRect();
        menu.style.top = (rect.bottom + 4) + 'px';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        menu.innerHTML = `
            <button class="context-menu-item" data-action="labels">Edit Labels</button>
            <button class="context-menu-item" data-action="description">Edit Description</button>
            <button class="context-menu-item" data-action="background">Change Background</button>
            <button class="context-menu-item danger" data-action="archive">Archive Board</button>
        `;
        document.body.appendChild(menu);

        menu.querySelector('[data-action="labels"]').addEventListener('click', () => {
            menu.remove();
            this.showLabelsEditor();
        });

        menu.querySelector('[data-action="description"]').addEventListener('click', () => {
            menu.remove();
            this.showEditDescriptionModal();
        });

        menu.querySelector('[data-action="background"]').addEventListener('click', () => {
            menu.remove();
            this.showBackgroundPicker();
        });

        menu.querySelector('[data-action="archive"]').addEventListener('click', () => {
            menu.remove();
            this.archiveBoard();
        });
    },

    showEditDescriptionModal() {
        const modal = App.createModal('boardDescModal', 'Board Description', `
            <div class="form-group">
                <label>Description</label>
                <textarea id="boardDescInput" rows="4" style="width:100%;padding:8px 12px;border:2px solid var(--color-border);border-radius:4px;font-size:14px;font-family:var(--font-family);resize:vertical;">${App.escapeHtml(this.data.description || '')}</textarea>
            </div>
        `, `<button class="btn btn-primary" id="saveBoardDesc">Save</button>`);

        document.getElementById('boardDescInput').focus();

        document.getElementById('saveBoardDesc').addEventListener('click', async () => {
            const desc = document.getElementById('boardDescInput').value.trim();
            try {
                await App.api('boards.update', { id: this.boardId, description: desc });
                this.data.description = desc;
                App.showToast('Description updated', 'success');
                modal.remove();
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });
    },

    showBackgroundPicker() {
        const colors = ['#0079BF','#D29034','#519839','#B04632','#89609E','#CD5A91','#4BBF6B','#00AECC','#838C91'];
        const colorOpts = colors.map(c =>
            `<div class="bg-color-option ${c === this.data.background_color ? 'selected' : ''}" data-color="${c}" style="background:${c}"></div>`
        ).join('');

        const modal = App.createModal('boardBgModal', 'Board Background', `
            <div class="bg-color-grid">${colorOpts}</div>
            <style>
                .bg-color-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
                .bg-color-option { height: 56px; border-radius: 8px; cursor: pointer; transition: transform 0.1s, box-shadow 0.1s; position: relative; }
                .bg-color-option:hover { transform: scale(1.05); }
                .bg-color-option.selected::after { content: '\\2713'; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; font-weight: bold; }
            </style>
        `);

        modal.querySelectorAll('.bg-color-option').forEach(el => {
            el.addEventListener('click', async () => {
                const color = el.dataset.color;
                try {
                    await App.api('boards.update', { id: this.boardId, background_color: color });
                    this.data.background_color = color;
                    document.getElementById('boardWrapper').style.backgroundColor = color;
                    modal.querySelectorAll('.bg-color-option').forEach(o => o.classList.remove('selected'));
                    el.classList.add('selected');
                    App.showToast('Background updated', 'success');
                    modal.remove();
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });
        });
    },

    async archiveBoard() {
        if (!confirm('Archive this board? It will be hidden from the dashboard.')) return;
        try {
            await App.api('boards.archive', { id: this.boardId });
            App.showToast('Board archived', 'info');
            window.location.href = 'index.php?page=dashboard';
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    showLabelsEditor() {
        const PRESET_COLORS = [
            '#61BD4F','#F2D600','#FF9F1A','#EB5A46','#C377E0',
            '#0079BF','#00C2E0','#51E898','#FF78CB','#344563',
        ];

        const renderLabelList = () => {
            return (this.data.labels || []).map(l => `
                <div class="label-editor-item" data-label-id="${l.id}">
                    <span class="label-editor-swatch" style="background:${l.color}"></span>
                    <input type="text" class="label-editor-name" value="${App.escapeHtml(l.name || '')}" placeholder="Label name (optional)" data-label-id="${l.id}">
                    <button class="label-editor-color-btn" data-label-id="${l.id}" data-color="${l.color}" title="Change color">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2z"/></svg>
                    </button>
                    <button class="label-editor-delete" data-label-id="${l.id}" title="Delete label">&times;</button>
                </div>
            `).join('');
        };

        const modal = App.createModal('labelsEditorModal', 'Edit Labels', `
            <style>
                .label-editor-item { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid var(--color-bg); }
                .label-editor-item:last-child { border-bottom:none; }
                .label-editor-swatch { width:32px; height:24px; border-radius:4px; flex-shrink:0; }
                .label-editor-name { flex:1; padding:6px 8px; border:1px solid var(--color-border); border-radius:4px; font-size:13px; font-family:var(--font-family); }
                .label-editor-name:focus { border-color:var(--color-primary); outline:none; }
                .label-editor-color-btn { background:none; border:none; cursor:pointer; padding:4px; color:var(--color-text-light); border-radius:4px; }
                .label-editor-color-btn:hover { background:var(--color-bg); }
                .label-editor-delete { background:none; border:none; cursor:pointer; font-size:18px; color:var(--color-text-light); padding:4px 6px; border-radius:4px; }
                .label-editor-delete:hover { background:#FFF4F4; color:var(--color-danger); }
                .color-palette { display:flex; flex-wrap:wrap; gap:6px; padding:8px 0; }
                .color-palette-swatch { width:28px; height:28px; border-radius:4px; cursor:pointer; border:2px solid transparent; transition:transform 0.1s; }
                .color-palette-swatch:hover { transform:scale(1.15); }
                .color-palette-swatch.selected { border-color:var(--color-text); }
                .label-editor-add { display:flex; align-items:center; gap:8px; margin-top:12px; padding-top:12px; border-top:1px solid var(--color-border); }
                .label-editor-add input { flex:1; padding:6px 8px; border:2px solid var(--color-border); border-radius:4px; font-size:13px; }
                .label-editor-add input:focus { border-color:var(--color-primary); outline:none; }
            </style>
            <div id="labelEditorList">${renderLabelList()}</div>
            <div class="label-editor-add">
                <input type="text" id="newLabelName" placeholder="New label name...">
                <div id="newLabelColor" class="label-editor-swatch" style="background:#0079BF;cursor:pointer;" title="Pick color"></div>
                <button class="btn btn-primary btn-sm" id="addLabelBtn">Add</button>
            </div>
            <div id="newLabelPalette" style="display:none;">
                <div class="color-palette">
                    ${PRESET_COLORS.map(c => `<div class="color-palette-swatch" data-color="${c}" style="background:${c}"></div>`).join('')}
                </div>
            </div>
        `);

        let newLabelColorValue = '#0079BF';

        // New label color picker toggle
        document.getElementById('newLabelColor').addEventListener('click', () => {
            const palette = document.getElementById('newLabelPalette');
            palette.style.display = palette.style.display === 'none' ? 'block' : 'none';
        });

        document.getElementById('newLabelPalette').addEventListener('click', (e) => {
            const swatch = e.target.closest('.color-palette-swatch');
            if (!swatch) return;
            newLabelColorValue = swatch.dataset.color;
            document.getElementById('newLabelColor').style.background = newLabelColorValue;
            document.getElementById('newLabelPalette').style.display = 'none';
        });

        // Add label
        document.getElementById('addLabelBtn').addEventListener('click', async () => {
            const name = document.getElementById('newLabelName').value.trim();
            try {
                const res = await App.api('labels.create', {
                    board_id: this.boardId,
                    name: name || null,
                    color: newLabelColorValue,
                });
                if (res.success) {
                    this.data.labels.push({ id: res.label_id, name: name || null, color: newLabelColorValue });
                    document.getElementById('labelEditorList').innerHTML = renderLabelList();
                    document.getElementById('newLabelName').value = '';
                    this.bindLabelEditorEvents(modal, PRESET_COLORS);
                }
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });

        document.getElementById('newLabelName').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); document.getElementById('addLabelBtn').click(); }
        });

        this.bindLabelEditorEvents(modal, PRESET_COLORS);
    },

    bindLabelEditorEvents(modal, presetColors) {
        const listEl = document.getElementById('labelEditorList');

        // Name blur → save
        listEl.querySelectorAll('.label-editor-name').forEach(input => {
            input.addEventListener('blur', async () => {
                const labelId = parseInt(input.dataset.labelId);
                const label = this.data.labels.find(l => l.id === labelId);
                const newName = input.value.trim();
                if (label && newName !== (label.name || '')) {
                    try {
                        await App.api('labels.update', { id: labelId, name: newName });
                        label.name = newName || null;
                    } catch (e) {
                        App.showToast(e.message, 'error');
                    }
                }
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            });
        });

        // Color button → show inline palette
        listEl.querySelectorAll('.label-editor-color-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const labelId = parseInt(btn.dataset.labelId);
                const item = btn.closest('.label-editor-item');
                const existing = item.querySelector('.color-palette');
                if (existing) { existing.remove(); return; }

                // Remove any other open palettes
                listEl.querySelectorAll('.color-palette').forEach(p => p.remove());

                const palette = document.createElement('div');
                palette.className = 'color-palette';
                palette.style.padding = '8px 0 4px 40px';
                palette.innerHTML = presetColors.map(c =>
                    `<div class="color-palette-swatch ${c === btn.dataset.color ? 'selected' : ''}" data-color="${c}" style="background:${c}"></div>`
                ).join('');
                item.after(palette);

                palette.addEventListener('click', async (e) => {
                    const swatch = e.target.closest('.color-palette-swatch');
                    if (!swatch) return;
                    const newColor = swatch.dataset.color;
                    try {
                        await App.api('labels.update', { id: labelId, color: newColor });
                        const label = this.data.labels.find(l => l.id === labelId);
                        if (label) label.color = newColor;
                        btn.dataset.color = newColor;
                        item.querySelector('.label-editor-swatch').style.background = newColor;
                        palette.remove();
                    } catch (e2) {
                        App.showToast(e2.message, 'error');
                    }
                });
            });
        });

        // Delete
        listEl.querySelectorAll('.label-editor-delete').forEach(btn => {
            btn.addEventListener('click', async () => {
                const labelId = parseInt(btn.dataset.labelId);
                try {
                    await App.api('labels.delete', { id: labelId });
                    this.data.labels = this.data.labels.filter(l => l.id !== labelId);
                    btn.closest('.label-editor-item').remove();
                    // Also remove any palette that was open for this label
                    const nextPalette = listEl.querySelector(`.color-palette`);
                    if (nextPalette) nextPalette.remove();
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });
        });
    },

    // SSE event handlers — coerce IDs to int since SSE JSON may deliver strings
    handleCardCreated(data) {
        const cardId = parseInt(data.card.id);
        const listId = parseInt(data.card.list_id);
        const list = this.data.lists.find(l => parseInt(l.id) === listId);
        if (list && !list.cards.find(c => parseInt(c.id) === cardId)) {
            data.card.id = cardId;
            data.card.list_id = listId;
            list.cards.push(data.card);
            this.renderLists();
        }
    },

    handleCardUpdated(data) {
        const cardId = parseInt(data.card?.id || data.card_id);
        if (!cardId) return;
        for (const list of this.data.lists) {
            const idx = list.cards.findIndex(c => parseInt(c.id) === cardId);
            if (idx !== -1) {
                if (data.card) Object.assign(list.cards[idx], data.card);
                this.renderLists();
                break;
            }
        }
    },

    handleCardMoved(data) {
        const cardId = parseInt(data.card_id);
        const targetListId = parseInt(data.target_list_id);
        const position = parseInt(data.position);

        // Skip if card is already in the target list at roughly the right position
        const targetList = this.data.lists.find(l => parseInt(l.id) === targetListId);
        const alreadyThere = targetList?.cards.find(c => parseInt(c.id) === cardId && parseInt(c.list_id) === targetListId);
        if (alreadyThere) return;

        this.updateCardInData(cardId, targetListId, position);
        this.renderLists();
    },

    handleCardArchived(data) {
        const cardId = parseInt(data.card_id);
        for (const list of this.data.lists) {
            list.cards = list.cards.filter(c => parseInt(c.id) !== cardId);
        }
        this.renderLists();
    },

    handleListCreated(data) {
        const listId = parseInt(data.list.id);
        if (!this.data.lists.find(l => parseInt(l.id) === listId)) {
            this.data.lists.push(data.list);
            this.renderLists();
        }
    },

    handleListUpdated(data) {
        const list = this.data.lists.find(l => l.id === data.list_id);
        if (list) {
            list.title = data.title;
            const titleEl = document.querySelector(`.list-title[data-list-id="${data.list_id}"]`);
            if (titleEl) titleEl.textContent = data.title;
        }
    },

    handleListReordered(data) {
        const posMap = {};
        data.positions.forEach((id, i) => posMap[id] = (i + 1) * 65536);
        this.data.lists.forEach(l => {
            if (posMap[l.id] !== undefined) l.position = posMap[l.id];
        });
        this.data.lists.sort((a, b) => a.position - b.position);
        this.renderLists();
    },

    handleListArchived(data) {
        this.data.lists = this.data.lists.filter(l => l.id !== data.list_id);
        this.renderLists();
    },
};

const POSITION_GAP = 65536;

// Initialize on load
document.addEventListener('DOMContentLoaded', () => Board.init());
