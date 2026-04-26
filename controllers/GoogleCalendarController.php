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
