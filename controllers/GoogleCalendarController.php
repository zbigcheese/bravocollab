<?php

require_once __DIR__ . '/../core/GoogleCalendar.php';

class GoogleCalendarController extends Controller
{
    public function status(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $configured = GoogleCalendar::isConfigured();
        $connected  = $configured && GoogleCalendar::isConnected(Auth::userId());
        $account    = $connected ? GoogleCalendar::getAccount(Auth::userId()) : null;

        $this->json([
            'configured'   => $configured,
            'connected'    => $connected,
            'connected_at' => $account['connected_at'] ?? null,
        ]);
    }

    public function disconnect(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        try {
            GoogleCalendar::disconnect(Auth::userId());
            $this->json(['success' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * JS handoff endpoint. The OAuth callback page reads code+state from the
     * URL fragment, base64-encodes a JSON object {code, state}, and POSTs
     * here. Encoding keeps "https://" out of the request body so the host's
     * WAF doesn't reject it.
     */
    public function oauthFinish(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $payload = (string) ($data['payload'] ?? '');
        if ($payload === '') {
            $this->json(['error' => 'Missing payload'], 400);
            return;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            $this->json(['error' => 'Invalid base64 payload'], 400);
            return;
        }
        $parsed = json_decode($decoded, true);
        if (!is_array($parsed)) {
            $this->json(['error' => 'Invalid JSON payload'], 400);
            return;
        }

        $code  = (string) ($parsed['code']  ?? '');
        $state = (string) ($parsed['state'] ?? '');
        if ($code === '' || $state === '') {
            $this->json(['error' => 'Missing code or state'], 400);
            return;
        }

        try {
            GoogleCalendar::handleCallback(Auth::userId(), $code, $state);
            $this->json(['success' => true]);
        } catch (Throwable $e) {
            error_log('Google oauthFinish failed: ' . $e->getMessage());
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function syncNow(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        if (!GoogleCalendar::isConnected(Auth::userId())) {
            $this->json(['error' => 'Not connected to Google Calendar'], 400);
            return;
        }

        try {
            $result = GoogleCalendar::syncUser(Auth::userId());
            $this->json(['success' => true, 'result' => $result]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }
}
