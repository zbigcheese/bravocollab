<?php
/**
 * TaskController — card-scoped tasks/subtasks (one level of nesting) with
 * their own assignees, due date, attachments, and comment thread.
 *
 * Authorization mirrors the card layer: board membership (or admin) gives
 * full access. The card creator and site admins can edit any task's title
 * or description; everyone else can only edit tasks they created. Accepting
 * a task is an assignee-only action (admins cannot accept on someone else's
 * behalf — the whole point of acceptance is the assignee's own ack).
 *
 * Acceptance writes task_assignments.accepted_at and notifies the card
 * coordinator (single recipient).
 *
 * Card clone reuses cloneForCard() which copies the task hierarchy and
 * resets assignees / due dates / comments / attachments to match the spec.
 */

class TaskController extends Controller
{
    /**
     * Tree of tasks for a card, with assignees and counts. Children are
     * nested under parent_task_id (one level deep, enforced server-side).
     */
    public function list(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $cardId = (int) ($_GET['card_id'] ?? 0);
        if (!$cardId) {
            $this->json(['error' => 'card_id required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $this->json(['tasks' => $this->buildTaskTree($cardId)]);
    }

    /** Single task + full detail (description, assignees, comments, attachments, children). */
    public function get(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $taskId = (int) ($_GET['id'] ?? 0);
        if (!$taskId) {
            $this->json(['error' => 'Task ID required'], 400);
            return;
        }

        $task = $this->loadTask($taskId);
        if (!$task) {
            $this->json(['error' => 'Task not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        $task = $this->hydrateTask($task);
        $task['board_id'] = $boardId;
        $task['children'] = $this->buildTaskTree((int) $task['card_id'], (int) $task['id']);

        // Parent chain for the breadcrumb (root → ... → this task).
        $task['breadcrumb'] = $this->buildBreadcrumb($task);

        $this->json(['task' => $task]);
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $parentId = !empty($data['parent_task_id']) ? (int) $data['parent_task_id'] : null;
        $title = trim((string) ($data['title'] ?? ''));

        if (!$cardId || $title === '') {
            $this->json(['error' => 'card_id and title are required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        // Enforce one level of nesting — a sub-task cannot itself have
        // sub-tasks. If the requested parent is already a child, reject.
        if ($parentId !== null) {
            $parent = $this->loadTask($parentId);
            if (!$parent || (int) $parent['card_id'] !== $cardId) {
                $this->json(['error' => 'Parent task not found on this card'], 400);
                return;
            }
            if (!empty($parent['parent_task_id'])) {
                $this->json(['error' => 'Sub-tasks cannot have their own sub-tasks'], 400);
                return;
            }
        }

        $db = Database::get();
        $posStmt = $db->prepare(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM tasks
             WHERE card_id = :cid AND (parent_task_id <=> :pid)'
        );
        $posStmt->execute(['cid' => $cardId, 'pid' => $parentId]);
        $pos = (int) $posStmt->fetchColumn();

        $db->prepare(
            'INSERT INTO tasks (card_id, parent_task_id, title, description, position, created_by)
             VALUES (:cid, :pid, :title, :desc, :pos, :by)'
        )->execute([
            'cid'   => $cardId,
            'pid'   => $parentId,
            'title' => $title,
            'desc'  => $data['description'] ?? null,
            'pos'   => $pos,
            'by'    => Auth::userId(),
        ]);
        $taskId = (int) $db->lastInsertId();

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => $cardId]);
        $this->logActivity($boardId, $cardId, 'task_created', ['task_id' => $taskId, 'title' => $title]);

        $this->json(['success' => true, 'task_id' => $taskId]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['id'] ?? 0);

        $task = $this->loadTask($taskId);
        if (!$task) {
            $this->json(['error' => 'Task not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        // Title/description are creator-only edits (admins included, but only
        // the task's own creator OR a site admin). Operational fields
        // (due_date, is_complete) are open to anyone with board access.
        $editsAuthorial = isset($data['title']) || array_key_exists('description', $data);
        if ($editsAuthorial
            && (int) $task['created_by'] !== Auth::userId()
            && !Auth::isAdmin()
        ) {
            $this->json(['error' => 'Only the task creator can edit the title or description'], 403);
            return;
        }

        $updates = [];
        $params  = ['id' => $taskId];

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                $this->json(['error' => 'Title is required'], 400);
                return;
            }
            $updates[] = '`title` = :title';
            $params['title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $updates[] = '`description` = :desc';
            $params['desc'] = $data['description'];
        }
        if (array_key_exists('due_date', $data)) {
            $updates[] = '`due_date` = :due';
            $params['due'] = $data['due_date'] ?: null;
        }
        if (isset($data['is_complete'])) {
            $newComplete = $data['is_complete'] ? 1 : 0;
            $updates[] = '`is_complete`  = :ic';
            $updates[] = '`completed_by` = :cb';
            $updates[] = '`completed_at` = :ca';
            $params['ic'] = $newComplete;
            $params['cb'] = $newComplete ? Auth::userId() : null;
            $params['ca'] = $newComplete ? date('Y-m-d H:i:s') : null;
        }

        if (empty($updates)) {
            $this->json(['error' => 'Nothing to update'], 400);
            return;
        }

        Database::get()
            ->prepare('UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = :id')
            ->execute($params);

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true]);

        // Due date / completion / title all feed the Google Calendar event.
        $this->pushGoogleSync('task', $taskId);
    }

    public function delete(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['id'] ?? 0);

        $task = $this->loadTask($taskId);
        if (!$task) {
            $this->json(['error' => 'Task not found'], 404);
            return;
        }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        // Only the creator OR a site admin can delete (matches card-comment policy).
        if ((int) $task['created_by'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'Only the task creator can delete this task'], 403);
            return;
        }

        // Collect sub-task ids before the FK cascade removes them — their
        // Google events need reconciling too, and afterwards the rows are gone.
        $childStmt = Database::get()->prepare(
            'SELECT id FROM tasks WHERE parent_task_id = :pid'
        );
        $childStmt->execute(['pid' => $taskId]);
        $affected = array_map('intval', $childStmt->fetchAll(PDO::FETCH_COLUMN));
        $affected[] = $taskId;

        Database::get()->prepare('DELETE FROM tasks WHERE id = :id')
            ->execute(['id' => $taskId]);

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true]);

        // syncTaskForAll still resolves recipients from the existing event
        // mappings, so deleted tasks drop out of connected Google calendars.
        $this->pushGoogleSync('task', $affected);
    }

    public function assign(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['task_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        $task = $this->loadTask($taskId);
        if (!$task) { $this->json(['error' => 'Task not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        Database::get()->prepare(
            'INSERT IGNORE INTO task_assignments (task_id, user_id) VALUES (:tid, :uid)'
        )->execute(['tid' => $taskId, 'uid' => $userId]);

        // Notify assignee.
        $cardStmt = Database::get()->prepare('SELECT title FROM cards WHERE id = :id');
        $cardStmt->execute(['id' => $task['card_id']]);
        $cardTitle = ($cardStmt->fetch())['title'] ?? '';

        $this->createNotification($userId, NOTIF_TASK_ASSIGNED, [
            'board_id'   => $boardId,
            'card_id'    => (int) $task['card_id'],
            'card_title' => $cardTitle,
            'task_id'    => $taskId,
            'task_title' => $task['title'],
            'actor_name' => Auth::userName(),
        ]);

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true]);

        // New assignee → the task appears in their Google calendar.
        $this->pushGoogleSync('task', $taskId);
    }

    public function unassign(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['task_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        $task = $this->loadTask($taskId);
        if (!$task) { $this->json(['error' => 'Task not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        Database::get()->prepare(
            'DELETE FROM task_assignments WHERE task_id = :tid AND user_id = :uid'
        )->execute(['tid' => $taskId, 'uid' => $userId]);

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true]);

        // Removed assignee → the event is dropped from their Google calendar.
        $this->pushGoogleSync('task', $taskId);
    }

    /**
     * Acceptance: write task_assignments.accepted_at = NOW() for the calling
     * user, notify the card coordinator. Idempotent — already-accepted is a
     * no-op success. Only assignees can accept their own slot.
     */
    public function accept(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['task_id'] ?? 0);

        $task = $this->loadTask($taskId);
        if (!$task) { $this->json(['error' => 'Task not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT accepted_at FROM task_assignments
             WHERE task_id = :tid AND user_id = :uid'
        );
        $stmt->execute(['tid' => $taskId, 'uid' => Auth::userId()]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->json(['error' => 'You are not assigned to this task'], 403);
            return;
        }
        if (!empty($row['accepted_at'])) {
            $this->json(['success' => true, 'already_accepted' => true, 'accepted_at' => $row['accepted_at']]);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $db->prepare(
            'UPDATE task_assignments SET accepted_at = :at
             WHERE task_id = :tid AND user_id = :uid'
        )->execute(['at' => $now, 'tid' => $taskId, 'uid' => Auth::userId()]);

        // Notify the card coordinator.
        $coordStmt = $db->prepare('SELECT coordinator_id, title FROM cards WHERE id = :id');
        $coordStmt->execute(['id' => $task['card_id']]);
        $card = $coordStmt->fetch();
        $coordId = (int) ($card['coordinator_id'] ?? 0);
        if ($coordId > 0) {
            $this->createNotification($coordId, NOTIF_TASK_ACCEPTED, [
                'board_id'   => $boardId,
                'card_id'    => (int) $task['card_id'],
                'card_title' => $card['title'] ?? '',
                'task_id'    => $taskId,
                'task_title' => $task['title'],
                'actor_name' => Auth::userName(),
            ]);
        }

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true, 'accepted_at' => $now]);
    }

    // ---- Task comments ----

    public function addComment(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $taskId = (int) ($data['task_id'] ?? 0);
        $body   = trim((string) ($data['body'] ?? ''));

        if ($body === '') {
            $this->json(['error' => 'Body required'], 400);
            return;
        }

        $task = $this->loadTask($taskId);
        if (!$task) { $this->json(['error' => 'Task not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $db->prepare(
            'INSERT INTO task_comments (task_id, user_id, body) VALUES (:tid, :uid, :body)'
        )->execute(['tid' => $taskId, 'uid' => Auth::userId(), 'body' => $body]);

        $commentId = (int) $db->lastInsertId();

        // Notify mentioned users — same algorithm as CommentController:
        // resolve @<display_name> tokens against board members and notify
        // each unique mentioned user once.
        $cardStmt = $db->prepare('SELECT title FROM cards WHERE id = :id');
        $cardStmt->execute(['id' => $task['card_id']]);
        $cardTitle = ($cardStmt->fetch())['title'] ?? '';

        $mentionPayload = [
            'board_id'   => $boardId,
            'card_id'    => (int) $task['card_id'],
            'card_title' => $cardTitle,
            'task_id'    => $taskId,
            'task_title' => $task['title'],
            'actor_name' => Auth::userName(),
            'body'       => $body,
        ];
        foreach ($this->resolveMentions($body, $boardId) as $mentionedUserId) {
            $this->createNotification($mentionedUserId, NOTIF_COMMENT_MENTION, $mentionPayload);
        }

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json(['success' => true, 'comment_id' => $commentId]);
    }

    /**
     * Scan the comment body for @<display_name> tokens that match a board
     * member (or admin) and return the unique set of user IDs. The actor
     * (current user) is excluded — self-mentions don't notify. Matching is
     * longest-name-first so that "@Anna Smith" doesn't get stolen by an
     * earlier "@Anna" match.
     *
     * @return int[]
     */
    private function resolveMentions(string $body, int $boardId): array
    {
        // Pool of names eligible to be mentioned: board members + site admins.
        $stmt = Database::get()->prepare(
            'SELECT u.id, u.display_name
             FROM board_members bm
             JOIN users u ON bm.user_id = u.id
             WHERE bm.board_id = :bid
             UNION
             SELECT id, display_name FROM users WHERE role = "admin"'
        );
        $stmt->execute(['bid' => $boardId]);
        $candidates = $stmt->fetchAll();
        if (!$candidates) return [];

        // Sort longest name first so overlapping names match deterministically.
        usort($candidates, static fn($a, $b) => mb_strlen($b['display_name']) <=> mb_strlen($a['display_name']));

        $matched = [];
        $remaining = $body;
        foreach ($candidates as $u) {
            $name = $u['display_name'];
            if ($name === '' || (int) $u['id'] === Auth::userId()) continue;
            // Word-boundary-ish match: require non-name-char after, or end of string.
            // Tokenization mirrors the JS side (which only checks for the literal '@<name>' string).
            $needle = '@' . $name;
            if (mb_stripos($remaining, $needle) !== false) {
                $matched[(int) $u['id']] = true;
                // Strip the matched name so a substring of it can't double-count.
                $remaining = str_ireplace($needle, '', $remaining);
            }
        }
        return array_keys($matched);
    }

    public function updateComment(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $commentId = (int) ($data['id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            $this->json(['error' => 'Body required'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT tc.*, t.card_id FROM task_comments tc
             JOIN tasks t ON tc.task_id = t.id WHERE tc.id = :id'
        );
        $stmt->execute(['id' => $commentId]);
        $row = $stmt->fetch();
        if (!$row) { $this->json(['error' => 'Comment not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $row['card_id']);
        $this->requireBoardAccess($boardId);

        if ((int) $row['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'Cannot edit another user\'s comment'], 403);
            return;
        }

        $db->prepare(
            'UPDATE task_comments SET body = :body, is_edited = 1 WHERE id = :id'
        )->execute(['body' => $body, 'id' => $commentId]);

        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $row['card_id']]);

        $this->json(['success' => true]);
    }

    public function deleteComment(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $commentId = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT tc.*, t.card_id FROM task_comments tc
             JOIN tasks t ON tc.task_id = t.id WHERE tc.id = :id'
        );
        $stmt->execute(['id' => $commentId]);
        $row = $stmt->fetch();
        if (!$row) { $this->json(['error' => 'Comment not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $row['card_id']);
        $this->requireBoardAccess($boardId);

        if ((int) $row['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'Cannot delete another user\'s comment'], 403);
            return;
        }

        // Best-effort cleanup of disk files for any attachments owned by this
        // comment. The DB rows cascade via FK; the files would otherwise leak.
        require_once __DIR__ . '/../core/FileUpload.php';
        $aStmt = $db->prepare(
            'SELECT stored_name FROM task_attachments WHERE task_comment_id = :id'
        );
        $aStmt->execute(['id' => $commentId]);
        foreach ($aStmt->fetchAll() as $a) {
            FileUpload::deleteFile($a['stored_name']);
        }

        $db->prepare('DELETE FROM task_comments WHERE id = :id')->execute(['id' => $commentId]);
        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $row['card_id']]);

        $this->json(['success' => true]);
    }

    // ---- Task attachments ----

    public function uploadAttachment(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $taskId = (int) ($_POST['task_id'] ?? 0);
        if (!$taskId) {
            $this->json(['error' => 'task_id required'], 400);
            return;
        }
        $task = $this->loadTask($taskId);
        if (!$task) { $this->json(['error' => 'Task not found'], 404); return; }
        $boardId = $this->getBoardIdForCard((int) $task['card_id']);
        $this->requireBoardAccess($boardId);

        // Optional task_comment_id — when present, the upload belongs to
        // the comment thread rather than the task itself. Validate it
        // actually lives on this task to prevent cross-task injection.
        $commentId = (isset($_POST['task_comment_id']) && $_POST['task_comment_id'] !== '')
            ? (int) $_POST['task_comment_id']
            : null;
        if ($commentId !== null) {
            $db0 = Database::get();
            $cs = $db0->prepare('SELECT id FROM task_comments WHERE id = :id AND task_id = :tid LIMIT 1');
            $cs->execute(['id' => $commentId, 'tid' => $taskId]);
            if (!$cs->fetch()) {
                $this->json(['error' => 'Comment not found on this task'], 400);
                return;
            }
        }

        if (empty($_FILES['file'])) {
            $this->json(['error' => 'No file uploaded'], 400);
            return;
        }

        require_once __DIR__ . '/../core/FileUpload.php';
        try {
            $fileData = FileUpload::handle($_FILES['file']);
        } catch (RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 400);
            return;
        }

        $db = Database::get();
        $db->prepare(
            'INSERT INTO task_attachments
                (task_id, task_comment_id, user_id, original_name, stored_name, file_size, mime_type, is_image, thumbnail_path)
             VALUES (:tid, :tcid, :uid, :on, :sn, :fs, :mt, :ii, :tp)'
        )->execute([
            'tid'  => $taskId,
            'tcid' => $commentId,
            'uid'  => Auth::userId(),
            'on'   => $fileData['original_name'], 'sn' => $fileData['stored_name'],
            'fs'   => $fileData['file_size'],     'mt' => $fileData['mime_type'],
            'ii'   => $fileData['is_image'],      'tp' => $fileData['thumbnail_path'],
        ]);

        $attId = (int) $db->lastInsertId();
        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $task['card_id']]);

        $this->json([
            'success' => true,
            'attachment' => [
                'id'              => $attId,
                'task_comment_id' => $commentId,
                'original_name'   => $fileData['original_name'],
                'stored_name'     => $fileData['stored_name'],
                'mime_type'       => $fileData['mime_type'],
                'is_image'        => $fileData['is_image'],
                'file_size'       => $fileData['file_size'],
                'thumbnail_path'  => $fileData['thumbnail_path'],
            ],
        ]);
    }

    public function downloadAttachment(): void
    {
        $this->requireAuth();

        $id = (int) ($_GET['id'] ?? 0);
        $thumb = isset($_GET['thumb']);

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT ta.*, t.card_id FROM task_attachments ta
             JOIN tasks t ON ta.task_id = t.id WHERE ta.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $att = $stmt->fetch();
        if (!$att) {
            http_response_code(404); echo 'File not found'; return;
        }
        $boardId = $this->getBoardIdForCard((int) $att['card_id']);
        $this->requireBoardAccess($boardId);

        $config = require __DIR__ . '/../config/config.php';
        if ($thumb && !empty($att['thumbnail_path'])) {
            $filePath = $config['upload_dir'] . '/thumbnails/' . $att['thumbnail_path'];
            $mimeType = 'image/jpeg';
            $fileName = 'thumb_' . $att['original_name'];
        } else {
            $filePath = $config['upload_dir'] . '/attachments/' . $att['stored_name'];
            $mimeType = $att['mime_type'];
            $fileName = $att['original_name'];
        }

        if (!file_exists($filePath)) {
            http_response_code(404); echo 'File not found on disk'; return;
        }

        if ($att['is_image']) header('Cache-Control: public, max-age=86400');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        $disp = ($att['is_image'] || $thumb) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disp . '; filename="' . addslashes($fileName) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    public function deleteAttachment(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT ta.*, t.card_id FROM task_attachments ta
             JOIN tasks t ON ta.task_id = t.id WHERE ta.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $att = $stmt->fetch();
        if (!$att) { $this->json(['error' => 'Attachment not found'], 404); return; }

        $boardId = $this->getBoardIdForCard((int) $att['card_id']);
        $this->requireBoardAccess($boardId);

        if ((int) $att['user_id'] !== Auth::userId() && !Auth::isAdmin()) {
            $this->json(['error' => 'Cannot delete another user\'s attachment'], 403);
            return;
        }

        require_once __DIR__ . '/../core/FileUpload.php';
        FileUpload::deleteFile($att['stored_name']);
        $db->prepare('DELETE FROM task_attachments WHERE id = :id')->execute(['id' => $id]);
        $this->publishSSE($boardId, SSE_TASK_CHANGED, ['card_id' => (int) $att['card_id']]);

        $this->json(['success' => true]);
    }

    // ---- Internal helpers ----

    private function loadTask(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Tree of tasks. If $rootId given, returns only its children (one level
     * deep — matches the schema constraint).
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildTaskTree(int $cardId, ?int $rootId = null): array
    {
        $db = Database::get();

        // Fetch all tasks for the card (cheap — bounded).
        $stmt = $db->prepare(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM task_comments tc WHERE tc.task_id = t.id) AS comment_count,
                    (SELECT COUNT(*) FROM task_attachments ta WHERE ta.task_id = t.id) AS attachment_count,
                    (SELECT COUNT(*) FROM tasks ch WHERE ch.parent_task_id = t.id) AS child_count
             FROM tasks t
             WHERE t.card_id = :cid
             ORDER BY t.parent_task_id IS NULL DESC, t.position ASC'
        );
        $stmt->execute(['cid' => $cardId]);
        $all = $stmt->fetchAll();

        if (!$all) return [];

        // Per-task assignees (single batched query, indexed by task_id).
        $ids = array_map(static fn($r) => (int) $r['id'], $all);
        $assignees = $this->batchAssignees($ids);

        // Map by id for parent linking, then build tree.
        $byId = [];
        foreach ($all as $t) {
            $t['assignees'] = $assignees[(int) $t['id']] ?? [];
            $t['children']  = [];
            $byId[(int) $t['id']] = $t;
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $pid = !empty($node['parent_task_id']) ? (int) $node['parent_task_id'] : null;
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        // If a specific root was requested, surface only its children.
        if ($rootId !== null) {
            return $byId[$rootId]['children'] ?? [];
        }
        return $roots;
    }

    /** @return array<int,array<int,array<string,mixed>>> task_id => [assignees] */
    private function batchAssignees(array $taskIds): array
    {
        if (!$taskIds) return [];
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = Database::get()->prepare(
            "SELECT ta.task_id, ta.user_id, ta.accepted_at, u.display_name
             FROM task_assignments ta
             JOIN users u ON ta.user_id = u.id
             WHERE ta.task_id IN ($placeholders)"
        );
        $stmt->execute($taskIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['task_id']][] = [
                'id'           => (int) $r['user_id'],
                'display_name' => $r['display_name'],
                'accepted_at'  => $r['accepted_at'],
            ];
        }
        return $out;
    }

    private function hydrateTask(array $task): array
    {
        $db = Database::get();

        // Assignees
        $stmt = $db->prepare(
            'SELECT u.id, u.display_name, ta.accepted_at
             FROM task_assignments ta JOIN users u ON ta.user_id = u.id
             WHERE ta.task_id = :tid'
        );
        $stmt->execute(['tid' => $task['id']]);
        $task['assignees'] = $stmt->fetchAll();

        // Comments (newest last for natural reading order in the thread)
        $stmt = $db->prepare(
            'SELECT tc.*, u.display_name AS author_name
             FROM task_comments tc JOIN users u ON tc.user_id = u.id
             WHERE tc.task_id = :tid
             ORDER BY tc.created_at ASC'
        );
        $stmt->execute(['tid' => $task['id']]);
        $task['comments'] = $stmt->fetchAll();

        // Per-comment attachments — batched lookup, then bucketed by comment_id
        // so the client can render attachments inline under each comment.
        if (!empty($task['comments'])) {
            $commentIds = array_map(static fn($c) => (int) $c['id'], $task['comments']);
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $cStmt = $db->prepare(
                "SELECT ta.*, u.display_name AS uploader_name
                 FROM task_attachments ta JOIN users u ON ta.user_id = u.id
                 WHERE ta.task_comment_id IN ($placeholders)
                 ORDER BY ta.created_at ASC"
            );
            $cStmt->execute($commentIds);
            $byComment = [];
            foreach ($cStmt->fetchAll() as $r) {
                $byComment[(int) $r['task_comment_id']][] = $r;
            }
            foreach ($task['comments'] as &$cm) {
                $cm['attachments'] = $byComment[(int) $cm['id']] ?? [];
            }
            unset($cm);
        }

        // Task-level attachments only (per-comment attachments are attached above).
        $stmt = $db->prepare(
            'SELECT ta.*, u.display_name AS uploader_name
             FROM task_attachments ta JOIN users u ON ta.user_id = u.id
             WHERE ta.task_id = :tid AND ta.task_comment_id IS NULL
             ORDER BY ta.created_at DESC'
        );
        $stmt->execute(['tid' => $task['id']]);
        $task['attachments'] = $stmt->fetchAll();

        // Creator name (for the "created by" line in the UI)
        $stmt = $db->prepare('SELECT display_name FROM users WHERE id = :id');
        $stmt->execute(['id' => $task['created_by']]);
        $row = $stmt->fetch();
        $task['creator_name'] = $row['display_name'] ?? '';

        return $task;
    }

    /**
     * Returns the chain [card → ... → task] suitable for the breadcrumb UI.
     * One entry per ancestor task plus the card at the head. Since nesting
     * is capped at one level, the chain is at most 3 deep.
     */
    private function buildBreadcrumb(array $task): array
    {
        $db = Database::get();

        $crumbs = [];
        // Walk parent chain until we hit a root task (no parent_task_id).
        $cursor = $task;
        $safety = 5;
        while ($safety-- > 0 && !empty($cursor['parent_task_id'])) {
            $stmt = $db->prepare('SELECT id, title, parent_task_id FROM tasks WHERE id = :id');
            $stmt->execute(['id' => $cursor['parent_task_id']]);
            $row = $stmt->fetch();
            if (!$row) break;
            array_unshift($crumbs, [
                'task_id' => (int) $row['id'],
                'title'   => $row['title'],
            ]);
            $cursor = $row;
        }

        // Card head.
        $stmt = $db->prepare('SELECT title FROM cards WHERE id = :id');
        $stmt->execute(['id' => $task['card_id']]);
        $card = $stmt->fetch();
        array_unshift($crumbs, [
            'card_id' => (int) $task['card_id'],
            'title'   => $card['title'] ?? '',
        ]);

        // Tail = the task itself (so the breadcrumb shows the full path).
        $crumbs[] = ['task_id' => (int) $task['id'], 'title' => $task['title'], 'is_current' => true];
        return $crumbs;
    }

    /**
     * Clone every task on $sourceCardId into $targetCardId. Preserves the
     * parent→child structure and titles/descriptions/positions, but resets
     * assignees, due_date, completion, comments, attachments — per the spec.
     * Called from CardController::cloneCard().
     */
    public static function cloneForCard(int $sourceCardId, int $targetCardId): void
    {
        $db = Database::get();

        // Fetch all source tasks in deterministic order.
        $src = $db->prepare(
            'SELECT id, parent_task_id, title, description, position
             FROM tasks
             WHERE card_id = :cid
             ORDER BY parent_task_id IS NULL DESC, position ASC'
        );
        $src->execute(['cid' => $sourceCardId]);
        $rows = $src->fetchAll();
        if (!$rows) return;

        $ins = $db->prepare(
            'INSERT INTO tasks (card_id, parent_task_id, title, description, position, created_by)
             VALUES (:cid, :pid, :title, :desc, :pos, :by)'
        );

        // Map old_id => new_id so child tasks can re-link to the cloned parent.
        $idMap = [];
        $cloningUser = Auth::userId() ?: (int) ($rows[0]['created_by'] ?? 1);

        // Pass 1: root tasks (parent_task_id IS NULL).
        foreach ($rows as $r) {
            if (!empty($r['parent_task_id'])) continue;
            $ins->execute([
                'cid'   => $targetCardId,
                'pid'   => null,
                'title' => $r['title'],
                'desc'  => $r['description'],
                'pos'   => $r['position'],
                'by'    => $cloningUser,
            ]);
            $idMap[(int) $r['id']] = (int) $db->lastInsertId();
        }

        // Pass 2: child tasks (parent_task_id NOT NULL). Falls back to root
        // if the parent somehow wasn't cloned (defensive — shouldn't happen
        // given the ordering above).
        foreach ($rows as $r) {
            if (empty($r['parent_task_id'])) continue;
            $newParent = $idMap[(int) $r['parent_task_id']] ?? null;
            $ins->execute([
                'cid'   => $targetCardId,
                'pid'   => $newParent,
                'title' => $r['title'],
                'desc'  => $r['description'],
                'pos'   => $r['position'],
                'by'    => $cloningUser,
            ]);
            $idMap[(int) $r['id']] = (int) $db->lastInsertId();
        }
    }
}
