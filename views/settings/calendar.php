<?php
require_once __DIR__ . '/../../core/GoogleCalendar.php';

$configured  = GoogleCalendar::isConfigured();
$connected   = $configured && GoogleCalendar::isConnected(Auth::userId());
$account     = $connected ? GoogleCalendar::getAccount(Auth::userId()) : null;
$flashError  = isset($_GET['error']) ? (string) $_GET['error'] : null;
$flashOk     = isset($_GET['connected']) ? '1' : (isset($_GET['disconnected']) ? '2' : null);
?>
<div class="settings-page">
    <div class="settings-card">
        <h1>Calendar sync</h1>
        <p class="settings-lede">
            Connect BravoCollab to your Google account so cards and tasks
            assigned to you (and anything in your personal board) with a due
            date appear on a dedicated <strong>BravoCollab</strong> calendar.
            Marking something complete shows the event as cancelled in Google.
            Sync runs every 15 minutes.
        </p>

        <?php if ($flashError): ?>
            <div class="settings-flash settings-flash-error">
                <?php if ($flashError === 'not_configured'): ?>
                    Google integration isn't configured on this server. The
                    administrator needs to set <code>google_client_id</code>
                    and <code>google_client_secret</code> in <code>config.php</code>.
                <?php elseif ($flashError === 'missing_code'): ?>
                    Google didn't return an authorization code. Try again.
                <?php else: ?>
                    Couldn't complete connection: <?php echo htmlspecialchars($flashError); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($flashOk === '1'): ?>
            <div class="settings-flash settings-flash-ok">Connected. Initial sync is running.</div>
        <?php elseif ($flashOk === '2'): ?>
            <div class="settings-flash settings-flash-ok">Disconnected. The BravoCollab calendar has been removed from your Google account.</div>
        <?php endif; ?>

        <?php if (!$configured): ?>
            <div class="settings-flash settings-flash-warn">
                Integration is not configured on the server. Please contact
                your administrator.
            </div>
        <?php elseif ($connected): ?>
            <div class="settings-status settings-status-on">
                <strong>Connected</strong>
                <span>Since <?php echo htmlspecialchars($account['connected_at']); ?></span>
            </div>
            <div class="settings-actions">
                <button type="button" class="btn btn-secondary" id="syncNowBtn">Sync now</button>
                <button type="button" class="btn btn-danger" id="disconnectBtn">Disconnect</button>
            </div>
            <p class="settings-fineprint">
                Disconnecting removes the BravoCollab calendar (and all the
                events we created in it) from your Google account.
            </p>
        <?php else: ?>
            <div class="settings-status settings-status-off">
                <strong>Not connected</strong>
            </div>
            <div class="settings-actions">
                <a href="index.php?page=google_connect" class="btn btn-primary">Connect Google Calendar</a>
            </div>
            <p class="settings-fineprint">
                You'll be sent to Google to authorize. We only ask for permission
                to manage calendars in your account — we create one called
                "BravoCollab" and never touch any other calendar.
            </p>
        <?php endif; ?>
    </div>
</div>

<style>
.settings-page { max-width: 720px; margin: 24px auto; padding: 0 16px; }
.settings-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 28px; }
.settings-card h1 { margin: 0 0 12px; font-size: 22px; color: #172b4d; }
.settings-lede { color: #5e6c84; line-height: 1.5; margin: 0 0 18px; }
.settings-flash { padding: 10px 14px; border-radius: 4px; margin-bottom: 18px; font-size: 14px; line-height: 1.5; }
.settings-flash-error { background: #FFEBE6; color: #BF2600; }
.settings-flash-warn  { background: #FFFAE6; color: #974F0C; }
.settings-flash-ok    { background: #E3FCEF; color: #006644; }
.settings-status { display: flex; flex-direction: column; gap: 4px; padding: 14px 16px; border-radius: 6px; margin-bottom: 14px; }
.settings-status-on  { background: #E3FCEF; }
.settings-status-off { background: #F4F5F7; }
.settings-status strong { color: #172b4d; font-size: 16px; }
.settings-status span { color: #5e6c84; font-size: 13px; }
.settings-actions { display: flex; gap: 10px; margin-bottom: 8px; }
.settings-fineprint { color: #5e6c84; font-size: 12px; margin: 8px 0 0; line-height: 1.5; }
</style>

<script>
document.getElementById('disconnectBtn')?.addEventListener('click', async () => {
    if (!confirm('Disconnect Google Calendar? The BravoCollab calendar will be removed from your Google account.')) return;
    try {
        await App.api('google_calendar.disconnect', {});
        window.location.href = 'index.php?page=settings_calendar&disconnected=1';
    } catch (e) {
        App.showToast(e.message || 'Failed to disconnect', 'error');
    }
});

document.getElementById('syncNowBtn')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Syncing…';
    try {
        const res = await App.api('google_calendar.sync_now', {});
        const r = res.result || {};
        App.showToast(`Synced: ${r.created||0} created, ${r.updated||0} updated, ${r.deleted||0} removed.`, 'success');
    } catch (err) {
        App.showToast(err.message || 'Sync failed', 'error');
    } finally {
        btn.disabled = false;
        btn.textContent = original;
    }
});
</script>
