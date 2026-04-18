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
    </script>
