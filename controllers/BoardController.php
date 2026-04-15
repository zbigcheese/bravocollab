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
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $this->requireBoardAccess($id);

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
        $this->requireAdmin();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $this->boardModel->update($id, ['is_archived' => 1]);
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
             WHERE bm.board_id = :board_id ORDER BY u.display_name'
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
