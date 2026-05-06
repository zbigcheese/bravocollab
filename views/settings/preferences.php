<?php
require_once __DIR__ . '/../../core/UserPreferences.php';
$prefs = UserPreferences::get(Auth::userId());
?>
<div class="settings-page">
    <div class="settings-card">
        <h1>Settings</h1>
        <p class="settings-lede">
            Control how BravoCollab notifies you and what gets included in
            your daily recap and Google Calendar sync.
        </p>

        <div class="settings-flash" id="prefSavedFlash" style="display:none;">
            Saved.
        </div>

        <h2 class="settings-section-heading">Notification preferences</h2>

        <div class="pref-row">
            <div class="pref-row-text">
                <div class="pref-row-title">Turn on notifications for cards I am the coordinator on</div>
                <div class="pref-row-help">
                    When on, you'll get notifications, daily recap entries, and
                    Google Calendar events for any card where you're the coordinator
                    — even when you're not also assigned. Off by default to avoid noise.
                </div>
            </div>
            <label class="pref-switch">
                <input type="checkbox" data-pref="notify_coordinator_cards" <?php echo $prefs['notify_coordinator_cards'] ? 'checked' : ''; ?>>
                <span class="pref-slider"></span>
            </label>
        </div>

        <div class="pref-row">
            <div class="pref-row-text">
                <div class="pref-row-title">Email notifications</div>
                <div class="pref-row-help">
                    The digest email that batches up your unread notifications
                    after they've been waiting an hour. Turn off to receive
                    notifications only inside BravoCollab.
                </div>
            </div>
            <label class="pref-switch">
                <input type="checkbox" data-pref="email_notifications" <?php echo $prefs['email_notifications'] ? 'checked' : ''; ?>>
                <span class="pref-slider"></span>
            </label>
        </div>

        <div class="pref-row">
            <div class="pref-row-text">
                <div class="pref-row-title">Daily recap email</div>
                <div class="pref-row-help">
                    The morning "What's next" email summarising cards and
                    tasks due in the next eight days. Sent at 8:00 CET only
                    when there's something due in the next three days.
                </div>
            </div>
            <label class="pref-switch">
                <input type="checkbox" data-pref="daily_recap_email" <?php echo $prefs['daily_recap_email'] ? 'checked' : ''; ?>>
                <span class="pref-slider"></span>
            </label>
        </div>

        <h2 class="settings-section-heading">Browser push notifications</h2>

        <div class="pref-row">
            <div class="pref-row-text">
                <div class="pref-row-title">Enable push notifications on this device</div>
                <div class="pref-row-help" id="pushHelp">
                    Get a system notification on this browser whenever you'd
                    normally get an in-app notification, plus a daily summary
                    push at 8:00 CET. On iPhone you must "Add to Home Screen"
                    first and open the app from there.
                </div>
                <div id="pushStatus" class="pref-row-help" style="margin-top:6px;color:var(--color-text-light);"></div>
            </div>
            <label class="pref-switch">
                <input type="checkbox" id="pushToggle">
                <span class="pref-slider"></span>
            </label>
        </div>
    </div>
</div>

