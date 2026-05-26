/**
 * BravoCollab - Dedicated search page
 *
 * Live-updates results as the user types (debounced), groups by source kind
 * (Card title, Description, Checklist item, Comment) and highlights every
 * occurrence of the query inside snippets. Submitting the form also pushes
 * ?q=… into the URL so results are shareable / deep-linkable.
 */
(function () {
    const page = document.getElementById('searchPage');
    if (!page) return;

    const input = document.getElementById('searchPageInput');
    const form = document.getElementById('searchPageForm');
    const resultsEl = document.getElementById('searchPageResults');
    const statusEl = document.getElementById('searchPageStatus');
    if (!input || !form || !resultsEl) return;

    const esc = (s) => App.escapeHtml(s);

    const highlight = (text, q) => {
        const e = esc(text || '');
        if (!q) return e;
        const escQ = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        try {
            return e.replace(new RegExp('(' + escQ + ')', 'gi'), '<mark>$1</mark>');
        } catch (err) {
            return e;
        }
    };

    const KIND_TITLE = {
        title:       'Card titles',
        description: 'Card descriptions',
        checklist:   'Checklist items',
        comment:     'Comments',
    };

    const renderGroup = (kind, rows, q) => {
        if (!rows.length) return '';
        const html = rows.map(r => {
            const url = `index.php?page=board&id=${r.board_id}&card=${r.card_id}`;
            const author = r.author_name ? ` &middot; <em>${esc(r.author_name)}</em>` : '';
            return `
                <a class="search-result" href="${url}">
                    <div class="search-result-title">${esc(r.card_title)}</div>
                    <div class="search-result-snippet">${highlight(r.snippet, q)}</div>
                    <div class="search-result-meta">${esc(r.board_title || '')}${author}</div>
                </a>
            `;
        }).join('');
        return `
            <div class="search-page-group">
                <h2 class="search-page-group-title">${KIND_TITLE[kind] || kind} (${rows.length})</h2>
                ${html}
            </div>
        `;
    };

    const render = (data) => {
        const q = data.query || '';
        const results = data.results || [];
        if (q.length < 2) {
            resultsEl.innerHTML = '';
            statusEl.textContent = 'Type at least 2 characters to search.';
            return;
        }
        if (!results.length) {
            resultsEl.innerHTML = `<div class="search-empty">No matches found for "${esc(q)}".</div>`;
            statusEl.textContent = `0 results for "${q}"`;
            return;
        }

        const grouped = { title: [], description: [], checklist: [], comment: [] };
        results.forEach(r => {
            if (grouped[r.kind]) grouped[r.kind].push(r);
        });

        const html = ['title', 'description', 'checklist', 'comment']
            .map(k => renderGroup(k, grouped[k], q))
            .join('');

        resultsEl.innerHTML = html;
        statusEl.textContent = `${results.length} result${results.length === 1 ? '' : 's'} for "${q}"`;
    };

    let lastQuery = null;
    const run = async (q) => {
        if (q === lastQuery) return;
        lastQuery = q;
        if (!q || q.length < 2) {
            render({ query: q, results: [] });
            return;
        }
        statusEl.textContent = 'Searching…';
        try {
            const res = await App.api('search.full', { q }, 'GET');
            // Drop stale responses if the user kept typing.
            if (input.value.trim() !== q) return;
            render(res);
        } catch (e) {
            statusEl.textContent = 'Search failed.';
        }
    };

    const debouncedRun = App.debounce((q) => run(q), 200);

    input.addEventListener('input', () => {
        const q = input.value.trim();
        debouncedRun(q);
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = input.value.trim();
        // Update the URL so the page is shareable / refresh-stable.
        const url = new URL(window.location);
        if (q) url.searchParams.set('q', q);
        else url.searchParams.delete('q');
        history.replaceState(null, '', url);
        run(q);
    });

    // Trigger an initial search if the page loaded with ?q=…
    const initial = page.dataset.initialQuery || input.value.trim();
    if (initial && initial.length >= 2) {
        run(initial);
    } else {
        render({ query: initial, results: [] });
    }
})();
