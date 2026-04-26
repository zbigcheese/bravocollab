<?php

class ChecklistController extends Controller
{
    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $title = trim($data['title'] ?? 'Checklist');

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();

        // Get next position
        $stmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM checklists WHERE card_id = :cid');
        $stmt->execute(['cid' => $cardId]);
        $position = (int) $stmt->fetchColumn();

        $db->prepare('INSERT INTO checklists (card_id, title, position) VALUES (:cid, :title, :pos)')
           ->execute(['cid' => $cardId, 'title' => $title, 'pos' => $position]);
        $checklistId = (int) $db->lastInsertId();

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $cardId]);

        $this->json(['success' => true, 'checklist_id' => $checklistId]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);
        $title = trim($data['title'] ?? '');

        if (empty($title)) {
            $this->json(['error' => 'Title is required'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare('SELECT card_id FROM checklists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $checklist = $stmt->fetch();
        if (!$checklist) {
            $this->json(['error' => 'Checklist not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($checklist['card_id']);
        $this->requireBoardAccess($boardId);

        $db->prepare('UPDATE checklists SET title = :title WHERE id = :id')
           ->execute(['title' => $title, 'id' => $id]);

        $this->json(['success' => true]);
    }

    public function delete(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare('SELECT card_id FROM checklists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $checklist = $stmt->fetch();
        if (!$checklist) {
            $this->json(['error' => 'Checklist not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($checklist['card_id']);
        $this->requireBoardAccess($boardId);

        $db->prepare('DELETE FROM checklists WHERE id = :id')->execute(['id' => $id]);

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $checklist['card_id']]);

        $this->json(['success' => true]);
    }

    public function addItem(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $checklistId = (int) ($data['checklist_id'] ?? 0);
        $content = trim($data['content'] ?? '');

        if (empty($content)) {
            $this->json(['error' => 'Content is required'], 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare('SELECT card_id FROM checklists WHERE id = :id');
        $stmt->execute(['id' => $checklistId]);
        $checklist = $stmt->fetch();
        if (!$checklist) {
            $this->json(['error' => 'Checklist not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($checklist['card_id']);
        $this->requireBoardAccess($boardId);

        $posStmt = $db->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM checklist_items WHERE checklist_id = :clid');
        $posStmt->execute(['clid' => $checklistId]);
        $position = (int) $posStmt->fetchColumn();

        $db->prepare('INSERT INTO checklist_items (checklist_id, content, position) VALUES (:clid, :content, :pos)')
           ->execute(['clid' => $checklistId, 'content' => $content, 'pos' => $position]);
        $itemId = (int) $db->lastInsertId();

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $checklist['card_id']]);

        $this->json(['success' => true, 'item_id' => $itemId]);
        $this->pushGoogleSync('item', $itemId);
    }

    public function toggleItem(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $itemId = (int) ($data['id'] ?? 0);
        $isChecked = $data['is_checked'] ?? false;

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT ci.*, cl.card_id FROM checklist_items ci JOIN checklists cl ON ci.checklist_id = cl.id WHERE ci.id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            $this->json(['error' => 'Item not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($item['card_id']);
        $this->requireBoardAccess($boardId);

        $db->prepare('UPDATE checklist_items SET is_checked = :checked, checked_by = :by, checked_at = :at WHERE id = :id')
           ->execute([
               'checked' => $isChecked ? 1 : 0,
               'by'      => $isChecked ? Auth::userId() : null,
               'at'      => $isChecked ? date('Y-m-d H:i:s') : null,
               'id'      => $itemId,
           ]);

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $item['card_id']]);

        $this->json(['success' => true]);
        $this->pushGoogleSync('item', $itemId);
    }

    public function deleteItem(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $itemId = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT cl.card_id FROM checklist_items ci JOIN checklists cl ON ci.checklist_id = cl.id WHERE ci.id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            $this->json(['error' => 'Item not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($item['card_id']);
        $this->requireBoardAccess($boardId);

        $db->prepare('DELETE FROM checklist_items WHERE id = :id')->execute(['id' => $itemId]);

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $item['card_id']]);

        $this->json(['success' => true]);
        // syncItemForAll runs against existing event mappings so it still
        // finds the now-orphaned event and removes it from Google.
        $this->pushGoogleSync('item', $itemId);
    }

    public function updateItem(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $itemId = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT ci.*, cl.card_id FROM checklist_items ci JOIN checklists cl ON ci.checklist_id = cl.id WHERE ci.id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            $this->json(['error' => 'Item not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($item['card_id']);
        $this->requireBoardAccess($boardId);

        $updates = [];
        $params = ['id' => $itemId];
        if (array_key_exists('assigned_to', $data)) {
            $updates[] = '`assigned_to` = :assigned_to';
            $params['assigned_to'] = $data['assigned_to'] ? (int) $data['assigned_to'] : null;

            // Notify assigned user
            if ($data['assigned_to'] && (int) $data['assigned_to'] !== Auth::userId()) {
                $card = $db->prepare('SELECT title FROM cards WHERE id = :id');
                $card->execute(['id' => $item['card_id']]);
                $cardTitle = ($card->fetch())['title'] ?? '';
                $this->createNotification((int) $data['assigned_to'], NOTIF_CARD_ASSIGNED, [
                    'board_id'   => $boardId,
                    'card_id'    => $item['card_id'],
                    'card_title' => $cardTitle . ' (' . $item['content'] . ')',
                    'actor_name' => Auth::userName(),
                ]);
            }
        }
        if (array_key_exists('due_date', $data)) {
            $updates[] = '`due_date` = :due_date';
            $params['due_date'] = $data['due_date'] ?: null;
        }
        if (isset($data['content'])) {
            $content = trim($data['content']);
            if (!empty($content)) {
                $updates[] = '`content` = :content';
                $params['content'] = $content;
            }
        }

        if (!empty($updates)) {
            $db->prepare('UPDATE `checklist_items` SET ' . implode(', ', $updates) . ' WHERE `id` = :id')
               ->execute($params);
        }

        $this->publishSSE($boardId, SSE_CHECKLIST_CHANGED, ['card_id' => $item['card_id']]);

        $this->json(['success' => true]);
        $this->pushGoogleSync('item', $itemId);
    }
}
