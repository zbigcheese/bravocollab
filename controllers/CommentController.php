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

        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        // If replying to a reply, flatten to the root comment
        $db = Database::get();
        if ($parentId) {
            $parentStmt = $db->prepare('SELECT id, parent_id FROM comments WHERE id = :id AND card_id = :cid');
            $parentStmt->execute(['id' => $parentId, 'cid' => $cardId]);
            $parent = $parentStmt->fetch();
            if (!$parent) {
                $parentId = null;
            } elseif ($parent['parent_id']) {
                // Parent is itself a reply — flatten to root
                $parentId = (int) $parent['parent_id'];
            }
        }

        $db->prepare('INSERT INTO comments (card_id, user_id, parent_id, body) VALUES (:card_id, :user_id, :parent_id, :body)')
           ->execute(['card_id' => $cardId, 'user_id' => Auth::userId(), 'parent_id' => $parentId, 'body' => $body]);
        $commentId = (int) $db->lastInsertId();

        // Notify card assignees + watchers + the coordinator (only if they
        // opted in via user_preferences.notify_coordinator_cards). The actor
        // is filtered out by createNotification, so self-comments don't
        // notify yourself.
        $recipients = $db->prepare(
            'SELECT user_id FROM card_assignments WHERE card_id = :cid_a
             UNION
             SELECT user_id FROM card_watchers WHERE card_id = :cid_w
             UNION
             SELECT c.coordinator_id AS user_id
             FROM cards c
             LEFT JOIN user_preferences up ON up.user_id = c.coordinator_id
             WHERE c.id = :cid_c
               AND c.coordinator_id IS NOT NULL
               AND COALESCE(up.notify_coordinator_cards, 0) = 1'
        );
        $recipients->execute(['cid_a' => $cardId, 'cid_w' => $cardId, 'cid_c' => $cardId]);

        $stmt = $db->prepare('SELECT title FROM cards WHERE id = :id');
        $stmt->execute(['id' => $cardId]);
        $cardTitle = ($stmt->fetch())['title'] ?? '';

        // Truncate the comment body so notification JSON stays reasonable and
        // email digests remain readable even for very long comments.
        $bodyForNotif = mb_strlen($body) > 500 ? mb_substr($body, 0, 500) . '…' : $body;

        foreach ($recipients->fetchAll() as $row) {
            $this->createNotification($row['user_id'], NOTIF_COMMENT_ADDED, [
                'board_id'   => $boardId,
                'card_id'    => $cardId,
                'card_title' => $cardTitle,
                'actor_name' => Auth::userName(),
                'body'       => $bodyForNotif,
            ]);
        }

        // Check for @mentions — match against actual board member names
        $boardMembers = $db->prepare(
            'SELECT u.id, u.display_name FROM board_members bm
             JOIN users u ON bm.user_id = u.id
             WHERE bm.board_id = :bid'
        );
        $boardMembers->execute(['bid' => $boardId]);
        foreach ($boardMembers->fetchAll() as $member) {
            if (mb_stripos($body, '@' . $member['display_name']) !== false) {
                $this->createNotification((int) $member['id'], NOTIF_COMMENT_MENTION, [
                    'board_id'   => $boardId,
                    'card_id'    => $cardId,
                    'card_title' => $cardTitle,
                    'actor_name' => Auth::userName(),
                    'body'       => $bodyForNotif,
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

        // Editing is strictly own-comments — admins included. Admins can
        // still delete (handled in delete()) but never rewrite someone
        // else's words under their own name.
        if ((int) $comment['user_id'] !== Auth::userId()) {
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

        // Strictly own-comments — admins included. Same rationale as edit:
        // even moderators shouldn't silently remove someone else's words.
        if ((int) $comment['user_id'] !== Auth::userId()) {
            $this->json(['error' => 'You can only delete your own comments'], 403);
            return;
        }

        $db->prepare('DELETE FROM comments WHERE id = :id')->execute(['id' => $commentId]);

        $boardId = $this->getBoardIdForCard($comment['card_id']);
        $this->publishSSE($boardId, SSE_COMMENT_DELETED, ['comment_id' => $commentId, 'card_id' => $comment['card_id']]);

        $this->json(['success' => true]);
    }
}
