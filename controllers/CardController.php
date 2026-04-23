<?php

require_once __DIR__ . '/../models/Card.php';
require_once __DIR__ . '/../models/BoardList.php';

class CardController extends Controller
{
    private Card $cardModel;

    public function __construct()
    {
        $this->cardModel = new Card();
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $listId = (int) ($data['list_id'] ?? 0);
        $title = trim($data['title'] ?? '');

        if (!$listId || empty($title)) {
            $this->json(['error' => 'List ID and title are required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForList($listId);
        if (!$boardId) {
            $this->json(['error' => 'List not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $position = $this->cardModel->getNextPosition('list_id', $listId);

        $cardId = $this->cardModel->insert([
            'list_id'    => $listId,
            'title'      => $title,
            'position'   => $position,
            'created_by' => Auth::userId(),
        ]);

        $card = $this->cardModel->find($cardId);
        $card['assignees'] = [];
        $card['labels'] = [];
        $card['comment_count'] = 0;
        $card['attachment_count'] = 0;
        $card['checklist_progress'] = '0/0';

        $this->publishSSE($boardId, SSE_CARD_CREATED, ['card' => $card]);
        $this->logActivity($boardId, $cardId, 'card_created', ['title' => $title]);

        $this->json(['success' => true, 'card' => $card]);
    }

    public function get(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $cardId = (int) ($_GET['id'] ?? 0);
        if (!$cardId) {
            $this->json(['error' => 'Card ID required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $card = $this->cardModel->getFullDetail($cardId);
        if (!$card) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }

        $card['board_id'] = $boardId;

        $this->json(['card' => $card]);
    }

    public function summary(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $cardId = (int) ($_GET['id'] ?? 0);
        if (!$cardId) {
            $this->json(['error' => 'Card ID required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $card = $this->cardModel->getSummary($cardId);
        if (!$card) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }

        $this->json(['card' => $card]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $updates = [];
        if (isset($data['title'])) {
            $title = trim($data['title']);
            if (empty($title)) {
                $this->json(['error' => 'Title is required'], 400);
                return;
            }
            $updates['title'] = $title;
        }
        if (array_key_exists('description', $data)) $updates['description'] = $data['description'];
        if (array_key_exists('due_date', $data)) $updates['due_date'] = $data['due_date'] ?: null;
        if (array_key_exists('start_date', $data)) $updates['start_date'] = $data['start_date'] ?: null;
        if (isset($data['due_complete'])) $updates['due_complete'] = $data['due_complete'] ? 1 : 0;

        if (empty($updates)) {
            $this->json(['error' => 'Nothing to update'], 400);
            return;
        }

        $this->cardModel->update($cardId, $updates);

        $card = $this->cardModel->find($cardId);
        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $card]);
        $this->logActivity($boardId, $cardId, 'card_updated', $updates);

        $this->json(['success' => true, 'card' => $card]);
    }

    public function move(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $targetListId = (int) ($data['target_list_id'] ?? 0);
        $position = (int) ($data['position'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        // Verify target list belongs to same board
        $targetBoardId = $this->getBoardIdForList($targetListId);
        if ($targetBoardId !== $boardId) {
            $this->json(['error' => 'Target list not found on this board'], 400);
            return;
        }

        $this->cardModel->update($cardId, [
            'list_id'  => $targetListId,
            'position' => $position,
        ]);

        $this->publishSSE($boardId, SSE_CARD_MOVED, [
            'card_id'        => $cardId,
            'target_list_id' => $targetListId,
            'position'       => $position,
        ]);

        $this->json(['success' => true]);
    }

    public function moveToBoard(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId        = (int) ($data['card_id'] ?? 0);
        $targetBoardId = (int) ($data['target_board_id'] ?? 0);
        $targetListId  = (int) ($data['target_list_id'] ?? 0);

        if (!$cardId || !$targetBoardId || !$targetListId) {
            $this->json(['error' => 'card_id, target_board_id and target_list_id are required'], 400);
            return;
        }

        $sourceBoardId = $this->getBoardIdForCard($cardId);
        if (!$sourceBoardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        // User must be a member of BOTH boards (or admin).
        $this->requireBoardAccess($sourceBoardId);
        $this->requireBoardAccess($targetBoardId);

        // Verify target list actually lives on the target board.
        if ($this->getBoardIdForList($targetListId) !== $targetBoardId) {
            $this->json(['error' => 'Target list does not belong to the target board'], 400);
            return;
        }

        $sameBoard = ($sourceBoardId === $targetBoardId);
        $card = $this->cardModel->find($cardId);

        $listModel  = new BoardList();
        $newPosition = $listModel->getNextPosition('list_id', $targetListId);

        if (!$sameBoard) {
            $db = Database::get();

            // Labels are board-scoped — the old label IDs are invalid on the target
            // board so we strip all label associations before the move.
            $db->prepare('DELETE FROM card_labels WHERE card_id = :cid')
               ->execute(['cid' => $cardId]);

            // Users who can legitimately stay attached on the target board:
            // members of the target board + all site admins.
            $stmt = $db->prepare('SELECT user_id AS uid FROM board_members WHERE board_id = :bid');
            $stmt->execute(['bid' => $targetBoardId]);
            $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $stmt = $db->query("SELECT id FROM users WHERE role = 'admin'");
            $allowed = array_unique(array_merge($allowed, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
            $allowedSet = array_flip($allowed); // O(1) lookup

            // Drop assignees not in the allowed set.
            $stmt = $db->prepare('SELECT user_id FROM card_assignments WHERE card_id = :cid');
            $stmt->execute(['cid' => $cardId]);
            $currentAssignees = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $toDrop = array_values(array_filter($currentAssignees, fn($uid) => !isset($allowedSet[$uid])));
            if (!empty($toDrop)) {
                $placeholders = implode(',', array_fill(0, count($toDrop), '?'));
                $params = array_merge([$cardId], $toDrop);
                $db->prepare("DELETE FROM card_assignments WHERE card_id = ? AND user_id IN ($placeholders)")
                   ->execute($params);
            }

            // Clear coordinator if not in allowed set.
            $coordId = isset($card['coordinator_id']) ? (int) $card['coordinator_id'] : 0;
            if ($coordId > 0 && !isset($allowedSet[$coordId])) {
                $db->prepare('UPDATE cards SET coordinator_id = NULL WHERE id = :cid')
                   ->execute(['cid' => $cardId]);
            }
        }

        $this->cardModel->update($cardId, [
            'list_id'  => $targetListId,
            'position' => $newPosition,
        ]);

        if ($sameBoard) {
            // Same-board move — reuse the regular card_moved event.
            $this->publishSSE($sourceBoardId, SSE_CARD_MOVED, [
                'card_id'        => $cardId,
                'target_list_id' => $targetListId,
                'position'       => $newPosition,
            ]);
            $this->logActivity($sourceBoardId, $cardId, 'card_moved', [
                'title' => $card['title'],
            ]);
        } else {
            // Cross-board: remove from source view, insert into target view.
            $summary = $this->cardModel->getSummary($cardId);
            if ($summary) {
                $summary['list_id'] = $targetListId;
            }
            $this->publishSSE($sourceBoardId, SSE_CARD_ARCHIVED, ['card_id' => $cardId]);
            $this->publishSSE($targetBoardId, SSE_CARD_CREATED, ['card' => $summary]);
            $this->logActivity($sourceBoardId, $cardId, 'card_moved_out', [
                'title'           => $card['title'],
                'target_board_id' => $targetBoardId,
            ]);
            $this->logActivity($targetBoardId, $cardId, 'card_moved_in', [
                'title'           => $card['title'],
                'source_board_id' => $sourceBoardId,
            ]);
        }

        $this->json([
            'success'         => true,
            'target_board_id' => $targetBoardId,
            'target_list_id'  => $targetListId,
            'same_board'      => $sameBoard,
        ]);
    }

    public function archive(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $card = $this->cardModel->find($cardId);
        $this->cardModel->update($cardId, ['is_archived' => 1]);

        $this->publishSSE($boardId, SSE_CARD_ARCHIVED, ['card_id' => $cardId]);
        $this->logActivity($boardId, $cardId, 'card_archived', ['title' => $card['title']]);

        $this->json(['success' => true]);
    }

    public function watch(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        Database::get()
            ->prepare('INSERT IGNORE INTO card_watchers (card_id, user_id) VALUES (:cid, :uid)')
            ->execute(['cid' => $cardId, 'uid' => Auth::userId()]);

        $this->json(['success' => true]);
    }

    public function unwatch(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        Database::get()
            ->prepare('DELETE FROM card_watchers WHERE card_id = :cid AND user_id = :uid')
            ->execute(['cid' => $cardId, 'uid' => Auth::userId()]);

        $this->json(['success' => true]);
    }

    public function restore(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $card = $this->cardModel->find($cardId);
        $this->cardModel->update($cardId, ['is_archived' => 0]);

        // Re-use card_updated for live refresh; consumers will pick up is_archived=0.
        $fresh = $this->cardModel->find($cardId);
        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $fresh]);
        $this->logActivity($boardId, $cardId, 'card_updated', ['restored' => true, 'title' => $card['title']]);

        $this->json(['success' => true]);
    }

    public function assign(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();

        // Check if already assigned
        $stmt = $db->prepare('SELECT 1 FROM card_assignments WHERE card_id = :cid AND user_id = :uid');
        $stmt->execute(['cid' => $cardId, 'uid' => $userId]);
        if ($stmt->fetch()) {
            $this->json(['error' => 'User already assigned'], 400);
            return;
        }

        $db->prepare('INSERT INTO card_assignments (card_id, user_id) VALUES (:cid, :uid)')
           ->execute(['cid' => $cardId, 'uid' => $userId]);

        // Notify assigned user
        $card = $this->cardModel->find($cardId);
        $this->createNotification($userId, NOTIF_CARD_ASSIGNED, [
            'board_id'    => $boardId,
            'card_id'     => $cardId,
            'card_title'  => $card['title'],
            'actor_name'  => Auth::userName(),
        ]);

        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $this->cardModel->find($cardId)]);

        $this->json(['success' => true]);
    }

    public function unassign(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $db->prepare('DELETE FROM card_assignments WHERE card_id = :cid AND user_id = :uid')
           ->execute(['cid' => $cardId, 'uid' => $userId]);

        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $this->cardModel->find($cardId)]);

        $this->json(['success' => true]);
    }

    public function setCoordinator(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $rawUser = $data['user_id'] ?? null;
        $userId = ($rawUser === null || $rawUser === '' || (int) $rawUser === 0) ? null : (int) $rawUser;

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        // If setting a coordinator, verify they're a board member (or admin)
        if ($userId !== null) {
            $db = Database::get();
            $stmt = $db->prepare(
                'SELECT 1 FROM board_members WHERE board_id = :bid AND user_id = :uid
                 UNION SELECT 1 FROM users WHERE id = :uid2 AND role = "admin"'
            );
            $stmt->execute(['bid' => $boardId, 'uid' => $userId, 'uid2' => $userId]);
            if (!$stmt->fetch()) {
                $this->json(['error' => 'User is not a board member'], 400);
                return;
            }
        }

        $this->cardModel->update($cardId, ['coordinator_id' => $userId]);

        $card = $this->cardModel->find($cardId);
        $coordinator = null;
        if ($userId !== null) {
            $db = Database::get();
            $stmt = $db->prepare('SELECT id, display_name FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $coordinator = $stmt->fetch() ?: null;

            // Notify new coordinator
            $this->createNotification($userId, NOTIF_CARD_ASSIGNED, [
                'board_id'    => $boardId,
                'card_id'     => $cardId,
                'card_title'  => $card['title'],
                'actor_name'  => Auth::userName(),
                'as_coordinator' => true,
            ]);
        }
        $card['coordinator'] = $coordinator;

        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $card]);

        $this->json(['success' => true, 'coordinator' => $coordinator]);
    }
}
