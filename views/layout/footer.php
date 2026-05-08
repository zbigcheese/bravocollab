    </main>
    <div id="toast-container"></div>
    <script src="<?php echo asset_url('public/js/app.js'); ?>"></script>
    <script src="<?php echo asset_url('public/js/notifications.js'); ?>"></script>
    <script src="<?php echo asset_url('public/js/pwa.js'); ?>"></script>
    <script>
        (function () {
            const trigger  = document.getElementById('userMenuTrigger');
            const dropdown = document.getElementById('userMenuDropdown');
            trigger?.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = dropdown.classList.toggle('open');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#userMenu')) {
                    dropdown?.classList.remove('open');
                    trigger?.setAttribute('aria-expanded', 'false');
                }
            });
        })();

        document.getElementById('logoutBtn')?.addEventListener('click', async () => {
            await App.api('auth.logout', {});
            window.location.href = 'index.php?page=login';
        });

        // Calendar-sync menu toggle (desktop standalone trigger).
        (function () {
            const trigger  = document.getElementById('calendarMenuTrigger');
            const dropdown = document.getElementById('calendarMenuDropdown');
            if (!trigger || !dropdown) return;
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = dropdown.classList.toggle('open');
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#calendarMenu')) {
                    dropdown.classList.remove('open');
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        })();

        // Wire the calendar action buttons (both desktop and mobile copies).
        async function calendarSyncNow(btn) {
            if (!btn) return;
            const original = btn.textContent;
            btn.disabled = true; btn.textContent = 'Syncing…';
            try {
                const res = await App.api('google_calendar.sync_now', {});
                const r = res.result || {};
                App.showToast(`Synced: ${r.created||0} created, ${r.updated||0} updated, ${r.deleted||0} removed.`, 'success');
            } catch (err) {
                App.showToast(err.message || 'Sync failed', 'error');
            } finally {
                btn.disabled = false; btn.textContent = original;
            }
        }
        async function calendarDisconnect() {
            if (!confirm('Disconnect Google Calendar? The BravoCollab calendar will be removed from your Google account.')) return;
            try {
                await App.api('google_calendar.disconnect', {});
                window.location.reload();
            } catch (e) {
                App.showToast(e.message || 'Failed to disconnect', 'error');
            }
        }
        document.getElementById('calMenuSyncNow')?.addEventListener('click',
            (e) => calendarSyncNow(e.currentTarget));
        document.getElementById('calMenuSyncNowMobile')?.addEventListener('click',
            (e) => calendarSyncNow(e.currentTarget));
        document.getElementById('calMenuDisconnect')?.addEventListener('click', calendarDisconnect);
        document.getElementById('calMenuDisconnectMobile')?.addEventListener('click', calendarDisconnect);

        // TEMPORARY: admin-only "test: dailyemail" trigger.
        // Renders a detailed report so we can see what was prepared and what
        // mail() actually returned (which is not a delivery guarantee).
        document.getElementById('testDailyEmailBtn')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'sending…';
            try {
                const res = await App.api('users.test_whats_next', {});
                showTestEmailReport(res);
            } catch (err) {
                App.showToast(err.message || 'Failed', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });

        function showTestEmailReport(res) {
            const esc = App.escapeHtml;

            const sectionsHtml = (res.sections || []).map(sec => {
                const cards = (sec.cards || []).map(c =>
                    `<li><strong>${esc(c.title)}</strong>
                       <span style="color:#5e6c84;font-size:12px;">— ${esc(c.board_title)} (due ${esc(c.due_date || '')})</span>
                     </li>`).join('');
                const items = (sec.items || []).map(it =>
                    `<li>&#9745; ${esc(it.content)}
                       <span style="color:#5e6c84;font-size:12px;">— ${esc(it.card_title)} / ${esc(it.board_title)} (due ${esc(it.due_date || '')})</span>
                     </li>`).join('');
                const empty = !cards && !items
                    ? '<li style="color:#999;font-style:italic;">(empty)</li>' : '';
                return `
                    <div style="margin:10px 0;">
                        <div style="font-weight:700;color:#172b4d;margin-bottom:4px;">${esc(sec.label)}</div>
                        <ul style="margin:0;padding-left:20px;line-height:1.55;">${cards}${items}${empty}</ul>
                    </div>
                `;
            }).join('') || '<p style="color:#999;font-style:italic;">No sections built (nothing assigned in the next 8 days).</p>';

            const email = res.email || {};
            const lastErr = email.last_error
                ? `<pre style="background:#FFF4F4;color:#a00;padding:8px;border-radius:4px;font-size:12px;white-space:pre-wrap;margin:6px 0 0;">${esc(JSON.stringify(email.last_error, null, 2))}</pre>`
                : '<span style="color:#5e6c84;">none</span>';

            const status = res.sent
                ? '<span style="color:#fff;background:#61BD4F;padding:2px 8px;border-radius:3px;font-weight:700;font-size:12px;">mail() OK</span>'
                : (res.reason === 'no_data'
                    ? '<span style="color:#fff;background:#5e6c84;padding:2px 8px;border-radius:3px;font-weight:700;font-size:12px;">SKIPPED</span>'
                    : '<span style="color:#fff;background:#EB5A46;padding:2px 8px;border-radius:3px;font-weight:700;font-size:12px;">mail() FAILED</span>');

            const headersHtml = email.headers
                ? `<pre style="background:#f4f5f7;padding:8px;border-radius:4px;font-size:12px;white-space:pre-wrap;margin:4px 0;">${esc(email.headers.join('\n'))}</pre>`
                : '<span style="color:#999;">—</span>';

            const bodyPreview = email.body_preview
                ? `<pre style="background:#f4f5f7;padding:8px;border-radius:4px;font-size:12px;white-space:pre-wrap;max-height:160px;overflow:auto;margin:4px 0;">${esc(email.body_preview)}…</pre>`
                : '<span style="color:#999;">—</span>';

            const content = `
                <div style="font-size:13px;color:#172b4d;">
                    <p style="margin:0 0 12px;">${status} <span style="margin-left:8px;">${esc(res.message || '')}</span></p>
                    <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;width:160px;">Recipient</td><td>${esc(res.recipient || '')}</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">From (header)</td><td>${esc(email.from || '—')}</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Envelope From</td><td>${esc(email.envelope_from || '—')} <span style="color:#5e6c84;font-size:11px;">(used for SPF check)</span></td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">sendmail args</td><td><code style="font-size:11px;">${esc(email.additional_params || '—')}</code></td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Message-ID</td><td><code style="font-size:11px;">${esc(email.message_id || '—')}</code></td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">CET now</td><td>${esc(res.cet_now || '')}</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Sections</td><td>${res.sections_count}</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Cards / items</td><td>${res.cards_total} card(s), ${res.items_total} item(s)</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Subject</td><td>${esc(email.subject || '—')}</td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Subject (encoded)</td><td><code style="font-size:11px;word-break:break-all;">${esc(email.subject_encoded || '—')}</code></td></tr>
                        <tr><td style="padding:3px 8px 3px 0;color:#5e6c84;">Body lengths</td><td>${email.body_length || 0} chars total (HTML ${email.html_length || 0} + plain ${email.plain_length || 0})</td></tr>
                    </table>

                    <div style="margin-top:14px;">
                        <div style="font-weight:700;color:#172b4d;margin-bottom:4px;">Prepared sections</div>
                        ${sectionsHtml}
                    </div>

                    <details style="margin-top:14px;">
                        <summary style="cursor:pointer;font-weight:700;">Mail headers</summary>
                        ${headersHtml}
                    </details>

                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;font-weight:700;">Body preview (text-only, first 600 chars)</summary>
                        ${bodyPreview}
                    </details>

                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;font-weight:700;">PHP last_error after mail()</summary>
                        ${lastErr}
                    </details>

                    <p style="color:#5e6c84;font-size:12px;margin-top:14px;">
                        <strong>If mail() returned OK but nothing arrives:</strong> check spam folder, then your MTA queue / mail.log on the server. Shared cPanel hosts often silently drop messages whose From doesn't match the domain, or rate-limit after a few sends in quick succession.
                    </p>
                </div>
            `;
            App.createModal('testEmailReportModal', 'test: dailyemail — diagnostics', content);
        }
    </script>
