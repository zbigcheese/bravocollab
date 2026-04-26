<?php

require_once __DIR__ . '/../models/BoardList.php';

class ListController extends Controller
{
    private BoardList $listModel;

    public function __construct()
    {
        $this->listModel = new BoardList();
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $boardId = (int) ($data['board_id'] ?? 0);
        $title = trim($data['title'] ?? '');

        $this->requireBoardAccess($boardId);

        if (empty($title)) {
            $this->json(['error' => 'Title is required'], 400);
            return;
        }

        $position = $this->listModel->getNextPosition('board_id', $boardId);

        $listId = $this->listModel->insert([
            'board_id' => $boardId,
            'title'    => $title,
            'position' => $position,
        ]);

        $list = $this->listModel->find($listId);
        $list['cards'] = [];

        $this->publishSSE($boardId, SSE_LIST_CREATED, ['list' => $list]);
        $this->logActivity($boardId, null, 'list_created', ['list_title' => $title]);

        $this->json(['success' => true, 'list' => $list]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');

        $list = $this->listModel->find($id);
        if (!$list) {
            $this->json(['error' => 'List not found'], 404);
            return;
        }

        $this->requireBoardAccess($list['board_id']);

        if (empty($title)) {
            $this->json(['error' => 'Title is required'], 400);
            return;
        }

        $this->listModel->update($id, ['title' => $title]);

        $this->publishSSE($list['board_id'], SSE_LIST_UPDATED, [
            'list_id' => $id, 'title' => $title,
        ]);

        $this->json(['success' => true]);
    }

    public function archive(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        $list = $this->listModel->find($id);
        if (!$list) {
            $this->json(['error' => 'List not found'], 404);
            return;
        }

        // Personal-board owners are admins of their own board.
        $this->requireBoardAdmin($list['board_id']);
        $this->listModel->update($id, ['is_archived' => 1]);

        $this->publishSSE($list['board_id'], SSE_LIST_ARCHIVED, ['list_id' => $id]);
        $this->logActivity($list['board_id'], null, 'list_archived', ['list_title' => $list['title']]);

        $this->json(['success' => true]);
    }

    public function forBoard(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $boardId = (int) ($_GET['board_id'] ?? 0);
        if (!$boardId) {
            $this->json(['error' => 'board_id is required'], 400);
            return;
        }
        $this->requireBoardAccess($boardId);

        $stmt = Database::get()->prepare(
            'SELECT id, title, position FROM lists
             WHERE board_id = :bid AND is_archived = 0
             ORDER BY position ASC'
        );
        $stmt->execute(['bid' => $boardId]);

        $this->json(['lists' => $stmt->fetchAll()]);
    }

    public function reorder(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $boardId = (int) ($data['board_id'] ?? 0);
        $positions = $data['positions'] ?? [];

        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $stmt = $db->prepare('UPDATE lists SET position = :pos WHERE id = :id AND board_id = :board_id');

        foreach ($positions as $i => $listId) {
            $stmt->execute([
                'pos'      => ($i + 1) * POSITION_GAP,
                'id'       => (int) $listId,
                'board_id' => $boardId,
            ]);
        }

        $this->publishSSE($boardId, SSE_LIST_REORDERED, ['positions' => $positions]);

        $this->json(['success' => true]);
    }
}
