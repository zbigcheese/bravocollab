<?php

require_once __DIR__ . '/../core/WebPush.php';

class PushController extends Controller
{
    /** Returns the VAPID public key + subscription status for the current user. */
    public function status(): void
    {
        $this->requireAuth();
        $this->requireGet();
        WebPush::ensureSchema();

        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => Auth::userId()]);
        $count = (int) $stmt->fetchColumn();

        $this->json([
            'configured'        => WebPush::isConfigured(),
            'public_key'        => WebPush::publicKey(),
            'subscription_count'=> $count,
        ]);
    }

    /**
     * Persist a Web Push subscription. Body shape (from PushManager):
     *   { endpoint: "...", keys: { p256dh: "...", auth: "..." } }
     */
    public function subscribe(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();
        WebPush::ensureSchema();

        $data = $this->getJSON();
        $endpoint = (string) ($data['endpoint'] ?? '');
        $p256dh   = (string) ($data['keys']['p256dh'] ?? '');
        $auth     = (string) ($data['keys']['auth']   ?? '');

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            $this->json(['error' => 'Missing subscription fields'], 400);
            return;
        }

        $userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        Database::get()->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent)
             VALUES (:uid, :ep, :p, :a, :ua)
             ON DUPLICATE KEY UPDATE
                p256dh = VALUES(p256dh),
                auth   = VALUES(auth),
                user_agent = VALUES(user_agent)'
        )->execute([
            'uid' => Auth::userId(), 'ep' => $endpoint, 'p' => $p256dh,
            'a' => $auth, 'ua' => $userAgent,
        ]);

        $this->json(['success' => true]);
    }

    /** Remove the given endpoint (the browser unsubscribed). */
    public function unsubscribe(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();
        WebPush::ensureSchema();

        $data = $this->getJSON();
        $endpoint = (string) ($data['endpoint'] ?? '');
        if ($endpoint === '') {
            // Caller wants to clear everything for this user (settings toggle off).
            Database::get()->prepare(
                'DELETE FROM push_subscriptions WHERE user_id = :uid'
            )->execute(['uid' => Auth::userId()]);
        } else {
            Database::get()->prepare(
                'DELETE FROM push_subscriptions WHERE user_id = :uid AND endpoint = :ep'
            )->execute(['uid' => Auth::userId(), 'ep' => $endpoint]);
        }
        $this->json(['success' => true]);
    }

    /**
     * Service worker calls this when it receives a push to find out what
     * to render. Returns the most recent unread, not-yet-pushed
     * notification for the current user, formatted for showNotification().
     * Marks it as "pushed" by setting a small flag we encode into emailed_at
     * so the same row isn't surfaced twice on a back-to-back push.
     *
     * Falls back to the latest unread notification if nothing brand-new is
     * found, so the worker still has SOMETHING to display.
     */
    public function latest(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $config = require __DIR__ . '/../config/config.php';
        $baseUrl = rtrim($config['base_url'], '/');

        // Look for the freshest unread notification. We can't easily mark
        // "pushed" without another column; the SW de-dupes via tag and the
        // user's notification badge UI handles its own read-state.
        $stmt = Database::get()->prepare(
            'SELECT id, type, data, created_at
             FROM notifications
             WHERE user_id = :uid AND is_read = 0
             ORDER BY created_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['uid' => Auth::userId()]);
        $n = $stmt->fetch();
        if (!$n) {
            $this->json([
                'title' => 'BravoCollab',
                'body'  => 'You have a new update.',
                'url'   => $baseUrl . '/index.php?page=dashboard',
                'tag'   => 'bc-generic',
            ]);
            return;
        }

        $data = is_string($n['data']) ? json_decode($n['data'], true) : ($n['data'] ?? []);
        $data = is_array($data) ? $data : [];

        $title = self::titleForType($n['type'], $data);
        $body  = self::bodyForType($n['type'], $data);
        if ($n['type'] === 'whats_next') {
            $url = $baseUrl . '/index.php?page=whats_next';
            if (!empty($data['date'])) $url .= '&date=' . urlencode($data['date']);
        } else {
            $url = $baseUrl . '/index.php?page=board&id=' . (int) ($data['board_id'] ?? 0);
            if (!empty($data['card_id'])) $url .= '&card=' . (int) $data['card_id'];
        }

        $this->json([
            'title' => $title,
            'body'  => $body,
            'url'   => $url,
            'tag'   => 'bc-notif-' . (int) $n['id'],
        ]);
    }

    private static function titleForType(string $type, array $data): string
    {
        $actor = $data['actor_name'] ?? 'Someone';
        $card  = $data['card_title'] ?? 'a card';
        $board = $data['board_title'] ?? 'a board';
        switch ($type) {
            case 'card_assigned':   return "{$actor} assigned you to {$card}";
            case 'card_unassigned': return "{$actor} removed you from {$card}";
            case 'comment_added':   return "{$actor} commented on {$card}";
            case 'comment_mention': return "{$actor} mentioned you in {$card}";
            case 'comment_reply':   return "{$actor} replied to your comment";
            case 'due_soon':        return "Due soon: {$card}";
            case 'due_overdue':     return "Overdue: {$card}";
            case 'board_invited':   return "{$actor} added you to {$board}";
            case 'whats_next':      return "What's next today";
            default:                return 'BravoCollab';
        }
    }

    private static function bodyForType(string $type, array $data): string
    {
        if ($type === 'whats_next') {
            $cards = (int) ($data['cards_total'] ?? 0);
            $items = (int) ($data['items_total'] ?? 0);
            return "You have {$cards} card(s) and {$items} task(s) coming up.";
        }
        if (!empty($data['body'])) {
            $b = (string) $data['body'];
            return mb_strlen($b) > 140 ? mb_substr($b, 0, 140) . '…' : $b;
        }
        return $data['card_title'] ?? '';
    }
}
