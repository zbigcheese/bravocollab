/**
 * BravoCollab - Calendar and Timeline views + view switcher.
 * Depends on Board (data source) and CardModal (for opening cards).
 */
const BoardViews = {
    currentView: 'board',
    calendarMonth: null,     // desktop: 1st of displayed month
    timelineMonth: null,
    calendarWeekStart: null, // mobile: Monday of displayed week
    timelineWeekStart: null,

    init() {
        if (!document.getElementById('viewSwitcher')) return;

        const now = new Date();
        this.calendarMonth     = new Date(now.getFullYear(), now.getMonth(), 1);
        this.timelineMonth     = new Date(now.getFullYear(), now.getMonth(), 1);
        this.calendarWeekStart = this._startOfWeek(now);
        this.timelineWeekStart = this._startOfWeek(now);

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

    _allDueItems() {
        return Array.isArray(Board.data?.due_items) ? Board.data.due_items : [];
    },

    // Style for an inline calendar task entry. Same priority logic as cards:
    //   completed or on archived card -> dim + strikethrough (no severity)
    //   overdue -> red + bold
    //   due today -> orange + bold
    _calTaskStateCls(item, todayKey) {
        if (item.is_checked == 1 || item.card_archived == 1) return ' cal-task-done';
        const dueKey = item.due_date ? item.due_date.substring(0, 10) : '';
        if (!dueKey) return '';
        if (dueKey < todayKey) return ' cal-task-overdue';
        if (dueKey === todayKey) return ' cal-task-today';
        return '';
    },

    _renderCalTask(item, todayKey, hidden) {
        const stateCls = this._calTaskStateCls(item, todayKey);
        const hiddenCls = hidden ? ' cal-card-hidden' : '';
        return `
            <div class="cal-task${stateCls}${hiddenCls}" data-card-id="${item.card_id}" title="${App.escapeHtml(item.content)} — ${App.escapeHtml(item.card_title)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <span>${App.escapeHtml(item.content)}</span>
            </div>
        `;
    },

    _labelColor(card) {
        return (card.labels && card.labels[0] && card.labels[0].color) ? card.labels[0].color : '#5bc0de';
    },

    // Returns the CSS class suffix (with leading space) describing a calendar
    // card's completion/due state. Order of priority:
    //   complete or archived -> dimmed + strikethrough (no severity flag)
    //   overdue (past)       -> red bg, white text
    //   due today            -> orange bg
    // Archived cards are intentionally treated like done: an old card that
    // never got a complete check shouldn't keep screaming red/orange.
    _calCardStateCls(card, todayKey) {
        if (card.due_complete == 1 || card.is_archived == 1) return ' cal-card-done';
        const dueKey = card.due_date ? card.due_date.substring(0, 10) : '';
        if (!dueKey) return '';
        if (dueKey < todayKey) return ' cal-card-overdue';
        if (dueKey === todayKey) return ' cal-card-today';
        return '';
    },

    _dateKey(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    },

    _startOfWeek(date) {
        // Monday-start week, normalized to midnight.
        const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const offset = (d.getDay() + 6) % 7; // days since Monday
        d.setDate(d.getDate() - offset);
        return d;
    },

    _isMobileView() {
        return window.matchMedia('(max-width: 768px)').matches;
    },

    _formatWeekRange(start) {
        const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + 6);
        const monthShort = (d) => d.toLocaleDateString(undefined, { month: 'short' });
        if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()) {
            return `${monthShort(start)} ${start.getDate()} – ${end.getDate()}, ${start.getFullYear()}`;
        }
        if (start.getFullYear() === end.getFullYear()) {
            return `${monthShort(start)} ${start.getDate()} – ${monthShort(end)} ${end.getDate()}, ${start.getFullYear()}`;
        }
        return `${monthShort(start)} ${start.getDate()}, ${start.getFullYear()} – ${monthShort(end)} ${end.getDate()}, ${end.getFullYear()}`;
    },

    // ------------------------- Calendar -------------------------
    renderCalendar() {
        if (this._isMobileView()) this._renderCalendarWeek();
        else this._renderCalendarMonth();
    },

    _renderCalendarWeek() {
        const pane = document.getElementById('calendarPane');
        const ws = this.calendarWeekStart;
        const days = Array.from({ length: 7 }, (_, i) =>
            new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + i));

        const cardsByDate = {};
        for (const card of this._allCards()) {
            if (!card.due_date) continue;
            const k = card.due_date.substring(0, 10);
            (cardsByDate[k] = cardsByDate[k] || []).push(card);
        }
        const itemsByDate = {};
        for (const item of this._allDueItems()) {
            const k = item.due_date.substring(0, 10);
            (itemsByDate[k] = itemsByDate[k] || []).push(item);
        }
        const todayKey = this._dateKey(new Date());
        const title = this._formatWeekRange(ws);

        const cellsHtml = days.map(d => {
            const k = this._dateKey(d);
            const cards = cardsByDate[k] || [];
            const items = itemsByDate[k] || [];
            const total = cards.length + items.length;
            const collapsed = total > 4;
            // Cards first, then tasks. The +N more accordion cuts at index 3
            // across the combined sequence, so items can be hidden too.
            let entryIdx = 0;
            const parts = [];
            for (const c of cards) {
                const stateCls = this._calCardStateCls(c, todayKey);
                const hidden = collapsed && entryIdx >= 3 ? ' cal-card-hidden' : '';
                parts.push(`
                    <div class="cal-card${stateCls}${hidden}" data-card-id="${c.id}" style="border-left-color:${this._labelColor(c)}" title="${App.escapeHtml(c.title)}">
                        ${App.escapeHtml(c.title)}
                    </div>
                `);
                entryIdx++;
            }
            for (const it of items) {
                const hidden = collapsed && entryIdx >= 3;
                parts.push(this._renderCalTask(it, todayKey, hidden));
                entryIdx++;
            }
            const cardsHtml = parts.join('');
            const toggle = collapsed
                ? `<button type="button" class="cal-toggle" data-hidden-count="${total - 3}">+ ${total - 3} more</button>`
                : '';
            const weekdayShort = d.toLocaleDateString(undefined, { weekday: 'short' });
            return `
                <div class="cal-cell ${k === todayKey ? 'cal-today' : ''} ${collapsed ? 'cal-collapsed' : ''}">
                    <div class="cal-cell-weekday">${weekdayShort}</div>
                    <div class="cal-date">${d.getDate()}</div>
                    <div class="cal-cards">
                        ${cardsHtml}
                        ${toggle}
                    </div>
                </div>
            `;
        }).join('');

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary" data-nav="prev" aria-label="Previous week">&lsaquo;</button>
                <div class="view-title">${title}</div>
                <button class="btn btn-sm btn-secondary" data-nav="next" aria-label="Next week">&rsaquo;</button>
                <button class="btn btn-sm btn-secondary" data-nav="today">This week</button>
            </div>
            <div class="calendar-grid calendar-week">${cellsHtml}</div>
        `;

        pane.querySelector('[data-nav="prev"]').addEventListener('click', () => {
            this.calendarWeekStart = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() - 7);
            this._renderCalendarWeek();
        });
        pane.querySelector('[data-nav="next"]').addEventListener('click', () => {
            this.calendarWeekStart = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 7);
            this._renderCalendarWeek();
        });
        pane.querySelector('[data-nav="today"]').addEventListener('click', () => {
            this.calendarWeekStart = this._startOfWeek(new Date());
            this._renderCalendarWeek();
        });
        pane.querySelectorAll('.cal-card, .cal-task').forEach(el => {
            el.addEventListener('click', () => CardModal.open(parseInt(el.dataset.cardId)));
        });
        pane.querySelectorAll('.cal-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const cell = btn.closest('.cal-cell');
                const expanded = cell.classList.toggle('cal-expanded');
                const hidden = btn.dataset.hiddenCount;
                btn.textContent = expanded ? '− Show less' : `+ ${hidden} more`;
            });
        });

        // Center today's cell within the scrollable strip if today is in this week.
        const todayCell = pane.querySelector('.cal-today');
        const grid = pane.querySelector('.calendar-grid');
        if (todayCell && grid) {
            const offset = todayCell.offsetLeft
                - (grid.clientWidth / 2)
                + (todayCell.clientWidth / 2);
            grid.scrollLeft = Math.max(0, offset);
        }
    },

    _renderCalendarMonth() {
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

        // Index cards and due-dated checklist items by yyyy-mm-dd.
        const cardsByDate = {};
        for (const card of this._allCards()) {
            if (!card.due_date) continue;
            const k = card.due_date.substring(0, 10);
            (cardsByDate[k] = cardsByDate[k] || []).push(card);
        }
        const itemsByDate = {};
        for (const item of this._allDueItems()) {
            const k = item.due_date.substring(0, 10);
            (itemsByDate[k] = itemsByDate[k] || []).push(item);
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
                    const cards = cardsByDate[k] || [];
                    const items = itemsByDate[k] || [];
                    const total = cards.length + items.length;
                    // Accordion: when more than 4 entries, show only 3 by default and
                    // reveal the rest behind a "+ N more" toggle. Cards then tasks.
                    const collapsed = total > 4;
                    let entryIdx = 0;
                    const parts = [];
                    for (const c of cards) {
                        const stateCls = this._calCardStateCls(c, todayKey);
                        const hidden = collapsed && entryIdx >= 3 ? ' cal-card-hidden' : '';
                        parts.push(`
                            <div class="cal-card${stateCls}${hidden}" data-card-id="${c.id}" style="border-left-color:${this._labelColor(c)}" title="${App.escapeHtml(c.title)}">
                                ${App.escapeHtml(c.title)}
                            </div>
                        `);
                        entryIdx++;
                    }
                    for (const it of items) {
                        const hidden = collapsed && entryIdx >= 3;
                        parts.push(this._renderCalTask(it, todayKey, hidden));
                        entryIdx++;
                    }
                    const cardsHtml = parts.join('');
                    const toggle = collapsed
                        ? `<button type="button" class="cal-toggle" data-hidden-count="${total - 3}">+ ${total - 3} more</button>`
                        : '';
                    const weekdayShort = cell.date.toLocaleDateString(undefined, { weekday: 'short' });
                    return `
                        <div class="cal-cell ${cell.outside ? 'cal-outside' : ''} ${k === todayKey ? 'cal-today' : ''} ${collapsed ? 'cal-collapsed' : ''}">
                            <div class="cal-cell-weekday">${weekdayShort}</div>
                            <div class="cal-date">${cell.date.getDate()}</div>
                            <div class="cal-cards">
                                ${cardsHtml}
                                ${toggle}
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
        pane.querySelectorAll('.cal-card, .cal-task').forEach(el => {
            el.addEventListener('click', () => CardModal.open(parseInt(el.dataset.cardId)));
        });
        pane.querySelectorAll('.cal-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const cell = btn.closest('.cal-cell');
                const expanded = cell.classList.toggle('cal-expanded');
                const hidden = btn.dataset.hiddenCount;
                btn.textContent = expanded ? '− Show less' : `+ ${hidden} more`;
            });
        });
    },

    // ------------------------- Timeline -------------------------
    renderTimeline() {
        if (this._isMobileView()) this._renderTimelineWeek();
        else this._renderTimelineMonth();
    },

    _renderTimelineMonth() {
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

    _renderTimelineWeek() {
        const pane = document.getElementById('timelinePane');
        const ws = this.timelineWeekStart;
        const we = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 6);
        const daysInWeek = 7;
        const todayKey = this._dateKey(new Date());
        const msPerDay = 86400000;

        // Cards whose date range intersects this week.
        const visible = this._allCards().filter(c => c.due_date || c.start_date).filter(c => {
            const startStr = c.start_date || (c.due_date ? c.due_date.substring(0, 10) : null);
            const endStr   = c.due_date ? c.due_date.substring(0, 10) : startStr;
            if (!startStr || !endStr) return false;
            const s = new Date(startStr);
            const e = new Date(endStr);
            return !(e < ws || s > we);
        }).sort((a, b) => {
            const as = a.start_date || (a.due_date ? a.due_date.substring(0, 10) : '');
            const bs = b.start_date || (b.due_date ? b.due_date.substring(0, 10) : '');
            return as.localeCompare(bs) || (a.title || '').localeCompare(b.title || '');
        });

        const headCells = Array.from({ length: daysInWeek }, (_, i) => {
            const date = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + i);
            const k = this._dateKey(date);
            const dow = date.getDay();
            const weekend = (dow === 0 || dow === 6) ? 'tl-weekend' : '';
            const wd = date.toLocaleDateString(undefined, { weekday: 'short' });
            return `<div class="tl-head-cell ${k === todayKey ? 'tl-today' : ''} ${weekend}">${wd} ${date.getDate()}</div>`;
        }).join('');

        const rowsHtml = visible.map(card => {
            const startStr = card.start_date || card.due_date.substring(0, 10);
            const endStr   = card.due_date ? card.due_date.substring(0, 10) : startStr;
            const start = new Date(startStr);
            const end   = new Date(endStr);
            const spanStartMs = Math.max(start.getTime(), ws.getTime());
            const spanEndMs   = Math.min(end.getTime(),   we.getTime());
            const startDay = Math.round((spanStartMs - ws.getTime()) / msPerDay) + 1;
            const endDay   = Math.max(startDay, Math.round((spanEndMs - ws.getTime()) / msPerDay) + 1);
            const left  = ((startDay - 1) / daysInWeek) * 100;
            const width = ((endDay - startDay + 1) / daysInWeek) * 100;
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

        const title = this._formatWeekRange(ws);

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary" data-nav="prev" aria-label="Previous week">&lsaquo;</button>
                <div class="view-title">${title}</div>
                <button class="btn btn-sm btn-secondary" data-nav="next" aria-label="Next week">&rsaquo;</button>
                <button class="btn btn-sm btn-secondary" data-nav="today">This week</button>
            </div>
            <div class="tl-wrap" style="--tl-days:${daysInWeek};">
                <div class="tl-head-row">
                    <div class="tl-head-label">Cards</div>
                    <div class="tl-head-cells">${headCells}</div>
                </div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">No cards with dates in this week.</div>'
                        : rowsHtml}
                </div>
            </div>
        `;

        pane.querySelector('[data-nav="prev"]').addEventListener('click', () => {
            this.timelineWeekStart = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() - 7);
            this._renderTimelineWeek();
        });
        pane.querySelector('[data-nav="next"]').addEventListener('click', () => {
            this.timelineWeekStart = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 7);
            this._renderTimelineWeek();
        });
        pane.querySelector('[data-nav="today"]').addEventListener('click', () => {
            this.timelineWeekStart = this._startOfWeek(new Date());
            this._renderTimelineWeek();
        });
        pane.querySelectorAll('.tl-bar').forEach(el => {
            el.addEventListener('click', () => CardModal.open(parseInt(el.dataset.cardId)));
        });
    },
};
