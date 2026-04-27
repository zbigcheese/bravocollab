<?php
require_once __DIR__ . '/../../core/GoogleCalendar.php';

if (!Auth::isLoggedIn()) {
    header('Location: index.php?page=login');
    exit;
}

// $_REQUEST handles legacy query-mode and form_post-mode responses (in case
// Google falls back). For response_mode=fragment, no params reach the server
// at all — the page below extracts them from window.location.hash via JS
// and POSTs them to the finish endpoint (base64-encoded so WAFs can't see
// "https://" patterns inside iss/scope).
$code  = $_REQUEST['code']  ?? '';
$state = $_REQUEST['state'] ?? '';
$error = $_REQUEST['error'] ?? '';

if ($error) {
    header('Location: index.php?page=settings_calendar&error=' . urlencode($error));
    exit;
}

if ($code && $state) {
    try {
        GoogleCalendar::handleCallback(Auth::userId(), $code, $state);
        header('Location: index.php?page=settings_calendar&connected=1');
        exit;
    } catch (Throwable $e) {
        error_log('Google callback failed: ' . $e->getMessage());
        header('Location: index.php?page=settings_calendar&error=' . urlencode($e->getMessage()));
        exit;
    }
}

// No server-visible params — assume fragment mode. Render JS handoff page.
$csrfToken = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES);
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Connecting...</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#f4f5f7;color:#172b4d;margin:0;padding:48px 16px;text-align:center}.box{max-width:420px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,0.08)}h1{font-size:18px;margin:0 0 8px}p{color:#5e6c84;font-size:14px;margin:6px 0}</style>
</head><body>
<div class="box">
    <h1>Connecting Google Calendar…</h1>
    <p id="status">Finishing authorization, please wait.</p>
</div>
<script>
(async function () {
    const goSettings = (qs) => { window.location.replace('index.php?page=settings_calendar' + qs); };

    // Hash arrives as "#code=...&state=...&iss=https://...&scope=https://..."
    const raw = (window.location.hash || '').replace(/^#/, '');
    if (!raw) { goSettings('&error=missing_code'); return; }

    const params = {};
    new URLSearchParams(raw).forEach((v, k) => { params[k] = v; });
    if (params.error) { goSettings('&error=' + encodeURIComponent(params.error)); return; }
    if (!params.code || !params.state) { goSettings('&error=missing_code'); return; }

    // Base64-encode the JSON payload so the request body has no literal
    // "https://" anywhere — keeps the host's WAF from rejecting it.
    const payload = btoa(JSON.stringify({ code: params.code, state: params.state }));

    try {
        const res = await fetch('api.php?action=google_calendar.oauth_finish', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo $csrfToken; ?>',
            },
            body: JSON.stringify({ payload }),
        });
        const json = await res.json().catch(() => ({}));
        if (res.ok && json.success) {
            goSettings('&connected=1');
        } else {
            goSettings('&error=' + encodeURIComponent(json.error || ('HTTP ' + res.status)));
        }
    } catch (e) {
        goSettings('&error=' + encodeURIComponent(e.message || 'network'));
    }
})();
</script>
</body></html>
