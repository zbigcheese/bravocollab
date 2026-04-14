<?php

class CommentController extends Controller
{
    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $body = trim($data['body'] ?? '');

        if (!$cardId || empty($body)) {
            $this->json(['error' => 'Card ID and comment body are required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $db->prepare('INSERT INTO comments (card_id, user_id, body) VALUES (:card_id, :user_id, :body)')
           ->execute(['card_id' => $cardId, 'user_id' => Auth::userId(), 'body' => $body]);
        $commentId = (int) $db->lastInsertId();

        // Notify card assignees
        $assignees = $db->prepare('SELECT user_id FROM card_assignments WHERE card_id = :cid');
        $assignees->execute(['cid' => $cardId]);
        $stmt = $db->prepare('SELECT title FROM cards WHERE id = :id');
        $stmt->execute(['id' => $cardId]);
        $cardTitle = ($stmt->fetch())['title'] ?? '';

        foreach ($assignees->fetchAll() as $row) {
            $this->createNotification($row['user_id'], NOTIF_COMMENT_ADDED, [
                'board_id'   => $boardId,
                'card_id'    => $cardId,
                'card_title' => $cardTitle,
                'actor_name' => Auth::userName(),
            ]);
        }

        // Check for @mentions
        if (preg_match_all('/@(\w+)/', $body, $matches)) {
            $mentioned = $db->prepare(
                'SELECT id FROM users WHERE display_name IN (' . implode(',', array_fill(0, count($matches[1]), '?')) . ')'
            );
            $mentioned->execute($matches[1]);
            foreach ($mentioned->fetchAll() as $user) {
                $this->createNotification($user['id'], NOTIF_COMMENT_MENTION, [
                    'board_id'   => $boardId,
                    'card_id'    => $cardId,
                    'card_title' => $cardTitle,
                    'actor_name' => Auth::userName(),
                ]);
            }
        }

        $this->publishSSE($boardId, SSE_COMMENT_ADDED, [
            'card_id'    => $cardId,
            'comment_id' => $commentId,
        ]);

        $this->logActivity($boardId, $cardId, 'comment_added', ['body_preview' => mb_substr($body, 0, 100)]);

        $this->json(['success' => true, 'comment_id' => $commentId]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $commentId = (int) ($data['id'] ?? 0);
        $body = trim($data['body'] ?? '');

        if (empty($body)) {
            $this->json(['error' => 'Comment body is required'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM comments WHERE id = :id');
        $stmt->execute(['id' => $commentId]);
        $comment = $stmt->fetch();
        if (!$comment) {
            $this->json(['error' => 'Comment not found'], 404);
            return;
        }

        // Only own comments (or admin)
        if ($comment['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'You can only edit your own comments'], 403);
            return;
        }

        $db->prepare('UPDATE comments SET body = :body, is_edited = 1 WHERE id = :id')
           ->execute(['body' => $body, 'id' => $commentId]);

        $boardId = $this->getBoardIdForCard($comment['card_id']);
        $this->publishSSE($boardId, SSE_COMMENT_UPDATED, ['comment_id' => $commentId, 'card_id' => $comment['card_id']]);

        $this->json(['success' => true]);
    }

    public function delete(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $commentId = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM comments WHERE id = :id');
        $stmt->execute(['id' => $commentId]);
        $comment = $stmt->fetch();

        if (!$comment) {
            $this->json(['error' => 'Comment not found'], 404);
            return;
        }

        if ($comment['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'You can only delete your own comments'], 403);
            return;
        }

        $db->prepare('DELETE FROM comments WHERE id = :id')->execute(['id' => $commentId]);

        $boardId = $this->getBoardIdForCard($comment['card_id']);
        $this->publishSSE($boardId, SSE_COMMENT_DELETED, ['comment_id' => $commentId, 'card_id' => $comment['card_id']]);

        $this->json(['success' => true]);
    }
}