<style>
.settings-page { max-width: 720px; margin: 24px auto; padding: 0 16px; }
.settings-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 28px; }
.settings-card h1 { margin: 0 0 12px; font-size: 22px; color: #172b4d; }
.settings-lede { color: #5e6c84; line-height: 1.5; margin: 0 0 18px; }
.settings-flash { padding: 10px 14px; border-radius: 4px; margin-bottom: 18px; font-size: 14px; line-height: 1.5; background: #E3FCEF; color: #006644; }

.settings-section-heading {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #5e6c84;
    margin: 18px 0 4px;
    font-weight: 700;
}

.pref-row {
    display: flex; align-items: flex-start; gap: 18px;
    padding: 16px 0;
    border-top: 1px solid var(--color-border);
}
.pref-row:first-of-type { border-top: 0; padding-top: 4px; }
.pref-row-text { flex: 1; min-width: 0; }
.pref-row-title { font-weight: 600; color: var(--color-text); font-size: 14px; margin-bottom: 4px; }
.pref-row-help  { color: var(--color-text-light); font-size: 13px; line-height: 1.5; }

/* Toggle switch */
.pref-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; cursor: pointer; }
.pref-switch input { opacity: 0; width: 0; height: 0; }
.pref-slider { position: absolute; inset: 0; background: #c1c7d0; border-radius: 999px; transition: background 0.15s; }
.pref-slider::before {
    content: ""; position: absolute; left: 2px; top: 2px; width: 20px; height: 20px;
    background: #fff; border-radius: 50%;
    transition: transform 0.15s; box-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.pref-switch input:checked + .pref-slider { background: #61BD4F; }
.pref-switch input:checked + .pref-slider::before { transform: translateX(20px); }
.pref-switch input:disabled + .pref-slider { opacity: 0.6; cursor: wait; }
</style>

<script>
(function () {
    const flash = document.getElementById('prefSavedFlash');
    let flashTimer = null;

    document.querySelectorAll('.pref-switch input[data-pref]').forEach(cb => {
        cb.addEventListener('change', async () => {
            const key = cb.dataset.pref;
            const desired = cb.checked;
            cb.disabled = true;
            try {
                await App.api('users.update_preferences', { [key]: desired ? 1 : 0 });
                flash.style.display = 'block';
                clearTimeout(flashTimer);
                flashTimer = setTimeout(() => { flash.style.display = 'none'; }, 1800);
            } catch (e) {
                cb.checked = !desired;
                App.showToast(e.message || 'Failed to save', 'error');
            } finally {
                cb.disabled = false;
            }
        });
    });

    // ---- Push notifications toggle ----
    const pushCb     = document.getElementById('pushToggle');
    const pushStatus = document.getElementById('pushStatus');
    if (!pushCb) return;

    const setStatus = (text) => { pushStatus.textContent = text; };
    const supported = ('serviceWorker' in navigator) && ('PushManager' in window);

    if (!supported) {
        pushCb.disabled = true;
        setStatus('This browser does not support web push notifications.');
        return;
    }

    function urlBase64ToUint8(str) {
        const padding = '='.repeat((4 - str.length % 4) % 4);
        const b64 = (str + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(b64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    let pushState = { configured: false, public_key: '', subscribed: false };

    async function refreshState() {
        try {
            const res = await App.api('push.status', {}, 'GET');
            pushState.configured = !!res.configured;
            pushState.public_key = res.public_key || '';
        } catch (e) { /* ignore */ }

        if (!pushState.configured) {
            pushCb.disabled = true;
            setStatus('Push isn\'t configured on this server. (Admin: run tools/generate_vapid.php and add the keys to config.php.)');
            return;
        }

        const reg = await navigator.serviceWorker.ready.catch(() => null);
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        pushState.subscribed = !!sub;
        pushCb.checked = pushState.subscribed;
        pushCb.disabled = false;

        if (pushState.subscribed) {
            setStatus('Active on this device.');
        } else if (Notification.permission === 'denied') {
            setStatus('Notifications are blocked in this browser. Re-enable them in browser settings, then toggle this on.');
            pushCb.disabled = true;
        } else {
            setStatus('Off on this device.');
        }
    }

    async function enablePush() {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
            pushCb.checked = false;
            setStatus('Permission denied — push not enabled.');
            return;
        }
        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8(pushState.public_key),
            });
        }
        const subJson = sub.toJSON();
        await App.api('push.subscribe', subJson);
        setStatus('Active on this device.');
    }

    async function disablePush() {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();
        if (sub) {
            const endpoint = sub.endpoint;
            try { await sub.unsubscribe(); } catch (e) { /* ignore */ }
            await App.api('push.unsubscribe', { endpoint });
        }
        setStatus('Off on this device.');
    }

    pushCb.addEventListener('change', async () => {
        const desired = pushCb.checked;
        pushCb.disabled = true;
        try {
            if (desired) await enablePush();
            else         await disablePush();
        } catch (e) {
            pushCb.checked = !desired;
            App.showToast(e.message || 'Push toggle failed', 'error');
        } finally {
            pushCb.disabled = false;
        }
    });

    refreshState();
})();
</script>
