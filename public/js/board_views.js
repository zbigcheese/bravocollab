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

    // Due-dated sub-entities of cards — checklist items and tasks/sub-tasks —
    // flattened into one normalized shape so the calendar and timeline can
    // render them with a single code path:
    //   { kind, id, card_id, title, card_title, parent_title, due_date,
    //     done, card_archived }
    // Entries on archived cards are kept on purpose: they render dimmed and
    // struck through rather than vanishing silently.
    _allDueEntries() {
        const out = [];
        for (const it of (Array.isArray(Board.data?.due_items) ? Board.data.due_items : [])) {
            if (!it.due_date) continue;
            out.push({
                kind: 'item',
                id: parseInt(it.id),
                card_id: parseInt(it.card_id),
                title: it.content,
                card_title: it.card_title,
                parent_title: null,
                due_date: it.due_date,
                done: it.is_checked == 1,
                card_archived: it.card_archived == 1,
            });
        }
        for (const t of (Array.isArray(Board.data?.due_tasks) ? Board.data.due_tasks : [])) {
            if (!t.due_date) continue;
            out.push({
                kind: 'task',
                id: parseInt(t.id),
                card_id: parseInt(t.card_id),
                title: t.title,
                card_title: t.card_title,
                // Only sub-tasks carry a parent; used for the tooltip chain.
                parent_title: t.parent_title || null,
                due_date: t.due_date,
                done: t.is_complete == 1,
                card_archived: t.card_archived == 1,
            });
        }
        return out;
    },

    // Style for an inline calendar entry. Same priority logic as cards:
    //   completed or on archived card -> dim + strikethrough (no severity)
    //   overdue -> red + bold
    //   due today -> orange + bold
    _calTaskStateCls(entry, todayKey) {
        if (entry.done || entry.card_archived) return ' cal-task-done';
        const dueKey = entry.due_date ? entry.due_date.substring(0, 10) : '';
        if (!dueKey) return '';
        if (dueKey < todayKey) return ' cal-task-overdue';
        if (dueKey === todayKey) return ' cal-task-today';
        return '';
    },

    // Checkmark for checklist items, checked square for tasks — so the two
    // kinds stay tellable apart at a glance inside a dense calendar cell.
    _entryIcon(kind) {
        return kind === 'task'
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><polyline points="8.5 12 11 14.5 15.5 9.5"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>';
    },

    // "Parent task › Sub-task" for nested tasks, plain title otherwise.
    _entryPath(entry) {
        return entry.parent_title ? `${entry.parent_title} › ${entry.title}` : entry.title;
    },

    _renderCalTask(entry, todayKey, hidden) {
        const stateCls = this._calTaskStateCls(entry, todayKey);
        const hiddenCls = hidden ? ' cal-card-hidden' : '';
        // Tasks deep-link to their own sub-page; checklist items stop at the card.
        const taskAttr = entry.kind === 'task' ? ` data-task-id="${entry.id}"` : '';
        const tip = `${this._entryPath(entry)} — ${entry.card_title}`;
        return `
            <div class="cal-task${stateCls}${hiddenCls}" data-card-id="${entry.card_id}"${taskAttr} title="${App.escapeHtml(tip)}">
                ${this._entryIcon(entry.kind)}
                <span>${App.escapeHtml(entry.title)}</span>
            </div>
        `;
    },

    /**
     * Open whatever a calendar cell entry / timeline bar points at. Cards open
     * the modal; tasks pre-set `?card=` AND `?task=` first so CardModal.open
     * preserves the task param and TaskView.bootstrap lands on the sub-page.
     */
    _openEntryEl(el) {
        const cardId = parseInt(el.dataset.cardId);
        if (!cardId) return;
        const taskId = el.dataset.taskId ? parseInt(el.dataset.taskId) : null;

        const u = new URL(window.location);
        u.searchParams.set('card', cardId);
        if (taskId) u.searchParams.set('task', taskId);
        else        u.searchParams.delete('task');
        history.replaceState(null, '', u);

        CardModal.open(cardId);
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
        for (const entry of this._allDueEntries()) {
            const k = entry.due_date.substring(0, 10);
            (itemsByDate[k] = itemsByDate[k] || []).push(entry);
        }
        const todayKey = this._dateKey(new Date());
        const title = this._formatWeekRange(ws);

        const cellsHtml = days.map(d => {
            const k = this._dateKey(d);
            const cards = cardsByDate[k] || [];
            const items = itemsByDate[k] || [];
            const total = cards.length + items.length;
            const collapsed = total > 4;
            // Cards first, then checklist items / tasks. The +N more accordion
            // cuts at index 3 across the combined sequence, so entries can be
            // hidden too.
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
            el.addEventListener('click', () => this._openEntryEl(el));
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
        for (const entry of this._allDueEntries()) {
            const k = entry.due_date.substring(0, 10);
            (itemsByDate[k] = itemsByDate[k] || []).push(entry);
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
                    // reveal the rest behind a "+ N more" toggle. Cards then
                    // checklist items / tasks.
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
            el.addEventListener('click', () => this._openEntryEl(el));
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

    /**
     * Normalized timeline rows intersecting [rangeStart, rangeEnd]:
     *   - cards      → start_date → due_date span (falls back to a single day)
     *   - tasks      → single-day marker on their due date
     *   - checklist  → same
     * Sorted by start date, then cards before tasks before checklist items,
     * then title — so a card and its own dated sub-entries cluster together.
     */
    _timelineRows(rangeStart, rangeEnd) {
        const rows = [];

        for (const c of this._allCards()) {
            if (!c.due_date && !c.start_date) continue;
            const startStr = c.start_date || (c.due_date ? c.due_date.substring(0, 10) : null);
            const endStr   = c.due_date ? c.due_date.substring(0, 10) : startStr;
            if (!startStr || !endStr) continue;
            rows.push({
                kind: 'card',
                label: c.title,
                tooltip: c.title,
                cardId: parseInt(c.id),
                taskId: null,
                startStr, endStr,
                color: this._labelColor(c),
                done: c.due_complete == 1,
            });
        }

        for (const e of this._allDueEntries()) {
            const day = e.due_date.substring(0, 10);
            rows.push({
                kind: e.kind,
                label: this._entryPath(e),
                tooltip: `${this._entryPath(e)} — ${e.card_title}`,
                cardId: e.card_id,
                taskId: e.kind === 'task' ? e.id : null,
                startStr: day,
                endStr: day,
                // Muted greys rather than label colors — sub-entries should
                // read as secondary to the card bars they hang off.
                color: e.kind === 'task' ? '#6b778c' : '#97a0af',
                done: e.done || e.card_archived,
            });
        }

        const kindRank = { card: 0, task: 1, item: 2 };
        return rows
            .filter(r => {
                const s = new Date(r.startStr);
                const e = new Date(r.endStr);
                return !(e < rangeStart || s > rangeEnd);
            })
            .sort((a, b) =>
                a.startStr.localeCompare(b.startStr)
                || kindRank[a.kind] - kindRank[b.kind]
                || (a.label || '').localeCompare(b.label || '')
            );
    },

    /** One timeline row, positioned as a percentage of the visible range. */
    _timelineRowHtml(row, rangeStartMs, rangeEndMs, totalDays) {
        const msPerDay = 86400000;
        const spanStartMs = Math.max(new Date(row.startStr).getTime(), rangeStartMs);
        const spanEndMs   = Math.min(new Date(row.endStr).getTime(),   rangeEndMs);
        const startDay = Math.round((spanStartMs - rangeStartMs) / msPerDay) + 1;
        const endDay   = Math.max(startDay, Math.round((spanEndMs - rangeStartMs) / msPerDay) + 1);
        const left  = ((startDay - 1) / totalDays) * 100;
        const width = ((endDay - startDay + 1) / totalDays) * 100;

        const done     = row.done ? ' tl-done' : '';
        const subCls   = row.kind === 'card' ? '' : ' tl-row-sub';
        const taskAttr = row.taskId ? ` data-task-id="${row.taskId}"` : '';
        const icon     = row.kind === 'card' ? '' : this._entryIcon(row.kind);

        return `
            <div class="tl-row">
                <div class="tl-row-label${subCls}" title="${App.escapeHtml(row.tooltip)}">
                    ${icon}<span>${App.escapeHtml(row.label)}</span>
                </div>
                <div class="tl-row-track">
                    <div class="tl-bar${done}" data-card-id="${row.cardId}"${taskAttr} style="left:${left}%;width:${width}%;background:${row.color}">
                        <span class="tl-bar-title">${App.escapeHtml(row.label)}</span>
                    </div>
                </div>
            </div>
        `;
    },

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

        // Cards, tasks and checklist items whose dates intersect this month.
        const visible = this._timelineRows(firstDay, lastDay);

        const headCells = Array.from({ length: daysInMonth }, (_, i) => {
            const date = new Date(m.getFullYear(), m.getMonth(), i + 1);
            const k = this._dateKey(date);
            const dow = date.getDay(); // 0=Sun, 6=Sat
            const weekend = (dow === 0 || dow === 6) ? 'tl-weekend' : '';
            return `<div class="tl-head-cell ${k === todayKey ? 'tl-today' : ''} ${weekend}">${i + 1}</div>`;
        }).join('');

        const rowsHtml = visible
            .map(row => this._timelineRowHtml(row, firstDay.getTime(), lastDay.getTime(), daysInMonth))
            .join('');

        pane.innerHTML = `
            <div class="view-toolbar">
                <button class="btn btn-sm btn-secondary" data-nav="prev" aria-label="Previous month">&lsaquo;</button>
                <div class="view-title">${monthName}</div>
                <button class="btn btn-sm btn-secondary" data-nav="next" aria-label="Next month">&rsaquo;</button>
                <button class="btn btn-sm btn-secondary" data-nav="today">Today</button>
            </div>
            <div class="tl-wrap" style="--tl-days:${daysInMonth};">
                <div class="tl-head-row">
                    <div class="tl-head-label">Cards &amp; tasks</div>
                    <div class="tl-head-cells">${headCells}</div>
                </div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">Nothing dated in this month. Set a due date on a card, task or checklist item to see it here.</div>'
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
            el.addEventListener('click', () => this._openEntryEl(el));
        });
    },

    _renderTimelineWeek() {
        const pane = document.getElementById('timelinePane');
        const ws = this.timelineWeekStart;
        const we = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + 6);
        const daysInWeek = 7;
        const todayKey = this._dateKey(new Date());

        // Cards, tasks and checklist items whose dates intersect this week.
        const visible = this._timelineRows(ws, we);

        const headCells = Array.from({ length: daysInWeek }, (_, i) => {
            const date = new Date(ws.getFullYear(), ws.getMonth(), ws.getDate() + i);
            const k = this._dateKey(date);
            const dow = date.getDay();
            const weekend = (dow === 0 || dow === 6) ? 'tl-weekend' : '';
            const wd = date.toLocaleDateString(undefined, { weekday: 'short' });
            return `<div class="tl-head-cell ${k === todayKey ? 'tl-today' : ''} ${weekend}">${wd} ${date.getDate()}</div>`;
        }).join('');

        const rowsHtml = visible
            .map(row => this._timelineRowHtml(row, ws.getTime(), we.getTime(), daysInWeek))
            .join('');

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
                    <div class="tl-head-label">Cards &amp; tasks</div>
                    <div class="tl-head-cells">${headCells}</div>
                </div>
                <div class="tl-body">
                    ${visible.length === 0
                        ? '<div class="tl-empty">Nothing dated in this week.</div>'
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
            el.addEventListener('click', () => this._openEntryEl(el));
        });
    },
};
