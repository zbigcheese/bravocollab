/**
 * BravoCollab - Notifications
 */
const Notifications = {
    pollInterval: null,

    init() {
        this.bindEvents();
        this.loadCount();
        // Poll for notifications on non-board pages (SSE handles it on board pages)
        if (!document.getElementById('boardWrapper')) {
            this.pollInterval = setInterval(() => this.loadCount(), 30000);
        }
    },

    bindEvents() {
        const bell = document.getElementById('notificationBell');
        const dropdown = document.getElementById('notificationDropdown');

        bell?.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = dropdown.classList.contains('open');
            if (isOpen) {
                dropdown.classList.remove('open');
            } else {
                dropdown.classList.add('open');
                this.loadList();
            }
        });

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#notificationBell')) {
                dropdown?.classList.remove('open');
            }
        });

        // Mark all read
        document.getElementById('markAllRead')?.addEventListener('click', async () => {
            await App.api('notifications.mark_all_read', {});
            this.updateBadge(0);
            this.loadList();
        });
    },

    async loadCount() {
        try {
            const res = await App.api('notifications.count', {}, 'GET');
            this.updateBadge(res.count);
        } catch (e) {
            // Silently fail
        }
    },

    updateBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (!badge) return;

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    },

    async loadList() {
        const list = document.getElementById('notificationList');
        if (!list) return;

        try {
            const res = await App.api('notifications.list', {}, 'GET');
            const notifications = res.notifications || [];

            if (notifications.length === 0) {
                list.innerHTML = '<p class="notification-empty">No notifications</p>';
                return;
            }

            list.innerHTML = notifications.map(n => {
                const data = typeof n.data === 'string' ? JSON.parse(n.data) : n.data;
                const text = this.formatNotification(n.type, data);
                const icon = this.getIcon(n.type);

                return `
                    <div class="notification-item ${n.is_read ? '' : 'unread'}" data-notif-id="${n.id}" data-board-id="${data.board_id || ''}" data-card-id="${data.card_id || ''}">
                        <div class="notif-icon">${icon}</div>
                        <div class="notif-content">
                            <div class="notif-text">${text}</div>
                            <div class="notif-time">${App.formatDate(n.created_at)}</div>
                        </div>
                    </div>
                `;
            }).join('');

            // Click to navigate and mark read
            list.querySelectorAll('.notification-item').forEach(el => {
                el.addEventListener('click', async () => {
                    const notifId = el.dataset.notifId;
                    const boardId = el.dataset.boardId;
                    const cardId = el.dataset.cardId;

                    await App.api('notifications.mark_read', { id: parseInt(notifId) });

                    if (boardId) {
                        let url = `index.php?page=board&id=${boardId}`;
                        if (cardId) url += `&card=${cardId}`;

                        // If already on this board, just open the card modal.
                        // `Board` is only defined on the board view, so guard with typeof
                        // otherwise the handler throws on the dashboard and never navigates.
                        const onSameBoard = typeof Board !== 'undefined'
                            && Board.boardId === parseInt(boardId);
                        if (onSameBoard && cardId) {
                            CardModal.open(parseInt(cardId));
                            document.getElementById('notificationDropdown')?.classList.remove('open');
                            el.classList.remove('unread');
                        } else {
                            window.location.href = url;
                        }
                    }
                });
            });

        } catch (e) {
            list.innerHTML = '<p class="notification-empty">Failed to load notifications</p>';
        }
    },

    formatNotification(type, data) {
        const actor = App.escapeHtml(data.actor_name || 'Someone');
        const card = App.escapeHtml(data.card_title || 'a card');
        const board = App.escapeHtml(data.board_title || 'a board');

        switch (type) {
            case 'card_assigned':
                return `<strong>${actor}</strong> assigned you to <strong>${card}</strong>`;
            case 'card_unassigned':
                return `<strong>${actor}</strong> removed you from <strong>${card}</strong>`;
            case 'comment_added':
                return `<strong>${actor}</strong> commented on <strong>${card}</strong>`;
            case 'comment_mention':
                return `<strong>${actor}</strong> mentioned you in <strong>${card}</strong>`;
            case 'due_soon':
                return `<strong>${card}</strong> is due soon`;
            case 'due_overdue':
                return `<strong>${card}</strong> is overdue`;
            case 'board_invited':
                return `<strong>${actor}</strong> added you to <strong>${board}</strong>`;
            default:
                return `New notification`;
        }
    },

    getIcon(type) {
        switch (type) {
            case 'card_assigned':
            case 'card_unassigned':
                return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0079BF" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/></svg>';
            case 'comment_added':
            case 'comment_mention':
                return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0079BF" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
            case 'due_soon':
            case 'due_overdue':
                return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#EB5A46" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            default:
                return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0079BF" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/></svg>';
        }
    },
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => Notifications.init());
