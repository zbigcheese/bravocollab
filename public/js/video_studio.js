/**
 * VideoStudio — per-card "Infographic video" workflow (feature-flagged).
 *
 *   1. Form, prefilled from the card (title, description + comments, due date,
 *      labels, image + data attachments). Everything is editable; required
 *      fields and problems are flagged inline (client + server validation).
 *   2. Data check: the parsed dataset is shown for confirmation (no AI).
 *   3. Generate: one Claude call writes the storyboard (server-side, polled).
 *   4. Review: edit every scene, data reference, chip and ticker. Edits are
 *      re-validated server-side (no AI) and the canvas preview updates live.
 *   5. Render in the browser → MP4 uploaded as a card attachment.
 */
const VideoStudio = {
    cardId: null,
    prefill: null,
    job: null,
    scenes: null,
    meta: null,
    player: null,
    assets: null,
    pollTimer: null,
    saveTimer: null,
    abort: null,

    // ──────────────────────────────────────────────────────── lifecycle ──

    async open(cardId) {
        this.cardId = parseInt(cardId);
        this.job = null;
        this._shell('Infographic video', '<div class="vs-center"><div class="spinner"></div></div>');
        try {
            this.prefill = await App.api('video.prefill', { card_id: this.cardId }, 'GET');
        } catch (e) {
            this._shell('Infographic video', `<div class="vs-center vs-error-box">${App.escapeHtml(e.message)}</div>`);
            return;
        }
        if ((this.prefill.jobs || []).length) this.showJobs();
        else this.showForm(this.prefill.form);
    },

    close() {
        clearTimeout(this.pollTimer);
        clearTimeout(this.saveTimer);
        if (this.abort) this.abort.abort();
        if (this.player) this.player.destroy();
        this.player = null;
        document.getElementById('videoStudio')?.remove();
        document.removeEventListener('keydown', this._onKey);
    },

    onSSE(data) {
        if (!document.getElementById('videoStudio') || parseInt(data.card_id) !== this.cardId) return;
        if (!this.job && document.querySelector('.vs-jobs')) this.open(this.cardId);
    },

    _onKey(e) {
        if (e.key === 'Escape' && !document.querySelector('#videoStudio .vs-busy')) VideoStudio.close();
    },

    _shell(title, body, footer = '') {
        let el = document.getElementById('videoStudio');
        if (!el) {
            el = document.createElement('div');
            el.id = 'videoStudio';
            el.className = 'vs-overlay';
            document.body.appendChild(el);
            document.addEventListener('keydown', this._onKey);
        }
        el.innerHTML = `
            <div class="vs-panel" role="dialog" aria-label="${App.escapeHtml(title)}">
                <div class="vs-header">
                    <h2>${App.escapeHtml(title)}</h2>
                    <button class="modal-close" data-vs-close aria-label="Close">&times;</button>
                </div>
                <div class="vs-body">${body}</div>
                ${footer ? `<div class="vs-footer">${footer}</div>` : ''}
            </div>`;
        el.querySelector('[data-vs-close]').addEventListener('click', () => this.close());
        return el;
    },

    // ───────────────────────────────────────────────────────────── jobs ──

    showJobs() {
        const statusLabel = { draft: 'Draft', storyboarding: 'Generating…', review: 'In review', rendering: 'Rendering', done: 'Rendered', failed: 'Failed' };
        const rows = this.prefill.jobs.map((j) => `
            <div class="vs-job">
                <div>
                    <strong>${App.escapeHtml(j.topic || 'Untitled')}</strong>
                    <span class="vs-status vs-status-${App.escapeHtml(j.status)}">${statusLabel[j.status] || j.status}</span>
                    <div class="text-muted text-sm">${App.escapeHtml(j.user_name)} · ${App.formatDateTime(j.updated_at)}</div>
                </div>
                <div class="vs-job-actions">
                    ${j.output_attachment_id ? `<a class="btn btn-sm btn-secondary" href="api.php?action=attachments.download&id=${parseInt(j.output_attachment_id)}">Download</a>` : ''}
                    <button class="btn btn-sm btn-primary" data-open-job="${parseInt(j.id)}">Open</button>
                </div>
            </div>`).join('');
        this._shell('Infographic video', `<div class="vs-jobs">${rows}</div>`,
            `<button class="btn btn-primary" id="vsNewJob">+ New video from this card</button>`);
        document.getElementById('vsNewJob').addEventListener('click', () => this.showForm(this.prefill.form));
        document.querySelectorAll('[data-open-job]').forEach((b) => b.addEventListener('click', () => this.loadJob(parseInt(b.dataset.openJob))));
    },

    async loadJob(id) {
        try {
            const res = await App.api('video.get', { id }, 'GET');
            this.job = res.job;
            const st = this.job.status;
            if (st === 'storyboarding') return this.showGenerating();
            if (this.job.storyboard && (this.job.storyboard.scenes || []).length) return this.showReview();
            this.showForm(this.job.form || this.prefill.form);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ───────────────────────────────────────────────────────────── form ──

    showForm(form, errors = {}, warnings = {}) {
        const f = form || {};
        const p = this.prefill;
        const ai = p.ai || {};
        const esc = App.escapeHtml;
        const langOpts = Object.entries(p.languages || {}).map(([k, v]) => `<option value="${esc(k)}" ${f.language === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
        const dataOpts = (p.data_files || []).map((a) => `<option value="${a.id}" ${parseInt(f.data_attachment_id) === a.id ? 'selected' : ''}>${esc(a.original_name)}</option>`).join('');
        const sel = new Set((f.footage_ids || []).map(Number));
        const thumbs = (p.images || []).map((a) => `
            <label class="vs-thumb">
                <input type="checkbox" name="footage" value="${a.id}" ${sel.has(a.id) ? 'checked' : ''}>
                <img src="api.php?action=attachments.download&id=${a.id}&thumb=1" alt="${esc(a.original_name)}" loading="lazy">
            </label>`).join('');
        const hasData = (p.data_files || []).length > 0;

        const body = `
            ${!ai.configured ? '<div class="vs-banner vs-banner-error">The Anthropic API key is not configured on the server yet (config/config.php → <code>anthropic_api_key</code>). You can prepare the form, but generation will fail.</div>' : ''}
            <p class="text-muted text-sm vs-intro">Prefilled from the card. Change anything. <span class="vs-req">*</span> = required.</p>
            <form id="vsForm" class="vs-form" novalidate>
                ${this._field('topic', 'Topic / headline idea', `<input type="text" name="topic" maxlength="120" value="${esc(f.topic)}">`, true)}
                ${this._field('brief', 'Notes for the storyboard (context, facts, quotes)', `<textarea name="brief" rows="6" maxlength="6000">${esc(f.brief)}</textarea><div class="vs-hint"><span data-count="brief"></span>/6000 · shorter notes = cheaper AI call</div>`)}
                <div class="vs-row">
                    ${this._field('language', 'Language', `<select name="language">${langOpts}</select>`, true)}
                    ${this._field('target_seconds', 'Length (seconds)', `<input type="number" name="target_seconds" min="15" max="60" step="5" value="${parseInt(f.target_seconds) || 30}">`, true)}
                </div>
                <fieldset class="vs-fieldset">
                    <legend>Data <span class="vs-req">*</span></legend>
                    <p class="vs-hint">Every number in the video is computed from this data by code, not by the AI.</p>
                    <label class="vs-radio"><input type="radio" name="data_kind" value="attachment" ${f.data_kind === 'attachment' ? 'checked' : ''} ${hasData ? '' : 'disabled'}> Card attachment (CSV, JSON or TXT)</label>
                    ${this._field('data_attachment_id', '', hasData ? `<select name="data_attachment_id">${dataOpts}</select>` : '<div class="vs-hint">No CSV/JSON/TXT attached to this card. Attach one to the card, or paste the numbers below.</div>')}
                    <label class="vs-radio"><input type="radio" name="data_kind" value="text" ${f.data_kind === 'text' ? 'checked' : ''}> Paste numbers</label>
                    ${this._field('data_text', '', `<textarea name="data_text" rows="5" placeholder="One per line, e.g.&#10;SNS ukupno: 2:37:45&#10;Studentska lista ukupno: 23 min 49 s&#10;Broj javljanja: 10">${esc(f.data_text)}</textarea>
                        <button type="button" class="btn btn-sm btn-secondary" id="vsExtract">Extract numbers from free text with AI</button>`)}
                    ${this._msg('data_kind', errors, warnings)}
                </fieldset>
                <fieldset class="vs-fieldset">
                    <legend>Footage (background images)</legend>
                    ${thumbs ? `<div class="vs-thumbs">${thumbs}</div>` : '<div class="vs-hint">No image attachments on this card. Scenes will use the brand background.</div>'}
                    ${this._msg('footage_ids', errors, warnings)}
                </fieldset>
                <fieldset class="vs-fieldset">
                    <legend>On-screen chrome</legend>
                    <div class="vs-row">
                        ${this._field('date_chip', 'Date chip', `<input type="text" name="date_chip" maxlength="30" value="${esc(f.date_chip)}" placeholder="18. novembar 2026.">`)}
                        ${this._field('context_chip', 'Context chip', `<input type="text" name="context_chip" maxlength="40" value="${esc(f.context_chip)}" placeholder="Konstitutivna sednica">`)}
                    </div>
                    <div class="vs-row">
                        ${this._field('section_tag', 'Section tag (top right)', `<input type="text" name="section_tag" maxlength="20" value="${esc(f.section_tag)}" placeholder="SKUPŠTINA">`)}
                        ${this._field('domain', 'Domain (bottom right)', `<input type="text" name="domain" maxlength="40" value="${esc(f.domain)}">`)}
                    </div>
                    ${this._field('ticker', 'News ticker', `<input type="text" name="ticker" maxlength="160" value="${esc(f.ticker)}" placeholder="Leave empty and the AI writes one">`)}
                </fieldset>
            </form>`;
        const footer = `
            ${(p.jobs || []).length ? '<button class="btn btn-secondary" id="vsBackJobs">All videos</button>' : ''}
            <span class="vs-spacer"></span>
            <button class="btn btn-primary" id="vsCheck">Check data &amp; continue</button>`;
        this._shell('Infographic video · 1/3 Brief', body, footer);

        const formEl = document.getElementById('vsForm');
        Object.entries(errors).forEach(([k]) => formEl.querySelector(`[data-field="${k}"]`)?.classList.add('vs-has-error'));
        const count = () => { const c = formEl.querySelector('[data-count="brief"]'); if (c) c.textContent = formEl.brief.value.length; };
        formEl.brief.addEventListener('input', count);
        count();
        formEl.querySelectorAll('input, select, textarea').forEach((el) => el.addEventListener('input', () => el.closest('.vs-field')?.classList.remove('vs-has-error')));
        formEl.data_text.addEventListener('focus', () => { formEl.querySelector('input[name="data_kind"][value="text"]').checked = true; });
        formEl.data_attachment_id?.addEventListener('focus', () => { formEl.querySelector('input[name="data_kind"][value="attachment"]').checked = true; });
        formEl.querySelectorAll('input[name="footage"]').forEach((cb) => cb.addEventListener('change', () => {
            if (formEl.querySelectorAll('input[name="footage"]:checked').length > 8) { cb.checked = false; App.showToast('At most 8 images', 'error'); }
        }));
        document.getElementById('vsBackJobs')?.addEventListener('click', () => this.showJobs());
        document.getElementById('vsExtract').addEventListener('click', () => this.extractWithAI());
        document.getElementById('vsCheck').addEventListener('click', () => this.checkData());
    },

    _field(name, label, control, required = false) {
        return `<div class="vs-field" data-field="${name}">
            ${label ? `<label>${App.escapeHtml(label)}${required ? ' <span class="vs-req">*</span>' : ''}</label>` : ''}
            ${control}
            <div class="vs-msg" data-msg="${name}"></div>
        </div>`;
    },

    _msg(name, errors, warnings) {
        if (errors[name]) return `<div class="vs-msg vs-msg-error">${App.escapeHtml(errors[name])}</div>`;
        if (warnings[name]) return `<div class="vs-msg vs-msg-warn">${App.escapeHtml(warnings[name])}</div>`;
        return '';
    },

    _collectForm() {
        const f = document.getElementById('vsForm');
        return {
            topic: f.topic.value.trim(),
            brief: f.brief.value,
            language: f.language.value,
            target_seconds: parseInt(f.target_seconds.value) || 0,
            date_chip: f.date_chip.value.trim(),
            context_chip: f.context_chip.value.trim(),
            section_tag: f.section_tag.value.trim(),
            domain: f.domain.value.trim(),
            ticker: f.ticker.value.trim(),
            footage_ids: [...f.querySelectorAll('input[name="footage"]:checked')].map((c) => parseInt(c.value)),
            data_kind: f.querySelector('input[name="data_kind"]:checked')?.value || '',
            data_attachment_id: parseInt(f.data_attachment_id?.value || 0),
            data_text: f.data_text.value,
        };
    },

    /** Client-side mirror of VideoStoryboard::validateForm — instant flags, the server re-checks. */
    _validate(f) {
        const e = {};
        if (f.topic.length < 3) e.topic = 'A topic of at least 3 characters is required.';
        if (!f.language) e.language = 'Pick a language.';
        if (f.target_seconds < 15 || f.target_seconds > 60) e.target_seconds = 'Length must be 15–60 seconds.';
        if (!f.data_kind) e.data_kind = 'Data is required: pick a data attachment or paste the numbers.';
        else if (f.data_kind === 'attachment' && !f.data_attachment_id) e.data_attachment_id = 'Pick a data attachment.';
        else if (f.data_kind === 'text' && !f.data_text.trim()) e.data_text = 'Paste the numbers, one "Label: value" per line.';
        return e;
    },

    _flag(errors, warnings = {}) {
        const formEl = document.getElementById('vsForm');
        if (!formEl) return;
        formEl.querySelectorAll('.vs-msg').forEach((m) => { m.textContent = ''; m.className = 'vs-msg'; });
        formEl.querySelectorAll('.vs-has-error').forEach((x) => x.classList.remove('vs-has-error'));
        const put = (k, text, cls) => {
            let slot = formEl.querySelector(`[data-msg="${k}"]`);
            if (!slot) {
                const fs = formEl.querySelector(`[data-field="${k}"]`) || formEl.querySelector('input[name="data_kind"]')?.closest('fieldset');
                slot = document.createElement('div');
                slot.dataset.msg = k;
                fs?.appendChild(slot);
            }
            slot.className = 'vs-msg ' + cls;
            slot.textContent = text;
            formEl.querySelector(`[data-field="${k}"]`)?.classList.add(cls === 'vs-msg-error' ? 'vs-has-error' : 'vs-has-warn');
        };
        Object.entries(warnings).forEach(([k, v]) => put(k, v, 'vs-msg-warn'));
        Object.entries(errors).forEach(([k, v]) => put(k, v, 'vs-msg-error'));
        const first = formEl.querySelector('.vs-has-error, .vs-msg-error');
        first?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    },

    async extractWithAI() {
        const f = document.getElementById('vsForm');
        const text = f.data_text.value.trim() || f.brief.value.trim();
        if (text.length < 10) { App.showToast('Paste the text with numbers into the "Paste numbers" box first.', 'error'); return; }
        const btn = document.getElementById('vsExtract');
        btn.disabled = true;
        btn.textContent = 'Extracting…';
        try {
            const res = await App.api('video.extract_data', { card_id: this.cardId, text });
            f.data_text.value = res.text;
            f.querySelector('input[name="data_kind"][value="text"]').checked = true;
            const n = Object.keys(res.dataset.values || {}).length;
            App.showToast(`${n} value(s) extracted — check them before continuing`, n ? 'success' : 'error');
        } catch (e) {
            App.showToast(e.message, 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Extract numbers from free text with AI';
    },

    async checkData() {
        const form = this._collectForm();
        const errors = this._validate(form);
        if (Object.keys(errors).length) { this._flag(errors); return; }
        const btn = document.getElementById('vsCheck');
        btn.disabled = true;
        try {
            const res = await fetch('api.php?action=video.save_draft', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': App.csrfToken },
                body: JSON.stringify({ card_id: this.cardId, job_id: this.job?.id || 0, form }),
            });
            const json = await res.json();
            if (res.status === 422) { this._flag(json.errors || {}, json.warnings || {}); App.showToast(json.error, 'error'); return; }
            if (!res.ok) throw new Error(json.error || 'Save failed');
            this.job = { ...(this.job || {}), ...json.job };
            this.showDataConfirm(json.warnings || {});
        } catch (e) {
            App.showToast(e.message, 'error');
        } finally {
            if (document.body.contains(btn)) btn.disabled = false;
        }
    },

    // ────────────────────────────────────────────────────── data confirm ──

    showDataConfirm(warnings) {
        const ds = this.job.dataset || { values: {}, lists: {}, notes: [] };
        const lang = this.job.form.language;
        const esc = App.escapeHtml;
        const vals = Object.entries(ds.values || {});
        const rows = vals.slice(0, 300).map(([k, v]) => `<tr><td><code>${esc(k)}</code></td><td>${esc(v.label)}</td><td class="vs-num">${esc(VideoRenderer.formatValue(v.value, v.unit, lang))}</td></tr>`).join('');
        const lists = Object.entries(ds.lists || {}).map(([k, l]) => `<li><code>${esc(k)}</code> — ${esc(l.label)} (${l.values.length})</li>`).join('');
        const warn = Object.values(warnings).map((w) => `<div class="vs-banner vs-banner-warn">${esc(w)}</div>`).join('');
        const ai = this.prefill.ai || {};
        const hasSb = !!(this.job.storyboard && (this.job.storyboard.scenes || []).length);
        const body = `
            ${warn}
            <p class="text-sm">Source: <strong>${esc(ds.source)}</strong> · ${vals.length} value(s), ${Object.keys(ds.lists || {}).length} list(s). These are the only numbers the video can show — check them.</p>
            ${(ds.notes || []).map((n) => `<p class="vs-hint">ℹ ${esc(n)}</p>`).join('')}
            <div class="vs-table-wrap"><table class="vs-table"><thead><tr><th>Key</th><th>Meaning</th><th>On screen</th></tr></thead><tbody>${rows}</tbody></table></div>
            ${lists ? `<details class="vs-details"><summary>Lists (for segmented bars)</summary><ul>${lists}</ul></details>` : ''}
            <p class="vs-hint">Generating uses 1 AI call (${ai.remaining_today ?? '?'} of ${ai.daily_limit ?? '?'} left today, model ${esc(ai.model || '')}). Editing the storyboard afterwards is free.</p>`;
        const footer = `
            <button class="btn btn-secondary" id="vsBackForm">Back to brief</button>
            <span class="vs-spacer"></span>
            ${hasSb ? '<button class="btn btn-secondary" id="vsKeep">Keep current storyboard</button>' : ''}
            <button class="btn btn-primary" id="vsGenerate" ${ai.configured ? '' : 'disabled title="API key not configured"'}>${hasSb ? 'Regenerate storyboard (AI)' : 'Generate storyboard (AI)'}</button>`;
        this._shell('Infographic video · 2/3 Check data', body, footer);
        document.getElementById('vsBackForm').addEventListener('click', () => this.showForm(this.job.form));
        document.getElementById('vsKeep')?.addEventListener('click', () => this.loadJob(this.job.id));
        document.getElementById('vsGenerate').addEventListener('click', () => this.generate());
    },

    // ───────────────────────────────────────────────────────── generate ──

    async generate() {
        const btn = document.getElementById('vsGenerate');
        if (btn) btn.disabled = true;
        try {
            const res = await App.api('video.generate', { id: this.job.id });
            this.job = { ...this.job, ...res.job };
        } catch (e) {
            // A slow host may drop the connection while the server keeps working — polling tells.
            if (/limit|configured|Already/.test(e.message)) { App.showToast(e.message, 'error'); if (btn) btn.disabled = false; return; }
        }
        this.showGenerating();
    },

    showGenerating() {
        this._shell('Infographic video · Writing storyboard',
            `<div class="vs-center vs-busy"><div class="spinner"></div><p>Claude is planning the scenes…</p><p class="vs-hint">Usually 10–60 seconds. You can close this window; the job keeps running.</p></div>`);
        const started = Date.now();
        const tick = async () => {
            try {
                const res = await App.api('video.get', { id: this.job.id }, 'GET');
                this.job = res.job;
                if (res.job.status === 'storyboarding' && Date.now() - started < 6 * 60 * 1000) {
                    this.pollTimer = setTimeout(tick, 2500);
                    return;
                }
                if (res.job.status === 'failed') return this.showFailed();
                this.showReview();
            } catch (e) {
                this.pollTimer = setTimeout(tick, 4000);
            }
        };
        this.pollTimer = setTimeout(tick, 2000);
    },

    showFailed() {
        this._shell('Infographic video · Generation failed',
            `<div class="vs-banner vs-banner-error">${App.escapeHtml(this.job.error || 'Unknown error')}</div>`,
            `<button class="btn btn-secondary" id="vsBackForm">Back to brief</button><span class="vs-spacer"></span><button class="btn btn-primary" id="vsRetry">Try again</button>`);
        document.getElementById('vsBackForm').addEventListener('click', () => this.showForm(this.job.form));
        document.getElementById('vsRetry').addEventListener('click', () => this.generate());
    },

    // ─────────────────────────────────────────────────────────── review ──

    async showReview() {
        const sb = this.job.storyboard || { scenes: [], meta: {}, issues: [] };
        this.scenes = JSON.parse(JSON.stringify(sb.scenes || [])).map((s) => { delete s._r; return s; });
        this.meta = { ...(sb.meta || {}) };
        const m = this.meta;
        const esc = App.escapeHtml;
        const u = this.job.usage || {};
        const body = `
            <div class="vs-review">
                <div class="vs-preview-col">
                    <canvas id="vsPreview" width="540" height="960" class="vs-preview"></canvas>
                    <div class="vs-player">
                        <button class="btn btn-sm btn-secondary" id="vsPlay">▶ Play</button>
                        <input type="range" id="vsScrub" min="0" max="1000" value="0" aria-label="Seek">
                        <span id="vsTime" class="vs-hint">0.0 s</span>
                    </div>
                    <div class="vs-scene-jumps" id="vsJumps"></div>
                    <fieldset class="vs-fieldset">
                        <legend>Chrome</legend>
                        ${['date_chip:Date chip:30', 'context_chip:Context chip:40', 'section_tag:Section tag:20', 'domain:Domain:40', 'ticker:Ticker:160'].map((x) => {
                            const [k, l, max] = x.split(':');
                            return `<label class="vs-mini">${l}<input type="text" data-meta="${k}" maxlength="${max}" value="${esc(m[k] || '')}"></label>`;
                        }).join('')}
                    </fieldset>
                    <p class="vs-hint">AI usage for this video: ${u.calls || 0} call(s), ${u.input_tokens || 0} in / ${u.output_tokens || 0} out tokens${u.cache_read_tokens ? `, ${u.cache_read_tokens} cached` : ''}.</p>
                </div>
                <div class="vs-edit-col">
                    <div id="vsIssues"></div>
                    <div id="vsScenes"></div>
                    <div class="vs-add-scene">
                        <select id="vsAddType">${['title', 'area_compare', 'big_numbers', 'spotlight', 'bar_ranking', 'quote', 'outro'].map((t) => `<option value="${t}">${this._typeLabel(t)}</option>`).join('')}</select>
                        <button class="btn btn-sm btn-secondary" id="vsAddScene">+ Add scene</button>
                    </div>
                </div>
            </div>`;
        const footer = `
            <button class="btn btn-secondary" id="vsBackData">Back to brief</button>
            <span class="vs-save-state vs-hint" id="vsSaveState"></span>
            <span class="vs-spacer"></span>
            <button class="btn btn-primary" id="vsRender">Approve &amp; render MP4</button>`;
        this._shell('Infographic video · 3/3 Review storyboard', body, footer);

        this._renderSceneEditor();
        this._renderIssues();
        document.querySelectorAll('[data-meta]').forEach((inp) => inp.addEventListener('input', () => { this.meta[inp.dataset.meta] = inp.value; this._queueSave(); }));
        document.getElementById('vsAddScene').addEventListener('click', () => { this.scenes.splice(Math.max(0, this.scenes.length - 1), 0, this._blankScene(document.getElementById('vsAddType').value)); this._queueSave(true); });
        document.getElementById('vsBackData').addEventListener('click', () => this.showForm(this.job.form));
        document.getElementById('vsRender').addEventListener('click', () => this.approveAndRender());
        await this._loadPreviewAssets();
        this._refreshPreview();
    },

    _typeLabel(t) {
        return { title: 'Title card', area_compare: 'Circle comparison', big_numbers: 'Big numbers', spotlight: 'Spotlight', bar_ranking: 'Bar ranking', quote: 'Quote', outro: 'Outro' }[t] || t;
    },

    _blankScene(type) {
        const base = { type, duration_s: { title: 3, outro: 3, quote: 5, big_numbers: 5 }[type] || 6, background: 'none' };
        const item = () => ({ label: '', value_ref: '', accent: false });
        switch (type) {
            case 'title': return { ...base, headline: [''], headline_accent: '', subtitle: '' };
            case 'area_compare': return { ...base, headline: '', subtitle: '', items: [item(), item()], legend: '' };
            case 'big_numbers': return { ...base, headline: '', rows: [item()], callout: { kind: 'none', a_ref: '', b_ref: '', template: '' } };
            case 'spotlight': return { ...base, headline: '', subtitle: '', value_label: '', value_ref: '', segments_ref: '' };
            case 'bar_ranking': return { ...base, headline: '', subtitle: '', items: [item(), item()] };
            case 'quote': return { ...base, quote: '', attribution: '' };
            default: return { ...base, headline: '', cta: '' };
        }
    },

    _renderSceneEditor() {
        const esc = App.escapeHtml;
        const ds = this.job.dataset || { values: {}, lists: {} };
        const lang = this.job.form.language;
        const valueOpts = (cur) => `<option value="">— pick a value —</option>` + Object.entries(ds.values || {}).map(([k, v]) =>
            `<option value="${esc(k)}" ${k === cur ? 'selected' : ''}>${esc(v.label)} = ${esc(VideoRenderer.formatValue(v.value, v.unit, lang))}</option>`).join('')
            + (cur && !(ds.values || {})[cur] ? `<option value="${esc(cur)}" selected>⚠ ${esc(cur)} (missing)</option>` : '');
        const listOpts = (cur) => `<option value="">— none —</option>` + Object.entries(ds.lists || {}).map(([k, l]) =>
            `<option value="${esc(k)}" ${k === cur ? 'selected' : ''}>${esc(l.label)} (${l.values.length})</option>`).join('');
        const tags = this.job.footage_tags || {};
        const bgOpts = (cur) => `<option value="none">Brand background</option>` + (this.job.form.footage_ids || []).map((id) =>
            `<option value="att_${id}" ${cur === 'att_' + id ? 'selected' : ''}>Image ${id}${tags['att_' + id] ? ' — ' + esc(tags['att_' + id]) : ''}</option>`).join('');
        const txt = (si, path, val, label, max, area = false) => `<label class="vs-mini" data-path="${path}">${label}${area
            ? `<textarea data-scene="${si}" data-bind="${path}" rows="2" maxlength="${max * 2}">${esc(val)}</textarea>`
            : `<input type="text" data-scene="${si}" data-bind="${path}" maxlength="${max * 2}" value="${esc(val)}">`}</label>`;
        const items = (si, key, list, maxN) => `
            <div class="vs-items" data-path="${key}">
                ${(list || []).map((it, j) => `
                    <div class="vs-item" data-path="${key}.${j}">
                        <input type="text" data-scene="${si}" data-bind="${key}.${j}.label" value="${esc(it.label)}" placeholder="Label" maxlength="60">
                        <select data-scene="${si}" data-bind="${key}.${j}.value_ref">${valueOpts(it.value_ref)}</select>
                        <label class="vs-accent" title="Highlight in accent colour"><input type="checkbox" data-scene="${si}" data-bind="${key}.${j}.accent" data-kind="bool" ${it.accent ? 'checked' : ''}> accent</label>
                        <button class="btn-icon" data-remove-item="${si}:${key}:${j}" title="Remove">&times;</button>
                    </div>`).join('')}
                ${(list || []).length < maxN ? `<button class="btn btn-sm btn-secondary" data-add-item="${si}:${key}">+ Add</button>` : ''}
            </div>`;

        const html = this.scenes.map((s, si) => {
            let fields = '';
            switch (s.type) {
                case 'title':
                    fields = `<label class="vs-mini" data-path="headline">Headline lines (one per line)<textarea data-scene="${si}" data-bind="headline" data-kind="lines" rows="3">${esc((s.headline || []).join('\n'))}</textarea></label>`
                        + txt(si, 'headline_accent', s.headline_accent, 'Accent line (orange)', 24) + txt(si, 'subtitle', s.subtitle, 'Subtitle', 80);
                    break;
                case 'area_compare':
                    fields = txt(si, 'headline', s.headline, 'Headline', 42) + txt(si, 'subtitle', s.subtitle, 'Subtitle', 80)
                        + `<div class="vs-sub">Circles (area = value)</div>` + items(si, 'items', s.items, 3) + txt(si, 'legend', s.legend, 'Legend', 50);
                    break;
                case 'bar_ranking':
                    fields = txt(si, 'headline', s.headline, 'Headline', 42) + txt(si, 'subtitle', s.subtitle, 'Subtitle', 80)
                        + `<div class="vs-sub">Bars (sorted by value automatically)</div>` + items(si, 'items', s.items, 6);
                    break;
                case 'big_numbers': {
                    const c = s.callout || { kind: 'none' };
                    fields = txt(si, 'headline', s.headline, 'Headline (optional)', 42) + `<div class="vs-sub">Numbers</div>` + items(si, 'rows', s.rows, 3)
                        + `<div class="vs-sub">Callout</div>
                           <div class="vs-callout" data-path="callout">
                             <select data-scene="${si}" data-bind="callout.kind"><option value="none" ${c.kind === 'none' ? 'selected' : ''}>None</option><option value="ratio" ${c.kind === 'ratio' ? 'selected' : ''}>Ratio (A ÷ B) → {ratio}</option><option value="difference" ${c.kind === 'difference' ? 'selected' : ''}>Difference (A − B) → {diff}</option></select>
                             ${c.kind !== 'none' ? `<label class="vs-mini" data-path="callout.a_ref">A<select data-scene="${si}" data-bind="callout.a_ref">${valueOpts(c.a_ref)}</select></label>
                             <label class="vs-mini" data-path="callout.b_ref">B<select data-scene="${si}" data-bind="callout.b_ref">${valueOpts(c.b_ref)}</select></label>`
                             + txt(si, 'callout.template', c.template, `Text (must contain ${c.kind === 'ratio' ? '{ratio}' : '{diff}'})`, 40) : ''}
                           </div>`;
                    break;
                }
                case 'spotlight':
                    fields = txt(si, 'headline', s.headline, 'Headline (name)', 42) + txt(si, 'subtitle', s.subtitle, 'Subtitle', 80) + txt(si, 'value_label', s.value_label, 'Value label', 22)
                        + `<label class="vs-mini" data-path="value_ref">Value<select data-scene="${si}" data-bind="value_ref">${valueOpts(s.value_ref)}</select></label>`
                        + `<label class="vs-mini" data-path="segments_ref">Segmented bar<select data-scene="${si}" data-bind="segments_ref">${listOpts(s.segments_ref)}</select></label>`;
                    break;
                case 'quote':
                    fields = txt(si, 'quote', s.quote, 'Quote (must be verbatim from the notes)', 170, true) + txt(si, 'attribution', s.attribution, 'Attribution', 50);
                    break;
                case 'outro':
                    fields = txt(si, 'headline', s.headline, 'Headline', 42) + txt(si, 'cta', s.cta, 'Call to action', 40);
                    break;
            }
            return `
                <div class="vs-scene" data-scene-card="${si}">
                    <div class="vs-scene-head">
                        <strong>${si + 1}. ${this._typeLabel(s.type)}</strong>
                        <label class="vs-dur">s <input type="number" min="2" max="12" step="0.5" data-scene="${si}" data-bind="duration_s" data-kind="num" value="${esc(String(s.duration_s))}"></label>
                        <span class="vs-spacer"></span>
                        <button class="btn-icon" data-move="${si}:-1" title="Move up" ${si === 0 ? 'disabled' : ''}>↑</button>
                        <button class="btn-icon" data-move="${si}:1" title="Move down" ${si === this.scenes.length - 1 ? 'disabled' : ''}>↓</button>
                        <button class="btn-icon" data-delete="${si}" title="Delete scene">&times;</button>
                    </div>
                    <div class="vs-scene-issues"></div>
                    ${fields}
                    <label class="vs-mini" data-path="background">Background<select data-scene="${si}" data-bind="background">${bgOpts(s.background)}</select></label>
                </div>`;
        }).join('');
        const box = document.getElementById('vsScenes');
        box.innerHTML = html || '<p class="vs-hint">No scenes. Add one below.</p>';

        box.querySelectorAll('[data-scene][data-bind]').forEach((el) => {
            const handler = () => {
                const si = parseInt(el.dataset.scene);
                let v = el.value;
                if (el.dataset.kind === 'bool') v = el.checked;
                else if (el.dataset.kind === 'num') v = parseFloat(v) || 0;
                else if (el.dataset.kind === 'lines') v = v.split('\n');
                this._setPath(this.scenes[si], el.dataset.bind, v);
                // The callout kind changes which inputs exist → structural.
                this._queueSave(el.dataset.bind === 'callout.kind');
            };
            el.addEventListener(el.tagName === 'SELECT' || el.type === 'checkbox' ? 'change' : 'input', handler);
        });
        box.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', () => {
            const [si, dir] = b.dataset.move.split(':').map(Number);
            const [s] = this.scenes.splice(si, 1);
            this.scenes.splice(si + dir, 0, s);
            this._queueSave(true);
        }));
        box.querySelectorAll('[data-delete]').forEach((b) => b.addEventListener('click', () => { this.scenes.splice(parseInt(b.dataset.delete), 1); this._queueSave(true); }));
        box.querySelectorAll('[data-add-item]').forEach((b) => b.addEventListener('click', () => {
            const [si, key] = b.dataset.addItem.split(':');
            (this.scenes[si][key] = this.scenes[si][key] || []).push({ label: '', value_ref: '', accent: false });
            this._queueSave(true);
        }));
        box.querySelectorAll('[data-remove-item]').forEach((b) => b.addEventListener('click', () => {
            const [si, key, j] = b.dataset.removeItem.split(':');
            this.scenes[si][key].splice(parseInt(j), 1);
            this._queueSave(true);
        }));
    },

    _setPath(obj, path, value) {
        const parts = path.split('.');
        let o = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            const k = /^\d+$/.test(parts[i]) ? parseInt(parts[i]) : parts[i];
            if (o[k] === undefined || o[k] === null) o[k] = /^\d+$/.test(parts[i + 1]) ? [] : {};
            o = o[k];
        }
        o[parts[parts.length - 1]] = value;
    },

    /** Debounced server re-validation. structural = re-render the editor afterwards. */
    _queueSave(structural = false) {
        this._structural = this._structural || structural;
        const st = document.getElementById('vsSaveState');
        if (st) st.textContent = 'Unsaved changes…';
        document.getElementById('vsRender')?.setAttribute('disabled', '');
        clearTimeout(this.saveTimer);
        if (structural) this._renderSceneEditor();
        this.saveTimer = setTimeout(() => this._save(), structural ? 50 : 700);
    },

    async _save() {
        const structural = this._structural;
        this._structural = false;
        const st = document.getElementById('vsSaveState');
        try {
            const res = await App.api('video.update_storyboard', { id: this.job.id, scenes: this.scenes, meta: this.meta });
            this.job = res.job;
            if (!document.getElementById('vsScenes')) return;
            if (structural) {
                this.scenes = JSON.parse(JSON.stringify(res.job.storyboard.scenes)).map((s) => { delete s._r; return s; });
                this._renderSceneEditor();
            }
            this._renderIssues();
            this._refreshPreview(true);
            if (st) st.textContent = 'Saved';
        } catch (e) {
            if (st) st.textContent = 'Save failed: ' + e.message;
        }
    },

    _renderIssues() {
        const issues = (this.job.storyboard && this.job.storyboard.issues) || [];
        const esc = App.escapeHtml;
        document.querySelectorAll('.vs-issue').forEach((x) => x.remove());
        document.querySelectorAll('.vs-scene-issues').forEach((x) => { x.innerHTML = ''; });
        document.querySelectorAll('.vs-flag-error, .vs-flag-warn').forEach((x) => x.classList.remove('vs-flag-error', 'vs-flag-warn'));
        const errors = issues.filter((i) => i.level === 'error').length;
        const warns = issues.length - errors;
        const global = [];
        issues.forEach((i) => {
            const cls = i.level === 'error' ? 'vs-flag-error' : 'vs-flag-warn';
            const note = `<div class="vs-issue vs-issue-${i.level}">${i.level === 'error' ? '✖' : '⚠'} ${esc(i.message)}</div>`;
            if (i.scene === null || i.scene === undefined) { global.push(note); return; }
            const card = document.querySelector(`[data-scene-card="${i.scene}"]`);
            if (!card) { global.push(note); return; }
            const target = card.querySelector(`[data-path="${CSS.escape(i.field)}"]`) || card.querySelector(`[data-path="${CSS.escape(i.field.split('.').slice(0, 2).join('.'))}"]`);
            if (target) {
                target.classList.add(cls);
                target.insertAdjacentHTML('beforeend', note);
            } else {
                card.querySelector('.vs-scene-issues').insertAdjacentHTML('beforeend', note);
            }
        });
        const box = document.getElementById('vsIssues');
        if (box) {
            box.innerHTML = (errors
                ? `<div class="vs-banner vs-banner-error">${errors} error(s) must be fixed before rendering.${warns ? ` ${warns} warning(s) to review.` : ''}</div>`
                : (warns ? `<div class="vs-banner vs-banner-warn">${warns} warning(s) — review them, then render.</div>` : '<div class="vs-banner vs-banner-ok">All checks passed. Every number on screen comes from the dataset.</div>'))
                + global.join('');
        }
        const btn = document.getElementById('vsRender');
        if (btn) {
            if (errors) { btn.setAttribute('disabled', ''); btn.title = 'Fix the errors first'; }
            else { btn.removeAttribute('disabled'); btn.title = ''; }
        }
    },

    async _loadPreviewAssets() {
        const bgs = {};
        (this.job.form.footage_ids || []).forEach((id) => { bgs['att_' + id] = `api.php?action=attachments.download&id=${id}`; });
        this.assets = await VideoRenderer.loadAssets({ logo: 'public/img/logo_white.png', backgrounds: bgs });
    },

    _refreshPreview(keepTime = false) {
        const canvas = document.getElementById('vsPreview');
        if (!canvas || !this.assets || !this.job.storyboard) return;
        const sb = JSON.parse(JSON.stringify(this.job.storyboard));
        const t = keepTime && this.player ? this.player.time : 0;
        const wasPlaying = this.player && this.player.playing;
        if (this.player) this.player.destroy();
        const ctx = canvas.getContext('2d');
        ctx.setTransform(canvas.width / VideoRenderer.W, 0, 0, canvas.height / VideoRenderer.H, 0, 0);
        const scrub = document.getElementById('vsScrub');
        const time = document.getElementById('vsTime');
        const playBtn = document.getElementById('vsPlay');
        this.player = VideoRenderer.createPlayer(canvas, sb, this.assets, (now, dur, playing) => {
            if (scrub && !scrub._dragging) scrub.value = Math.round((now / Math.max(0.001, dur)) * 1000);
            if (time) time.textContent = `${now.toFixed(1)} / ${dur.toFixed(1)} s`;
            if (playBtn) playBtn.textContent = playing ? '❚❚ Pause' : '▶ Play';
        });
        this.player.seek(t);
        if (wasPlaying) this.player.play();
        playBtn.onclick = () => this.player.toggle();
        scrub.oninput = () => { scrub._dragging = true; this.player.pause(); this.player.seek((scrub.value / 1000) * this.player.duration); };
        scrub.onchange = () => { scrub._dragging = false; };
        const tl = VideoRenderer.timeline(sb);
        document.getElementById('vsJumps').innerHTML = tl.parts.map((p) =>
            `<button class="btn btn-sm btn-secondary" data-jump="${p.start + Math.min(p.scene.duration_s - 0.2, 2)}">${p.index + 1}</button>`).join('');
        document.querySelectorAll('[data-jump]').forEach((b) => b.addEventListener('click', () => { this.player.pause(); this.player.seek(parseFloat(b.dataset.jump)); }));
    },

    // ─────────────────────────────────────────────────────────── render ──

    async approveAndRender() {
        if (!VideoRenderer.isSupported()) {
            App.showToast('Rendering needs WebCodecs — use a recent desktop Chrome or Edge.', 'error');
            return;
        }
        clearTimeout(this.saveTimer);
        let approved;
        try {
            if (this._structural || document.getElementById('vsSaveState')?.textContent.startsWith('Unsaved')) await this._save();
            approved = await App.api('video.approve', { id: this.job.id });
        } catch (e) {
            App.showToast(e.message, 'error');
            return;
        }
        if (this.player) this.player.destroy();
        this.abort = new AbortController();
        this._shell('Infographic video · Rendering',
            `<div class="vs-center vs-busy">
                <p>Rendering 1080×1920 MP4 in your browser… keep this tab open.</p>
                <div class="vs-progress"><div class="vs-progress-bar" id="vsProg"></div></div>
                <p class="vs-hint" id="vsProgText">Loading assets…</p>
            </div>`,
            `<span class="vs-spacer"></span><button class="btn btn-secondary" id="vsCancel">Cancel</button>`);
        document.getElementById('vsCancel').addEventListener('click', () => this.abort.abort());
        const prog = (p, label) => {
            const bar = document.getElementById('vsProg');
            if (bar) bar.style.width = Math.round(p * 100) + '%';
            const tx = document.getElementById('vsProgText');
            if (tx) tx.textContent = label || `${Math.round(p * 100)}%`;
        };
        try {
            await this._loadScript('public/vendor/mp4-muxer.min.js');
            const assets = await VideoRenderer.loadAssets(approved.assets);
            const sb = approved.job.storyboard;
            const blob = await VideoRenderer.renderMp4(sb, assets, (p) => prog(p * 0.9, `Encoding frames… ${Math.round(p * 100)}%`), this.abort.signal);
            prog(0.92, `Uploading ${(blob.size / 1048576).toFixed(1)} MB…`);
            const fd = new FormData();
            fd.append('id', this.job.id);
            fd.append('file', blob, 'infographic.mp4');
            const res = await App.upload('video.save_render', fd);
            if (!res || res.error) throw new Error((res && res.error) || 'Upload failed');
            this.job = { ...this.job, ...res.job };
            this.showDone(URL.createObjectURL(blob), res.attachment_id);
        } catch (e) {
            this._shell('Infographic video · Render failed', `<div class="vs-banner vs-banner-error">${App.escapeHtml(e.message)}</div>`,
                `<span class="vs-spacer"></span><button class="btn btn-primary" id="vsBackReview">Back to storyboard</button>`);
            document.getElementById('vsBackReview').addEventListener('click', () => this.loadJob(this.job.id));
        } finally {
            this.abort = null;
        }
    },

    showDone(blobUrl, attachmentId) {
        this._shell('Infographic video · Done',
            `<div class="vs-done">
                <video src="${blobUrl}" controls playsinline class="vs-video"></video>
                <div>
                    <p><strong>Saved to the card</strong> as an attachment. Card members were notified.</p>
                    <p><a class="btn btn-primary" href="api.php?action=attachments.download&id=${parseInt(attachmentId)}">Download MP4</a></p>
                </div>
            </div>`,
            `<button class="btn btn-secondary" id="vsBackReview">Edit storyboard</button><span class="vs-spacer"></span><button class="btn btn-primary" data-vs-close2>Close</button>`);
        document.getElementById('vsBackReview').addEventListener('click', () => this.loadJob(this.job.id));
        document.querySelector('[data-vs-close2]').addEventListener('click', () => this.close());
    },

    _loadScript(src) {
        if (document.querySelector(`script[data-src="${src}"]`)) return Promise.resolve();
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.dataset.src = src;
            s.onload = resolve;
            s.onerror = () => reject(new Error('Could not load ' + src));
            document.head.appendChild(s);
        });
    },
};
