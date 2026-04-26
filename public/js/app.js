/**
 * BravoCollab - Global Utilities
 */
const App = {
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
    userId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0'),

    async api(action, data = {}, method = 'POST') {
        const opts = {
            method,
            headers: {
                'X-CSRF-Token': this.csrfToken,
            },
        };

        let url = `api.php?action=${action}`;

        if (method === 'GET') {
            const params = new URLSearchParams(data).toString();
            if (params) url += '&' + params;
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }

        const res = await fetch(url, opts);

        if (res.status === 401) {
            window.location.href = 'index.php?page=login';
            return null;
        }

        const json = await res.json();

        if (!res.ok && json.error) {
            throw new Error(json.error);
        }

        return json;
    },

    async upload(action, formData) {
        const res = await fetch(`api.php?action=${action}`, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': this.csrfToken,
            },
            body: formData,
        });

        if (res.status === 401) {
            window.location.href = 'index.php?page=login';
            return null;
        }

        return res.json();
    },

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        const diff = now - d;
        const mins = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);

        if (mins < 1) return 'just now';
        if (mins < 60) return `${mins}m ago`;
        if (hours < 24) return `${hours}h ago`;
        if (days < 7) return `${days}d ago`;

        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    },

    formatDueDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        now.setHours(0, 0, 0, 0);
        const due = new Date(d);
        due.setHours(0, 0, 0, 0);
        const diff = Math.ceil((due - now) / 86400000);

        const formatted = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

        if (diff < 0) return { text: formatted, class: 'overdue' };
        if (diff === 0) return { text: 'Today', class: 'due-today' };
        if (diff === 1) return { text: 'Tomorrow', class: 'due-soon' };
        if (diff <= 3) return { text: formatted, class: 'due-soon' };
        return { text: formatted, class: '' };
    },

    showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('removing');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },

    getInitials(name) {
        if (!name) return '?';
        return name.split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);
    },

    avatarHtml(name, size = '') {
        const cls = size ? `avatar avatar-${size}` : 'avatar';
        const colors = ['#0079BF', '#61BD4F', '#EB5A46', '#FF9F1A', '#C377E0', '#00C2E0', '#344563'];
        const hash = name.split('').reduce((acc, c) => acc + c.charCodeAt(0), 0);
        const bg = colors[hash % colors.length];
        return `<span class="${cls}" style="background:${bg}" title="${this.escapeHtml(name)}">${this.escapeHtml(this.getInitials(name))}</span>`;
    },

    // Simple modal helper
    createModal(id, title, content, footer = '') {
        let modal = document.getElementById(id);
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = id;
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal">
                <div class="modal-header">
                    <h2>${this.escapeHtml(title)}</h2>
                    <button class="modal-close" data-close>&times;</button>
                </div>
                <div class="modal-body">${content}</div>
                ${footer ? `<div class="modal-footer">${footer}</div>` : ''}
            </div>
        `;

        document.body.appendChild(modal);

        // Close handlers
        modal.querySelector('[data-close]').addEventListener('click', () => modal.remove());
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });

        return modal;
    },

    closeModal(id) {
        document.getElementById(id)?.remove();
    },

    // Debounce helper
    debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    },
};
