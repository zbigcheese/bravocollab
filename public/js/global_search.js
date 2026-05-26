/**
 * BravoCollab - Global search (header input + live dropdown)
 *
 * Behavior:
 *  - Typing: debounced calls to search.quick → render top-N dropdown.
 *  - Enter / submit: navigate to the dedicated search page with ?q=.
 *  - Click a dropdown result: navigate to the board+card (and item/comment
 *    anchor when meaningful).
 *  - Keyboard: ArrowDown/Up to traverse, Enter on a selection to open, Esc
 *    to close.
 */
(function () {
    const input = document.getElementById('globalSearchInput');
    const form = document.getElementById('globalSearchForm');
    const dropdown = document.getElementById('globalSearchDropdown');
    if (!input || !form || !dropdown) return;

    let lastQuery = '';
    let selectedIdx = -1;
    let currentResults = [];

    const close = () => {
        dropdown.classList.remove('open');
        dropdown.innerHTML = '';
        selectedIdx = -1;
        currentResults = [];
    };

    const escapeHtml = (s) => App.escapeHtml(s);

    // Highlight every case-insensitive occurrence of $q inside $text. Returns
    // an HTML string that's already escaped (so callers can drop it straight
    // into innerHTML). Empty queries pass through unchanged.
    const highlight = (text, q) => {
        const esc = escapeHtml(text || '');
        if (!q) return esc;
        const escQ = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        try {
            return esc.replace(
                new RegExp('(' + escQ + ')', 'gi'),
                '<mark>$1</mark>'
            );
        } catch (e) {
            return esc;
        }
    };

    const kindLabel = (kind) => {
        switch (kind) {
            case 'title':       return 'Card title';
            case 'description': return 'Description';
            case 'checklist':   return 'Checklist item';
            case 'comment':     return 'Comment';
            default:            return kind;
        }
    };

    const urlFor = (r) => {
        let url = `index.php?page=board&id=${r.board_id}&card=${r.card_id}`;
        return url;
    };

    const render = (results, q) => {
        if (!results.length) {
            dropdown.innerHTML = `
                <div class="global-search-empty">No matches for "${escapeHtml(q)}"</div>
                <div class="global-search-footer">
                    <a href="index.php?page=search&q=${encodeURIComponent(q)}">Open full search…</a>
                </div>
            `;
            dropdown.classList.add('open');
            currentResults = [];
            selectedIdx = -1;
            return;
        }

        currentResults = results;
        selectedIdx = 0;

        const rows = results.map((r, i) => `
            <a class="global-search-item${i === 0 ? ' selected' : ''}"
               href="${urlFor(r)}"
               data-idx="${i}">
                <div class="global-search-item-title">${escapeHtml(r.card_title)}</div>
                <div class="global-search-item-snippet">${highlight(r.snippet, q)}</div>
                <div class="global-search-item-meta">
                    <span class="global-search-kind">${kindLabel(r.kind)}</span>
                    <span class="global-search-board">${escapeHtml(r.board_title || '')}</span>
                </div>
            </a>
        `).join('');

        dropdown.innerHTML = rows + `
            <div class="global-search-footer">
                <a href="index.php?page=search&q=${encodeURIComponent(q)}">See all results →</a>
            </div>
        `;
        dropdown.classList.add('open');
    };

    const paintSelection = () => {
        dropdown.querySelectorAll('.global-search-item').forEach((el, i) => {
            el.classList.toggle('selected', i === selectedIdx);
        });
    };

    const runQuery = App.debounce(async (q) => {
        if (q.length < 2) {
            close();
            return;
        }
        if (q === lastQuery) return;
        lastQuery = q;
        try {
            const res = await App.api('search.quick', { q }, 'GET');
            // Stale-response guard: if the user kept typing, drop this batch.
            if (input.value.trim() !== q) return;
            render(res.results || [], q);
        } catch (e) {
            close();
        }
    }, 180);

    input.addEventListener('input', () => {
        const q = input.value.trim();
        if (q.length < 2) {
            close();
            return;
        }
        runQuery(q);
    });

    input.addEventListener('focus', () => {
        const q = input.value.trim();
        if (q.length >= 2) runQuery(q);
    });

    input.addEventListener('keydown', (e) => {
        const open = dropdown.classList.contains('open');
        if (!open) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedIdx = Math.min(currentResults.length - 1, selectedIdx + 1);
            paintSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedIdx = Math.max(0, selectedIdx - 1);
            paintSelection();
        } else if (e.key === 'Escape') {
            close();
            input.blur();
        } else if (e.key === 'Enter') {
            if (selectedIdx >= 0 && currentResults[selectedIdx]) {
                e.preventDefault();
                window.location.href = urlFor(currentResults[selectedIdx]);
            }
            // else falls through to form submit
        }
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = input.value.trim();
        if (q.length < 2) return;
        window.location.href = 'index.php?page=search&q=' + encodeURIComponent(q);
    });

    // Click outside → close dropdown.
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#globalSearch')) close();
    });

    // Cmd/Ctrl + K focuses the search input — small productivity tweak.
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
            e.preventDefault();
            input.focus();
            input.select();
        }
    });
})();
