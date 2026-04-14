    </main>
    <div id="toast-container"></div>
    <script src="public/js/app.js"></script>
    <script src="public/js/notifications.js"></script>
    <script>
        document.getElementById('logoutBtn')?.addEventListener('click', async () => {
            await App.api('auth.logout', {});
            window.location.href = 'index.php?page=login';
        });
    </script>
