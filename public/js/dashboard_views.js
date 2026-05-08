/**
 * DashboardViews — combined calendar + timeline overview at the bottom of
 * the dashboard. Reuses CSS classes from board_views.css (cal-cell,
 * cal-card, cal-task, tl-bar, etc.) so it inherits the visual treatment
 * and severity-colour rules without redefining them.
 *
 * Mobile: weekly only (matches the board calendar's mobile behaviour).
 * Desktop: weekly or monthly via the dropdown above the pane.
 *
 * Click on any entry navigates to the board with the card auto-opened
 * (board.js reads ?card=ID on load and pops the modal).
 */
const DashboardViews = {
    cards: [],
    items: [],
    currentView: 'calendar',  // 'calendar' | 'timeline'
    range: 'month',           // 'week' | 'month'  (forced to 'week' on mobile)
    monthAnchor: null,        // first of displayed month
    weekAnchor: null,         // Monday of displayed week

    LS_VIEW:  'dashCal:view',
    LS_RANGE: 'dashCal:range',

    async init() {
        const section = document.getElementById('dashboardCalendarSection');
        if (!section) return;

        const now = new Date();
        this.monthAnchor = new Date(now.getFullYear(), now.getMonth(), 1);
        this.weekAnchor  = this._startOfWeek(now);

        try {
            const v = localStorage.getItem(this.LS_VIEW);
            if (v === 'calendar' || v === 'timeline') this.currentView = v;
            const r = localStorage.getItem(this.LS_RANGE);
            if (r === 'week' || r === 'month') this.range = r;
        } catch (e) { /* ignore storage errors */ }

        // Mobile is always weekly. Hide the range dropdown there.
        const rangeSel = document.getElementById('dashCalRange');
        if (this._isMobile()) {
            this.range = 'week';
            if (rangeSel) rangeSel.style.display = 'none';
        }

        if (rangeSel) {
            rangeSel.value = this.range;
            rangeSel.addEventListener('change', () => {
                this.range = rangeSel.value;
                try { localStorage.setItem(this.LS_RANGE, this.range); } catch (e) {}
                this.refresh();
            });
        }

        section.querySelectorAll('.dashboard-view-switcher-btn').forEach(btn => {
            btn.addEventListener('click', () => this.switchTo(btn.dataset.view));
        });

        // React to viewport changes (rotation / resize) — switch to weekly on mobile.
        window.addEventListener('resize', () => {
            const wasMobile = (this.range === 'week' && rangeSel?.style.display === 'none');
            const isMobile  = this._isMobile();
            if (isMobile && !wasMobile) {
                this.range = 'week';
                if (rangeSel) rangeSel.style.display = 'none';
                this.refresh();
            } else if (!isMobile && wasMobile) {
                if (rangeSel) {
                    rangeSel.style.display = '';
                    try { this.range = localStorage.getItem(this.LS_RANGE) || 'month'; } catch (e) {}
                    rangeSel.value = this.range;
                }
                this.refresh();
            }
        });

        section.hidden = false;
        this.applyViewState();
        await this.loadData();
        this.refresh();
    },

    async loadData() {
        try {
            const res = await App.api('boards.calendar_data', {}, 'GET');
            this.cards = res.cards || [];
            this.items = res.items || [];
        } catch (e) {
            this.cards = [];
            this.items = [];
        }
    },

    switchTo(view) {
        if (view !== 'calendar' && view !== 'timeline') view = 'calendar';
        this.currentView = view;
        try { localStorage.setItem(this.LS_VIEW, view); } catch (e) {}
        this.applyViewState();
        this.refresh();
    },

    applyViewState() {
        document.querySelectorAll('.dashboard-view-switcher-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.view === this.currentView);
        });
        const calPane = document.getElementById('dashboardCalendarPane');
        const tlPane  = document.getElementById('dashboardTimelinePane');
        if (calPane) calPane.hidden = this.currentView !== 'calendar';
        if (tlPane)  tlPane.hidden  = this.currentView !== 'timeline';
    },

    refresh() {
        if (this._isMobile()) this.range = 'week';
        if (this.currentView === 'calendar') {
            this.range === 'week' ? this._renderCalendarWeek() : this._renderCalendarMonth();
        } else {
            this.range === 'week' ? this._renderTimelineWeek() : this._renderTimelineMonth();
        }
    },

    // ---- helpers ----
    _isMobile() {
        return window.matchMedia('(max-width: 768px)').matches;
    },

    _dateKey(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
    },

    _startOfWeek(date) {
        const d = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const offset = (d.getDay() + 6) % 7;  // Monday-start
        d.setDate(d.getDate() - offset);
        return d;
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

    _cardUrl(c) {
        let u = `index.php?page=board&id=${c.board_id}`;
        if (c.id)    u += `&card=${c.id}`;
        return u;
    },
    _itemUrl(it) {
        return `index.php?page=board&id=${it.board_id}&card=${it.card_id}`;
    },

    _calCardStateCls(card, todayKey) {
        if (card.due_complete == 1 || card.is_archived == 1) return ' cal-card-done';
        const k = card.due_date ? card.due_date.substring(0, 10) : '';
        if (!k) return '';
        if (k <  todayKey) return ' cal-card-overdue';
        if (k === todayKey) return ' cal-card-today';
        return '';
    },

    _calTaskStateCls(item, todayKey) {
        if (item.is_checked == 1 || item.card_archived == 1) return ' cal-task-done';
        const k = item.due_date ? item.due_date.substring(0, 10) : '';
        if (!k) return '';
        if (k <  todayKey) return ' cal-task-overdue';
        if (k === todayKey) return ' cal-task-today';
        return '';
    },

    _renderCalCard(c, todayKey, hidden) {
        const stateCls = this._calCardStateCls(c, todayKey);
        const hiddenCls = hidden ? ' cal-card-hidden' : '';
        const color = c.board_color || '#5bc0de';
        const title = `${c.title}\n— ${c.board_title || ''}`;
        return `
            <a class="cal-card${stateCls}${hiddenCls}" href="${this._cardUrl(c)}" style="border-left-color:${App.escapeHtml(color)};display:block;text-decoration:none;color:inherit;" title="${App.escapeHtml(title)}">
                ${App.escapeHtml(c.title)}
            </a>
        `;
    },

    _renderCalTask(it, todayKey, hidden) {
        const stateCls = this._calTaskStateCls(it, todayKey);
        const hiddenCls = hidden ? ' cal-card-hidden' : '';
        const t = `${it.content}\n— ${it.card_title || ''} · ${it.board_title || ''}`;
        return `
            <a class="cal-task${stateCls}${hiddenCls}" href="${this._itemUrl(it)}" style="text-decoration:none;color:inherit;" title="${App.escapeHtml(t)}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                <span>${App.escapeHtml(it.content)}</span>
            </a>
        `;
    },

    _bucketByDate() {
        const cardsByDate = {};
        for (const c of this.cards) {
            if (!c.due_date) continue;
            const k = c.due_date.substring(0, 10);
            (cardsByDate[k] = cardsByDate[k] || []).push(c);
        }
        const itemsByDate = {};
        for (const it of this.items) {
            if (!it.due_date) continue;
            const k = it.due_date.substring(0, 10);
            (itemsByDate[k] = itemsByDate[k] || []).push(it);
        }
        return { cardsByDate, itemsByDate };
    },

    // ------------------------- Calendar -------------------------
    _renderCalendarMonth() {
        const pane = document.getElementById('dashboardCalendarPane');
        if (!pane) return;

        const m = this.monthAnchor;
        const monthName = m.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        const firstDay = new Date(m.getFullYear(), m.getMonth(), 1);
        const lastDay  = new Date(m.getFullYear(), m.getMonth() + 1, 0);
        const daysInMonth = lastDay.getDate();
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

        const { cardsByDate, itemsByDate } = this._bucketByDate();
        const todayKey = this._dateKey(new Date());
        const dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="prev" aria-label="Previous month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
                <div class="view-title">${monthName}</div>
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="next" aria-label="Next month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg></button>
                <button class="btn btn-sm btn-secondary" data-nav="today">Today</button>
            </div>
            <div class="calendar-grid">
                ${dayNames.map(n => `<div class="cal-head">${n}</div>`).join('')}
                ${cells.map(cell => {
                    const k = this._dateKey(cell.date);
                    const cards = cardsByDate[k] || [];
                    const items = itemsByDate[k] || [];
                    const total = cards.length + items.length;
                    const collapsed = total > 4;
                    let idx = 0;
                    const parts = [];
                    for (const c of cards)  { parts.push(this._renderCalCard(c, todayKey, collapsed && idx >= 3)); idx++; }
                    for (const it of items) { parts.push(this._renderCalTask(it, todayKey, collapsed && idx >= 3)); idx++; }
                    const toggle = collapsed
                        ? `<button type="button" class="cal-toggle" data-hidden-count="${total - 3}">+ ${total - 3} more</button>`
                        : '';
                    const weekdayShort = cell.date.toLocaleDateString(undefined, { weekday: 'short' });
                    return `
                        <div class="cal-cell ${cell.outside ? 'cal-outside' : ''} ${k === todayKey ? 'cal-today' : ''} ${collapsed ? 'cal-collapsed' : ''}">
                            <div class="cal-cell-weekday">${weekdayShort}</div>
                            <div class="cal-date">${cell.date.getDate()}</div>
                            <div class="cal-cards">${parts.join('')}${toggle}</div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
        this._wireMonthNav(pane);
        this._wireToggles(pane);
    },

    _renderCalendarWeek() {
        const pane = document.getElementById('dashboardCalendarPane');
        if (!pane) return;

        const ws = this.weekAnchor;
        const days = Array.from({ length: 7 }, (_, i) =>
            new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + i));

        const { cardsByDate, itemsByDate } = this._bucketByDate();
        const todayKey = this._dateKey(new Date());
        const title = this._formatWeekRange(ws);

        const cellsHtml = days.map(d => {
            const k = this._dateKey(d);
            const cards = cardsByDate[k] || [];
            const items = itemsByDate[k] || [];
            const total = cards.length + items.length;
            const collapsed = total > 4;
            let idx = 0;
            const parts = [];
            for (const c of cards)  { parts.push(this._renderCalCard(c, todayKey, collapsed && idx >= 3)); idx++; }
            for (const it of items) { parts.push(this._renderCalTask(it, todayKey, collapsed && idx >= 3)); idx++; }
            const toggle = collapsed
                ? `<button type="button" class="cal-toggle" data-hidden-count="${total - 3}">+ ${total - 3} more</button>`
                : '';
            const weekdayShort = d.toLocaleDateString(undefined, { weekday: 'short' });
            return `
                <div class="cal-cell ${k === todayKey ? 'cal-today' : ''} ${collapsed ? 'cal-collapsed' : ''}">
                    <div class="cal-cell-weekday">${weekdayShort}</div>
                    <div class="cal-date">${d.getDate()}</div>
                    <div class="cal-cards">${parts.join('')}${toggle}</div>
                </div>
            `;
        }).join('');

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="prev" aria-label="Previous week"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
                <div class="view-title">${title}</div>
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="next" aria-label="Next week"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg></button>
                <button class="btn btn-sm btn-secondary" data-nav="today">This week</button>
            </div>
            <div class="calendar-grid calendar-week">${cellsHtml}</div>
        `;
        this._wireWeekNav(pane);
        this._wireToggles(pane);

        // Center "today" if visible (matches the board view's behaviour).
        const todayCell = pane.querySelector('.cal-today');
        const grid = pane.querySelector('.calendar-grid');
        if (todayCell && grid) {
            const offset = todayCell.offsetLeft - (grid.clientWidth / 2) + (todayCell.clientWidth / 2);
            grid.scrollLeft = Math.max(0, offset);
        }
    },

    // ------------------------- Timeline -------------------------
    _visibleForTimeline(rangeStart, rangeEnd) {
        const msPerDay = 86400000;
        const visible = this.cards.filter(c => c.due_date || c.start_date).filter(c => {
            const startStr = c.start_date || (c.due_date ? c.due_date.substring(0, 10) : null);
            const endStr   = c.due_date ? c.due_date.substring(0, 10) : startStr;
            if (!startStr || !endStr) return false;
            const s = new Date(startStr);
            const e = new Date(endStr);
            return !(e < rangeStart || s > rangeEnd);
        }).sort((a, b) => {
            const as = a.start_date || (a.due_date ? a.due_date.substring(0, 10) : '');
            const bs = b.start_date || (b.due_date ? b.due_date.substring(0, 10) : '');
            return as.localeCompare(bs) || (a.title || '').localeCompare(b.title || '');
        });
        return { visible, msPerDay };
    },

    _renderTimelineMonth() {
        const pane = document.getElementById('dashboardTimelinePane');
        if (!pane) return;
        const m = this.monthAnchor;
        const monthName = m.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
        const firstDay = new Date(m.getFullYear(), m.getMonth(), 1);
        const lastDay  = new Date(m.getFullYear(), m.getMonth() + 1, 0);
        const daysInMonth = lastDay.getDate();
        const todayKey = this._dateKey(new Date());

        const { visible, msPerDay } = this._visibleForTimeline(firstDay, lastDay);

        const headCells = Array.from({ length: daysInMonth }, (_, i) => {
            const date = new Date(m.getFullYear(), m.getMonth(), i + 1);
            const k = this._dateKey(date);
            const dow = date.getDay();
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
            const color = card.board_color || '#5bc0de';
            return `
                <div class="tl-row">
                    <div class="tl-row-label" title="${App.escapeHtml(card.title)} — ${App.escapeHtml(card.board_title || '')}">${App.escapeHtml(card.title)}</div>
                    <div class="tl-row-track">
                        <a class="tl-bar${done}" href="${this._cardUrl(card)}" style="left:${left}%;width:${width}%;background:${App.escapeHtml(color)};text-decoration:none;color:inherit;">
                            <span class="tl-bar-title">${App.escapeHtml(card.title)}</span>
                        </a>
                    </div>
                </div>
            `;
        }).join('');

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="prev" aria-label="Previous month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
                <div class="view-title">${monthName}</div>
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="next" aria-label="Next month"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg></button>
                <button class="btn btn-sm btn-secondary" data-nav="today">Today</button>
            </div>
            <div class="tl-wrap" style="--tl-days:${daysInMonth};">
                <div class="tl-head-row"><div class="tl-head-label">Cards</div><div class="tl-head-cells">${headCells}</div></div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">No cards with dates in this month.</div>'
                        : rowsHtml}
                </div>
            </div>
        `;
        this._wireMonthNav(pane);
    },

    _renderTimelineWeek() {
        const pane = document.getElementById('dashboardTimelinePane');
        if (!pane) return;
        const ws = this.weekAnchor;
        const we = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 6);
        const daysInWeek = 7;
        const todayKey = this._dateKey(new Date());

        const { visible, msPerDay } = this._visibleForTimeline(ws, we);

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
            const color = card.board_color || '#5bc0de';
            return `
                <div class="tl-row">
                    <div class="tl-row-label" title="${App.escapeHtml(card.title)} — ${App.escapeHtml(card.board_title || '')}">${App.escapeHtml(card.title)}</div>
                    <div class="tl-row-track">
                        <a class="tl-bar${done}" href="${this._cardUrl(card)}" style="left:${left}%;width:${width}%;background:${App.escapeHtml(color)};text-decoration:none;color:inherit;">
                            <span class="tl-bar-title">${App.escapeHtml(card.title)}</span>
                        </a>
                    </div>
                </div>
            `;
        }).join('');

        const title = this._formatWeekRange(ws);

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="prev" aria-label="Previous week"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
                <div class="view-title">${title}</div>
                <button class="btn btn-sm btn-secondary nav-arrow-btn" data-nav="next" aria-label="Next week"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg></button>
                <button class="btn btn-sm btn-secondary" data-nav="today">This week</button>
            </div>
            <div class="tl-wrap" style="--tl-days:${daysInWeek};">
                <div class="tl-head-row"><div class="tl-head-label">Cards</div><div class="tl-head-cells">${headCells}</div></div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">No cards with dates in this week.</div>'
                        : rowsHtml}
                </div>
            </div>
        `;
        this._wireWeekNav(pane);
    },

    _wireMonthNav(pane) {
        const m = this.monthAnchor;
        pane.querySelector('[data-nav="prev"]')?.addEventListener('click', () => {
            this.monthAnchor = new Date(m.getFullYear(), m.getMonth() - 1, 1);
            this.refresh();
        });
        pane.querySelector('[data-nav="next"]')?.addEventListener('click', () => {
            this.monthAnchor = new Date(m.getFullYear(), m.getMonth() + 1, 1);
            this.refresh();
        });
        pane.querySelector('[data-nav="today"]')?.addEventListener('click', () => {
            const now = new Date();
            this.monthAnchor = new Date(now.getFullYear(), now.getMonth(), 1);
            this.refresh();
        });
    },

    _wireWeekNav(pane) {
        const ws = this.weekAnchor;
        pane.querySelector('[data-nav="prev"]')?.addEventListener('click', () => {
            this.weekAnchor = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() - 7);
            this.refresh();
        });
        pane.querySelector('[data-nav="next"]')?.addEventListener('click', () => {
            this.weekAnchor = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 7);
            this.refresh();
        });
        pane.querySelector('[data-nav="today"]')?.addEventListener('click', () => {
            this.weekAnchor = this._startOfWeek(new Date());
            this.refresh();
        });
    },

    _wireToggles(pane) {
        pane.querySelectorAll('.cal-toggle').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault(); e.stopPropagation();
                const cell = btn.closest('.cal-cell');
                const expanded = cell.classList.toggle('cal-expanded');
                const hidden = btn.dataset.hiddenCount;
                btn.textContent = expanded ? '− Show less' : `+ ${hidden} more`;
            });
        });
    },
};

document.addEventListener('DOMContentLoaded', () => DashboardViews.init());
