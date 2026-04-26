    </main>
    <div id="toast-container"></div>
    <script src="public/js/app.js"></script>
    <script src="public/js/notifications.js"></script>
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

        // TEMPORARY: admin-only "test: dailyemail" trigger.
        document.getElementById('testDailyEmailBtn')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'sending…';
            try {
                const res = await App.api('users.test_whats_next', {});
                App.showToast(res.message || 'Done', res.sent ? 'success' : 'info');
            } catch (err) {
                App.showToast(err.message || 'Failed', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });
    </script>
