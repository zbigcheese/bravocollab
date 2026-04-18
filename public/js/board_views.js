/**
 * BravoCollab - Calendar and Timeline views + view switcher.
 * Depends on Board (data source) and CardModal (for opening cards).
 */
const BoardViews = {
    currentView: 'board',
    calendarMonth: null, // Date at 1st of displayed month
    timelineMonth: null,

    init() {
        if (!document.getElementById('viewSwitcher')) return;

        const now = new Date();
        this.calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
        this.timelineMonth = new Date(now.getFullYear(), now.getMonth(), 1);

        document.querySelectorAll('.view-switcher-btn').forEach(btn => {
            btn.addEventListener('click', () => this.switchTo(btn.dataset.view));
        });

        const saved = localStorage.getItem('boardView:' + Board.boardId);
        const initial = ['board', 'calendar', 'timeline'].includes(saved) ? saved : 'board';
        this.switchTo(initial);
    },

    switchTo(view) {
        if (!['board', 'calendar', 'timeline'].includes(view)) view = 'board';
        this.currentView = view;
        try { localStorage.setItem('boardView:' + Board.boardId, view); } catch (e) {}

        document.querySelectorAll('.view-switcher-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === view);
            btn.setAttribute('aria-selected', btn.dataset.view === view ? 'true' : 'false');
        });

        const canvas = document.getElementById('boardCanvas');
        const cal    = document.getElementById('calendarPane');
        const tl     = document.getElementById('timelinePane');
        canvas.style.display = view === 'board'    ? ''     : 'none';
        cal.style.display    = view === 'calendar' ? 'flex' : 'none';
        tl.style.display     = view === 'timeline' ? 'flex' : 'none';

        this.refresh();
    },

    refresh() {
        if (!Board.data) return;
        if (this.currentView === 'calendar') this.renderCalendar();
        else if (this.currentView === 'timeline') this.renderTimeline();
    },

    _allCards() {
        const cards = [];
        for (const list of (Board.data?.lists || [])) {
            for (const c of (list.cards || [])) {
                cards.push({ ...c, list_title: list.title });
            }
        }
        return cards;
    },

    _labelColor(card) {
        return (card.labels && card.labels[0] && card.labels[0].color) ? card.labels[0].color : '#5bc0de';
    },

    _dateKey(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    },

    // ------------------------- Calendar -------------------------
    renderCalendar() {
        const pane = document.getElementById('calendarPane');
        const m = this.calendarMonth;
        const monthName = m.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        const firstDay = new Date(m.getFullYear(), m.getMonth(), 1);
        const lastDay  = new Date(m.getFullYear(), m.getMonth() + 1, 0);
        const daysInMonth = lastDay.getDate();

        // Monday-start weeks: JS getDay: 0=Sun..6=Sat → shift so Mon=0
        const lead = (firstDay.getDay() + 6) % 7;
        const prevLastDay = new Date(m.getFullYear(), m.getMonth(), 0).getDate();

        const cells = [];
        for (let i = lead - 1; i >= 0; i--) {
            cells.push({ date: new Date(m.getFullYear(), m.getMonth() - 1, prevLastDay - i), outside: true });
        }
        for (let d = 1; d <= daysInMonth; d++) {
            cells.push({ date: new Date(m.getFullYear(), m.getMonth(), d), outside: false });
        }
        while (cells.length % 7 !== 0) {
            const last = cells[cells.length - 1].date;
            cells.push({ date: new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1), outside: true });
        }

        // Index cards by yyyy-mm-dd of due_date
        const byDate = {};
        for (const card of this._allCards()) {
            if (!card.due_date) continue;
            const k = card.due_date.substring(0, 10);
            (byDate[k] = byDate[k] || []).push(card);
        }

        const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        const todayKey = this._dateKey(new Date());

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary" data-nav="prev" aria-label="Previous month">&lsaquo;</button>
                <div class="view-title">${monthName}</div>
                <button class="btn btn-sm btn-secondary" data-nav="next" aria-label="Next month">&rsaquo;</button>
                <button class="btn btn-sm btn-secondary" data-nav="today">Today</button>
            </div>
            <div class="calendar-grid">
                ${dayNames.map(n => `<div class="cal-head">${n}</div>`).join('')}
                ${cells.map(cell => {
                    const k = this._dateKey(cell.date);
                    const cards = byDate[k] || [];
                    return `
                        <div class="cal-cell ${cell.outside ? 'cal-outside' : ''} ${k === todayKey ? 'cal-today' : ''}">
                            <div class="cal-date">${cell.date.getDate()}</div>
                            <div class="cal-cards">
                                ${cards.map(c => `
                                    <div class="cal-card" data-card-id="${c.id}" style="border-left-color:${this._labelColor(c)}" title="${App.escapeHtml(c.title)}">
                                        ${App.escapeHtml(c.title)}
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        pane.querySelector('[data-nav="prev"]').addEventListener('click', () => {
            this.calendarMonth = new Date(m.getFullYear(), m.getMonth() - 1, 1);
            this.renderCalendar();
        });
        pane.querySelector('[data-nav="next"]').addEventListener('click', () => {
            this.calendarMonth = new Date(m.getFullYear(), m.getMonth() + 1, 1);
            this.renderCalendar();
        });
        pane.querySelector('[data-nav="today"]').addEventListener('click', () => {
            const now = new Date();
            this.calendarMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            this.renderCalendar();
        });
        pane.querySelectorAll('.cal-card').forEach(el => {
            el.addEventListener('click', () => CardModal.open(parseInt(el.dataset.cardId)));
        });
    },

    // ------------------------- Timeline -------------------------
    renderTimeline() {
        const pane = document.getElementById('timelinePane');
        const m = this.timelineMonth;
        const monthName = m.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        const firstDay = new Date(m.getFullYear(), m.getMonth(), 1);
        const lastDay  = new Date(m.getFullYear(), m.getMonth() + 1, 0);
        const daysInMonth = lastDay.getDate();
        const todayKey = this._dateKey(new Date());

        // Pick cards whose date range intersects this month
        const msPerDay = 86400000;
        const visible = this._allCards().filter(c => c.due_date || c.start_date).filter(c => {
            const startStr = c.start_date || (c.due_date ? c.due_date.substring(0, 10) : null);
            const endStr   = c.due_date ? c.due_date.substring(0, 10) : startStr;
            if (!startStr || !endStr) return false;
            const s = new Date(startStr);
            const e = new Date(endStr);
            return !(e < firstDay || s > lastDay);
        }).sort((a, b) => {
            const as = a.start_date || (a.due_date ? a.due_date.substring(0, 10) : '');
            const bs = b.start_date || (b.due_date ? b.due_date.substring(0, 10) : '');
            return as.localeCompare(bs) || (a.title || '').localeCompare(b.title || '');
        });

        const headCells = Array.from({ length: daysInMonth }, (_, i) => {
            const date = new Date(m.getFullYear(), m.getMonth(), i + 1);
            const k = this._dateKey(date);
            const dow = date.getDay(); // 0=Sun, 6=Sat
            const weekend = (dow === 0 || dow === 6) ? 'tl-weekend' : '';
            return `<div class="tl-head-cell ${k === todayKey ? 'tl-today' : ''} ${weekend}">${i + 1}</div>`;
        }).join('');

        const rowsHtml = visible.map(card => {
            const startStr = card.start_date || card.due_date.substring(0, 10);
            const endStr   = card.due_date ? card.due_date.substring(0, 10) : startStr;
            const start = new Date(startStr);
            const end   = new Date(endStr);
            const spanStartMs = Math.max(start.getTime(), firstDay.getTime());
            const spanEndMs   = Math.min(end.getTime(),   lastDay.getTime());
            const startDay = Math.round((spanStartMs - firstDay.getTime()) / msPerDay) + 1;
            const endDay   = Math.max(startDay, Math.round((spanEndMs - firstDay.getTime()) / msPerDay) + 1);
            const left  = ((startDay - 1) / daysInMonth) * 100;
            const width = ((endDay - startDay + 1) / daysInMonth) * 100;
            const done = card.due_complete == 1 ? ' tl-done' : '';
            return `
                <div class="tl-row">
                    <div class="tl-row-label" title="${App.escapeHtml(card.title)}">${App.escapeHtml(card.title)}</div>
                    <div class="tl-row-track">
                        <div class="tl-bar${done}" data-card-id="${card.id}" style="left:${left}%;width:${width}%;background:${this._labelColor(card)}">
                            <span class="tl-bar-title">${App.escapeHtml(card.title)}</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary" data-nav="prev" aria-label="Previous month">&lsaquo;</button>
                <div class="view-title">${monthName}</div>
                <button class="btn btn-sm btn-secondary" data-nav="next" aria-label="Next month">&rsaquo;</button>
                <button class="btn btn-sm btn-secondary" data-nav="today">Today</button>
            </div>
            <div class="tl-wrap" style="--tl-days:${daysInMonth};">
                <div class="tl-head-row">
                    <div class="tl-head-label">Cards</div>
                    <div class="tl-head-cells">${headCells}</div>
                </div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">No cards with dates in this month. Set a due date (and optional start date) on a card to see it here.</div>'
                        : rowsHtml}
                </div>
            </div>
        `;

        pane.querySelector('[data-nav="prev"]').addEventListener('click', () => {
            this.timelineMonth = new Date(m.getFullYear(), m.getMonth() - 1, 1);
            this.renderTimeline();
        });
        pane.querySelector('[data-nav="next"]').addEventListener('click', () => {
            this.timelineMonth = new Date(m.getFullYear(), m.getMonth() + 1, 1);
            this.renderTimeline();
        });
        pane.querySelector('[data-nav="today"]').addEventListener('click', () => {
            const now = new Date();
            this.timelineMonth = new Date(now.getFullYear(), now.getMonth(), 1);
            this.renderTimeline();
        });
        pane.querySelectorAll('.tl-bar').forEach(el => {
            el.addEventListener('click', () => CardModal.open(parseInt(el.dataset.cardId)));
        });
    },
};
