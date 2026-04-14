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
}
