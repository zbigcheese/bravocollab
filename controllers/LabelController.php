<?php

class LabelController extends Controller
{
    public function list(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $boardId = (int) ($_GET['board_id'] ?? 0);
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM labels WHERE board_id = :bid ORDER BY id ASC');
        $stmt->execute(['bid' => $boardId]);

        $this->json(['labels' => $stmt->fetchAll()]);
    }

    public function create(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $boardId = (int) ($data['board_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $color = $data['color'] ?? '#0079BF';

        $this->requireBoardAccess($boardId);

        $v = new Validator();
        $v->hexColor($color);
        if ($v->fails()) {
            $this->json(['error' => $v->firstError()], 400);
            return;
        }

        $db = Database::get();
        $db->prepare('INSERT INTO labels (board_id, name, color) VALUES (:bid, :name, :color)')
           ->execute(['bid' => $boardId, 'name' => $name ?: null, 'color' => $color]);

        $labelId = (int) $db->lastInsertId();
        $this->publishSSE($boardId, SSE_LABEL_CHANGED, ['action' => 'created']);

        $this->json(['success' => true, 'label_id' => $labelId]);
    }

    public function update(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM labels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $label = $stmt->fetch();
        if (!$label) {
            $this->json(['error' => 'Label not found'], 404);
            return;
        }

        $this->requireBoardAccess($label['board_id']);

        $updates = [];
        if (array_key_exists('name', $data)) $updates['name'] = trim($data['name']) ?: null;
        if (isset($data['color'])) {
            $v = new Validator();
            $v->hexColor($data['color']);
            if ($v->fails()) {
                $this->json(['error' => $v->firstError()], 400);
                return;
            }
            $updates['color'] = $data['color'];
        }

        if (!empty($updates)) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($updates as $k => $v) {
                $sets[] = "`{$k}` = :{$k}";
                $params[$k] = $v;
            }
            $db->prepare("UPDATE labels SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        }

        $this->publishSSE($label['board_id'], SSE_LABEL_CHANGED, ['action' => 'updated']);

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
        $stmt = $db->prepare('SELECT board_id FROM labels WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $label = $stmt->fetch();
        if (!$label) {
            $this->json(['error' => 'Label not found'], 404);
            return;
        }

        $this->requireBoardAccess($label['board_id']);

        $db->prepare('DELETE FROM labels WHERE id = :id')->execute(['id' => $id]);

        $this->publishSSE($label['board_id'], SSE_LABEL_CHANGED, ['action' => 'deleted']);

        $this->json(['success' => true]);
    }

    public function attach(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $labelId = (int) ($data['label_id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();

        // Check if already attached
        $stmt = $db->prepare('SELECT 1 FROM card_labels WHERE card_id = :cid AND label_id = :lid');
        $stmt->execute(['cid' => $cardId, 'lid' => $labelId]);
        if (!$stmt->fetch()) {
            $db->prepare('INSERT INTO card_labels (card_id, label_id) VALUES (:cid, :lid)')
               ->execute(['cid' => $cardId, 'lid' => $labelId]);
        }

        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card_id' => $cardId]);

        $this->json(['success' => true]);
    }

    public function detach(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['card_id'] ?? 0);
        $labelId = (int) ($data['label_id'] ?? 0);

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $db = Database::get();
        $db->prepare('DELETE FROM card_labels WHERE card_id = :cid AND label_id = :lid')
           ->execute(['cid' => $cardId, 'lid' => $labelId]);

        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card_id' => $cardId]);

        $this->json(['success' => true]);
    }
}
