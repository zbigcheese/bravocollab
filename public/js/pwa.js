/**
 * BravoCollab PWA helpers — push subscribe/unsubscribe and the first-open
 * permission prompt banner that appears when the app is launched as an
 * installed PWA.
 */
const PWA = {
    LS_PROMPTED: 'bc_push_prompted',
    _statusCache: null,

    isStandalone() {
        return (
            window.matchMedia('(display-mode: standalone)').matches
            || window.matchMedia('(display-mode: fullscreen)').matches
            || window.matchMedia('(display-mode: minimal-ui)').matches
            // iOS-specific
            || window.navigator.standalone === true
        );
    },

    isPushSupported() {
        return ('serviceWorker' in navigator) && ('PushManager' in window);
    },

    urlBase64ToUint8(str) {
        const padding = '='.repeat((4 - str.length % 4) % 4);
        const b64 = (str + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(b64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    },

    async getStatus(forceRefresh = false) {
        if (!forceRefresh && this._statusCache) return this._statusCache;
        try {
            const res = await App.api('push.status', {}, 'GET');
            this._statusCache = res;
            return res;
        } catch (e) {
            return { configured: false, public_key: '', subscription_count: 0 };
        }
    },

    async getCurrentSubscription() {
        if (!this.isPushSupported()) return null;
        const reg = await navigator.serviceWorker.ready.catch(() => null);
        if (!reg) return null;
        return await reg.pushManager.getSubscription();
    },

    /** Full subscribe flow. Returns true on success. */
    async enablePush() {
        if (!this.isPushSupported()) {
            App.showToast('Push notifications are not supported on this browser.', 'error');
            return false;
        }
        const status = await this.getStatus(true);
        if (!status.configured || !status.public_key) {
            App.showToast('Push isn\'t configured on the server yet.', 'error');
            return false;
        }
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') return false;

        const reg = await navigator.serviceWorker.ready;
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.urlBase64ToUint8(status.public_key),
            });
        }
        await App.api('push.subscribe', sub.toJSON());
        return true;
    },

    async disablePush() {
        const sub = await this.getCurrentSubscription();
        if (sub) {
            const endpoint = sub.endpoint;
            try { await sub.unsubscribe(); } catch (e) { /* ignore */ }
            try { await App.api('push.unsubscribe', { endpoint }); } catch (e) { /* ignore */ }
        }
    },

    /**
     * On first PWA launch, drop a small banner asking the user to enable
     * notifications. Persists a localStorage flag after they choose so we
     * don't bug them again. Browser-tab use never triggers the banner.
     */
    async maybePromptOnFirstOpen() {
        if (!this.isStandalone()) return;
        if (!this.isPushSupported()) return;
        if (Notification.permission === 'granted' || Notification.permission === 'denied') return;
        try { if (localStorage.getItem(this.LS_PROMPTED) === '1') return; } catch (e) {}

        const status = await this.getStatus();
        if (!status.configured) return;

        const sub = await this.getCurrentSubscription();
        if (sub) return;

        this._showPromptBanner();
    },

    _showPromptBanner() {
        if (document.getElementById('pwaPushPrompt')) return;
        const wrap = document.createElement('div');
        wrap.id = 'pwaPushPrompt';
        wrap.innerHTML = `
            <div class="pwa-push-prompt">
                <div class="pwa-push-prompt-text">
                    <strong>Turn on notifications?</strong>
                    <span>Get pinged when you're assigned, mentioned, replied to, or have something due today.</span>
                </div>
                <div class="pwa-push-prompt-actions">
                    <button type="button" class="pwa-push-prompt-no" id="pwaPushNo">Not now</button>
                    <button type="button" class="pwa-push-prompt-yes" id="pwaPushYes">Enable</button>
                </div>
            </div>
        `;
        document.body.appendChild(wrap);

        const close = () => {
            try { localStorage.setItem(this.LS_PROMPTED, '1'); } catch (e) {}
            wrap.remove();
        };

        document.getElementById('pwaPushNo').addEventListener('click', close);
        document.getElementById('pwaPushYes').addEventListener('click', async () => {
            const btn = document.getElementById('pwaPushYes');
            btn.disabled = true; btn.textContent = 'Enabling…';
            try {
                const ok = await this.enablePush();
                if (ok) App.showToast('Notifications enabled.', 'success');
            } catch (e) {
                App.showToast(e.message || 'Failed to enable notifications', 'error');
            } finally {
                close();
            }
        });
    },
};

document.addEventListener('DOMContentLoaded', () => {
    PWA.maybePromptOnFirstOpen();
});
