<?php

require_once __DIR__ . '/../models/Board.php';

class BoardController extends Controller
{
    private Board $boardModel;

    public function __construct()
    {
        $this->boardModel = new Board();
    }

    public function list(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $boards = $this->boardModel->getForUser(Auth::userId(), Auth::isAdmin());
        $this->json(['boards' => $boards]);
    }

    public function recentUpdates(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $userId  = Auth::userId();
        $isAdmin = Auth::isAdmin();
        $db      = Database::get();

        // Scope to boards the user can see. Personal boards are excluded
        // from the dashboard "recent updates" stream — they're single-user
        // spaces where the user's own actions aren't useful "news."
        if ($isAdmin) {
            $boardIds = $db->query(
                'SELECT id FROM boards WHERE is_archived = 0 AND is_personal = 0'
            )->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $db->prepare(
                'SELECT b.id FROM boards b
                 JOIN board_members bm ON b.id = bm.board_id
                 WHERE bm.user_id = :uid AND b.is_archived = 0 AND b.is_personal = 0'
            );
            $stmt->execute(['uid' => $userId]);
            $boardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($boardIds)) {
            $this->json(['updates' => (object) []]);
            return;
        }

        // Latest activity per card (one row per distinct card) within scope.
        // The derived `latest` table collapses multiple activities on the same card
        // to a single row before we join back for display data.
        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));
        $sql = "SELECT a.id, a.board_id, a.card_id, a.action, a.created_at,
                       u.display_name AS actor_name,
                       c.title AS card_title
                FROM activities a
                JOIN users u ON a.user_id = u.id
                JOIN cards c ON a.card_id = c.id
                JOIN (
                    SELECT MAX(id) AS max_id
                    FROM activities
                    WHERE card_id IS NOT NULL AND board_id IN ($placeholders)
                    GROUP BY card_id
                ) latest ON a.id = latest.max_id
                WHERE c.is_archived = 0
                ORDER BY a.board_id ASC, a.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($boardIds);
        $rows = $stmt->fetchAll();

        // Group by board, cap 3 per board (already ordered newest-first).
        $grouped = [];
        foreach ($rows as $row) {
            $bid = (int) $row['board_id'];
            if (!isset($grouped[$bid])) $grouped[$bid] = [];
            if (count($grouped[$bid]) >= 3) continue;
            $grouped[$bid][] = [
                'id'         => (int) $row['id'],
                'card_id'    => (int) $row['card_id'],
                'card_title' => $row['card_title'],
                'action'     => $row['action'],
                'actor_name' => $row['actor_name'],
                'created_at' => $row['created_at'],
            ];
        }

        $this->json(['updates' => (object) $grouped]);
    }

    /**
     * Combined calendar data for the dashboard overview — every card with a
     * due date plus every checklist item with a due date, across every board
     * the current user can see. Same access rules as boards.list (member-of
     * for regular users, all non-personal-of-others for admins).
     */
    public function calendarData(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $userId  = Auth::userId();
        $isAdmin = Auth::isAdmin();
        $db      = Database::get();

        if ($isAdmin) {
            $stmt = $db->prepare(
                'SELECT id FROM boards
                 WHERE is_archived = 0
                   AND NOT (is_personal = 1 AND created_by != :uid)'
            );
            $stmt->execute(['uid' => $userId]);
            $boardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $stmt = $db->prepare(
                'SELECT b.id FROM boards b
                 JOIN board_members bm ON b.id = bm.board_id
                 WHERE bm.user_id = :uid AND b.is_archived = 0'
            );
            $stmt->execute(['uid' => $userId]);
            $boardIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($boardIds)) {
            $this->json(['cards' => [], 'items' => []]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($boardIds), '?'));

        $cardsStmt = $db->prepare(
            "SELECT c.id, c.title, c.due_date, c.start_date,
                    c.due_complete, c.is_archived,
                    l.board_id, b.title AS board_title, b.background_color AS board_color
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE l.board_id IN ($placeholders)
               AND c.due_date IS NOT NULL
             ORDER BY c.due_date ASC"
        );
        $cardsStmt->execute($boardIds);
        $cards = $cardsStmt->fetchAll();

        $itemsStmt = $db->prepare(
            "SELECT ci.id, ci.content, ci.due_date, ci.is_checked,
                    ch.card_id, c.title AS card_title, c.is_archived AS card_archived,
                    l.board_id, b.title AS board_title, b.background_color AS board_color
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE l.board_id IN ($placeholders)
               AND ci.due_date IS NOT NULL
             ORDER BY ci.due_date ASC"
        );
        $itemsStmt->execute($boardIds);
        $items = $itemsStmt->fetchAll();

        $this->json([
            'cards' => $cards,
            'items' => $items,
        ]);
    }

    public function get(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            $this->json(['error' => 'Board ID required'], 400);
            return;
        }

        $this->requireBoardAccess($id);

        $board = $this->boardModel->getWithDetails($id);
        if (!$board) {
            $this->json(['error' => 'Board not found'], 404);
            return;
        }

        $this->json(['board' => $board]);
    }

    public function create(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $bgColor = trim($data['background_color'] ?? '#0079BF');

        $v = new Validator();
        $v->required($title, 'title')->maxLength($title, 255, 'title');
        if ($v->fails()) {
            $this->json(['error' => $v->firstError()], 400);
            return;
        }

        $boardId = $this->boardModel->insert([
            'title'            => $title,
            'description'      => $description,
            'background_color' => $bgColor,
            'created_by'       => Auth::userId(),
        ]);

        // Add creator as board owner
        $db = Database::get();
        $db->prepare('INSERT INTO board_members (board_id, user_id, role) VALUES (:board_id, :user_id, :role)')
           ->execute(['board_id' => $boardId, 'user_id' => Auth::userId(), 'role' => BOARD_ROLE_OWNER]);

        // Create default labels
        foreach (DEFAULT_LABEL_COLORS as $color) {
            $db->prepare('INSERT INTO labels (board_id, color) VALUES (:board_id, :color)')
               ->execute(['board_id' => $boardId, 'color' => $color]);
        }

        $board = $this->boardModel->find($boardId);
        $this->json(['success' => true, 'board' => $board]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        // Personal-board owners are admins of their own board.
        $this->requireBoardAdmin($id);

        $updates = [];
        if (isset($data['title'])) {
            $title = trim($data['title']);
            if (empty($title)) {
                $this->json(['error' => 'Title is required'], 400);
                return;
            }
            $updates['title'] = $title;
        }
        if (isset($data['description'])) $updates['description'] = trim($data['description']);
        if (isset($data['background_color'])) $updates['background_color'] = $data['background_color'];

        if (empty($updates)) {
            $this->json(['error' => 'Nothing to update'], 400);
            return;
        }

        $this->boardModel->update($id, $updates);
        $this->json(['success' => true]);
    }

    public function archive(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        // Personal boards are non-archivable / non-deletable by design —
        // they're a fixed user-space artifact that auto-recreates anyway.
        $board = $this->boardModel->find($id);
        if (!$board) {
            $this->json(['error' => 'Board not found'], 404);
            return;
        }
        if ((int) ($board['is_personal'] ?? 0) === 1) {
            $this->json(['error' => 'Personal boards cannot be archived'], 400);
            return;
        }

        $this->requireBoardAdmin($id);
        $this->boardModel->update($id, ['is_archived' => 1]);
        $this->json(['success' => true]);
    }

    public function restore(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $this->requireBoardAdmin($id);
        $this->boardModel->update($id, ['is_archived' => 0]);
        $this->json(['success' => true]);
    }

    public function members(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $boardId = (int) ($_GET['board_id'] ?? 0);
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $members = $db->prepare(
            'SELECT u.id, u.display_name, u.email, bm.role as board_role
             FROM board_members bm JOIN users u ON bm.user_id = u.id
             WHERE bm.board_id = :board_id AND u.is_active = 1
             ORDER BY u.display_name'
        );
        $members->execute(['board_id' => $boardId]);

        $this->json(['members' => $members->fetchAll()]);
    }

    public function addMember(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $boardId = (int) ($data['board_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        if (!$boardId || !$userId) {
            $this->json(['error' => 'Board ID and User ID required'], 400);
            return;
        }

        $db = Database::get();

        // Check if already a member
        $stmt = $db->prepare('SELECT 1 FROM board_members WHERE board_id = :bid AND user_id = :uid');
        $stmt->execute(['bid' => $boardId, 'uid' => $userId]);
        if ($stmt->fetch()) {
            $this->json(['error' => 'User is already a board member'], 400);
            return;
        }

        $db->prepare('INSERT INTO board_members (board_id, user_id, role) VALUES (:bid, :uid, :role)')
           ->execute(['bid' => $boardId, 'uid' => $userId, 'role' => BOARD_ROLE_MEMBER]);

        // Notify the user
        $board = $this->boardModel->find($boardId);
        $this->createNotification($userId, NOTIF_BOARD_INVITED, [
            'board_id'    => $boardId,
            'board_title' => $board['title'],
            'actor_name'  => Auth::userName(),
        ]);

        $this->json(['success' => true]);
    }

    public function removeMember(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $boardId = (int) ($data['board_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        $db = Database::get();
        $db->prepare('DELETE FROM board_members WHERE board_id = :bid AND user_id = :uid')
           ->execute(['bid' => $boardId, 'uid' => $userId]);

        $this->json(['success' => true]);
    }
}
