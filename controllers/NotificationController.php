<?php

class NotificationController extends Controller
{
    public function list(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute(['uid' => Auth::userId()]);

        $this->json(['notifications' => $stmt->fetchAll()]);
    }

    public function count(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0'
        );
        $stmt->execute(['uid' => Auth::userId()]);

        $this->json(['count' => (int) $stmt->fetchColumn()]);
    }

    public function markRead(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid')
           ->execute(['id' => $id, 'uid' => Auth::userId()]);

        $this->json(['success' => true]);
    }

    public function markAllRead(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $db = Database::get();
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0')
           ->execute(['uid' => Auth::userId()]);

        $this->json(['success' => true]);
    }

    /**
     * Mark every unread notification referencing the given card as read.
     * Triggered whenever the user opens a card (board click, deep-link from
     * an email, etc.) so the bell badge clears automatically once the
     * relevant card has been viewed.
     */
    public function markCardRead(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        if (!$cardId) {
            $this->json(['error' => 'card_id required'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare(
            "UPDATE notifications
             SET is_read = 1
             WHERE user_id = :uid
               AND is_read = 0
               AND JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.card_id')) = :cid"
        );
        $stmt->execute(['uid' => Auth::userId(), 'cid' => (string) $cardId]);

        $this->json(['success' => true, 'marked' => $stmt->rowCount()]);
    }
}
