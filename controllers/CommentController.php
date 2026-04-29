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
        // Capture the comment the user actually clicked Reply on, BEFORE
        // flattening reassigns parent_id to the thread root. The reply
        // notification should target the person whose comment was directly
        // replied to, not the original thread author.
        $directlyRepliedToId = $parentId ?: null;

        // If replying to a reply, flatten to the root comment
        $db = Database::get();
        if ($parentId) {
            $parentStmt = $db->prepare('SELECT id, parent_id FROM comments WHERE id = :id AND card_id = :cid');
            $parentStmt->execute(['id' => $parentId, 'cid' => $cardId]);
            $parent = $parentStmt->fetch();
            if (!$parent) {
                $parentId = null;
                $directlyRepliedToId = null;
            } elseif ($parent['parent_id']) {
                // Parent is itself a reply — flatten storage to root, but
                // keep $directlyRepliedToId unchanged for notification routing.
                $parentId = (int) $parent['parent_id'];
            }
        }

        // Resolve the author of the comment that was directly replied to.
        // They get a NOTIF_COMMENT_REPLY regardless of their assignment /
        // watcher / coordinator status on the card.
        $replyTargetUserId = null;
        if ($directlyRepliedToId) {
            $rStmt = $db->prepare('SELECT user_id FROM comments WHERE id = :id LIMIT 1');
            $rStmt->execute(['id' => $directlyRepliedToId]);
            $rRow = $rStmt->fetch();
            $replyTargetUserId = $rRow ? (int) $rRow['user_id'] : null;
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

        // ---- Single-notification-per-user dispatch ----
        // A single comment can match a recipient via multiple paths
        // (assignee + mentioned, coordinator + mentioned, etc.). Without
        // dedup the user gets one notification per matched path. Resolve
        // each user to ONE most-specific notification:
        //   reply target  → NOTIF_COMMENT_REPLY    (highest priority)
        //   mentioned     → NOTIF_COMMENT_MENTION
        //   union member  → NOTIF_COMMENT_ADDED    (lowest priority)

        $unionIds = [];
        foreach ($recipients->fetchAll() as $row) {
            $unionIds[(int) $row['user_id']] = true;
        }

        // Mentioned-board-members lookup.
        $mentionedIds = [];
        $boardMembers = $db->prepare(
            'SELECT u.id, u.display_name FROM board_members bm
             JOIN users u ON bm.user_id = u.id
             WHERE bm.board_id = :bid'
        );
        $boardMembers->execute(['bid' => $boardId]);
        foreach ($boardMembers->fetchAll() as $member) {
            if (mb_stripos($body, '@' . $member['display_name']) !== false) {
                $mentionedIds[(int) $member['id']] = true;
            }
        }

        $notifPayload = [
            'board_id'   => $boardId,
            'card_id'    => $cardId,
            'card_title' => $cardTitle,
            'actor_name' => Auth::userName(),
            'body'       => $bodyForNotif,
        ];

        // Send the highest-priority type for each recipient. Build the union
        // of all candidate users, then dispatch each exactly once.
        $allRecipientIds = $unionIds + $mentionedIds;
        if ($replyTargetUserId !== null) {
            $allRecipientIds[$replyTargetUserId] = true;
        }
        foreach (array_keys($allRecipientIds) as $rid) {
            if ($replyTargetUserId !== null && $rid === $replyTargetUserId) {
                $type = NOTIF_COMMENT_REPLY;
            } elseif (isset($mentionedIds[$rid])) {
                $type = NOTIF_COMMENT_MENTION;
            } else {
                $type = NOTIF_COMMENT_ADDED;
            }
            $this->createNotification($rid, $type, $notifPayload);
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
