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

        // Auto-watch: the creator gets watched-by-default so they receive
        // the same comment / due notifications other watchers get without
        // having to flip the eye icon on their own card.
        Database::get()->prepare(
            'INSERT IGNORE INTO card_watchers (card_id, user_id) VALUES (:cid, :uid)'
        )->execute(['cid' => $cardId, 'uid' => Auth::userId()]);

        $card = $this->cardModel->find($cardId);
        $card['assignees'] = [];
        $card['labels'] = [];
        $card['comment_count'] = 0;
        $card['attachment_count'] = 0;
        $card['checklist_progress'] = '0/0';
        $card['is_watching'] = 1;

        $this->publishSSE($boardId, SSE_CARD_CREATED, ['card' => $card]);
        $this->logActivity($boardId, $cardId, 'card_created', ['title' => $title]);

        $this->json(['success' => true, 'card' => $card]);
        $this->pushGoogleSync('card', $cardId);
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

        // Title and description are creator-only edits. Other fields
        // (due_date, due_complete, start_date) stay open to anyone with
        // board access — they're operational, not authorial.
        $editsTitleOrDesc = isset($data['title']) || array_key_exists('description', $data);
        if ($editsTitleOrDesc) {
            $existing = $this->cardModel->find($cardId);
            if (!$existing) {
                $this->json(['error' => 'Card not found'], 404);
                return;
            }
            if ((int) $existing['created_by'] !== Auth::userId()) {
                $this->json(['error' => 'Only the card creator can edit the title or description'], 403);
                return;
            }
        }

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

        // Snapshot due_complete before applying updates so we know if this
        // request transitions the card from incomplete → complete (only the
        // forward transition fires a notification; un-completing doesn't).
        $prevDueComplete = null;
        if (isset($updates['due_complete'])) {
            $stmt = Database::get()->prepare('SELECT due_complete FROM cards WHERE id = :id');
            $stmt->execute(['id' => $cardId]);
            $row = $stmt->fetch();
            $prevDueComplete = $row ? (int) $row['due_complete'] : null;
        }

        $this->cardModel->update($cardId, $updates);

        // Notify subscribers when this update completes the card.
        if (isset($updates['due_complete'])
            && (int) $updates['due_complete'] === 1
            && $prevDueComplete === 0
        ) {
            $this->notifyCardEvent($cardId, $boardId, NOTIF_CARD_COMPLETED);
        }

        $card = $this->cardModel->find($cardId);
        $this->publishSSE($boardId, SSE_CARD_UPDATED, ['card' => $card]);
        $this->logActivity($boardId, $cardId, 'card_updated', $updates);

        $this->json(['success' => true, 'card' => $card]);
        $this->pushGoogleSync('card', $cardId);
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
        // List change can flip personal/non-personal status which affects
        // the calendar event, so always push.
        $this->pushGoogleSync('card', $cardId);
    }

    /**
     * Duplicate a card into the same list. Carries over: title, description,
     * due_date, start_date, coordinator, assignees, labels, attachments
     * (files physically copied with new stored_names), checklists (titles),
     * checklist items (content + assigned_to). Resets: is_archived=0,
     * due_complete=0, every checklist item's is_checked / due_date / checked_*,
     * comments (none), watchers (only the cloning user). The new card is
     * inserted right after the source in the list ordering.
     */
    public function cloneCard(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $cardId = (int) ($data['id'] ?? 0);
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

        $src = $this->cardModel->find($cardId);
        if (!$src) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }

        $db     = Database::get();
        $config = require __DIR__ . '/../config/config.php';
        $uploadDir = $config['upload_dir'];

        // Position the clone immediately after the source. If there's no
        // room between source and the next card, append to the list end.
        $nextStmt = $db->prepare(
            'SELECT position FROM cards WHERE list_id = :lid AND position > :pos ORDER BY position ASC LIMIT 1'
        );
        $nextStmt->execute(['lid' => $src['list_id'], 'pos' => $src['position']]);
        $nextRow = $nextStmt->fetch();
        if ($nextRow) {
            $diff = (int) $nextRow['position'] - (int) $src['position'];
            $newPosition = $diff >= 2
                ? (int) $src['position'] + intdiv($diff, 2)
                : $this->cardModel->getNextPosition('list_id', (int) $src['list_id']);
        } else {
            $newPosition = (int) $src['position'] + POSITION_GAP;
        }

        $newCardId = $this->cardModel->insert([
            'list_id'        => $src['list_id'],
            'title'          => $src['title'],
            'description'    => $src['description'],
            'position'       => $newPosition,
            'due_date'       => $src['due_date'],
            'start_date'     => $src['start_date'] ?? null,
            'due_complete'   => 0,
            'coordinator_id' => $src['coordinator_id'],
            'is_archived'    => 0,
            'created_by'     => Auth::userId(),
        ]);

        // The cloning user auto-watches (mirrors the auto-watch rule for
        // freshly-created cards). Watchers from the source are NOT carried over.
        $db->prepare(
            'INSERT IGNORE INTO card_watchers (card_id, user_id) VALUES (:cid, :uid)'
        )->execute(['cid' => $newCardId, 'uid' => Auth::userId()]);

        // Labels — straight copy via INSERT…SELECT.
        $db->prepare(
            'INSERT INTO card_labels (card_id, label_id)
             SELECT :new, label_id FROM card_labels WHERE card_id = :old'
        )->execute(['new' => $newCardId, 'old' => $cardId]);

        // Assignments — straight copy.
        $db->prepare(
            'INSERT INTO card_assignments (card_id, user_id)
             SELECT :new, user_id FROM card_assignments WHERE card_id = :old'
        )->execute(['new' => $newCardId, 'old' => $cardId]);

        // Attachments — copy each file on disk with a fresh stored_name so
        // deleting one card's attachment can't orphan the other's reference.
        // Skip silently if the source file is missing (was probably already
        // pruned out-of-band).
        $atts = $db->prepare('SELECT * FROM attachments WHERE card_id = :cid');
        $atts->execute(['cid' => $cardId]);
        $insAtt = $db->prepare(
            'INSERT INTO attachments
                (card_id, user_id, original_name, stored_name, file_size, mime_type, is_image, thumbnail_path)
             VALUES (:cid, :uid, :on, :sn, :fs, :mt, :ii, :tp)'
        );
        foreach ($atts->fetchAll() as $a) {
            $oldPath = $uploadDir . '/attachments/' . $a['stored_name'];
            if (!file_exists($oldPath)) continue;

            $ext = strtolower(pathinfo($a['stored_name'], PATHINFO_EXTENSION)) ?: 'bin';
            $newStored = uniqid('', true) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $newPath = $uploadDir . '/attachments/' . $newStored;
            if (!@copy($oldPath, $newPath)) continue;

            $newThumb = null;
            if (!empty($a['thumbnail_path'])) {
                $oldThumbPath = $uploadDir . '/thumbnails/' . $a['thumbnail_path'];
                if (file_exists($oldThumbPath)) {
                    $newThumb = 'thumb_' . $newStored;
                    if (!@copy($oldThumbPath, $uploadDir . '/thumbnails/' . $newThumb)) {
                        $newThumb = null;
                    }
                }
            }

            $insAtt->execute([
                'cid' => $newCardId, 'uid' => Auth::userId(),
                'on'  => $a['original_name'], 'sn' => $newStored,
                'fs'  => $a['file_size'], 'mt' => $a['mime_type'],
                'ii'  => $a['is_image'], 'tp' => $newThumb,
            ]);
        }

        // Checklists + items. Items are reset to unchecked with NULL due_date
        // (per the spec — "fresh start" for the clone) but assigned_to is
        // preserved.
        $cls = $db->prepare('SELECT id, title, position FROM checklists WHERE card_id = :cid ORDER BY position ASC');
        $cls->execute(['cid' => $cardId]);
        $insCl = $db->prepare(
            'INSERT INTO checklists (card_id, title, position) VALUES (:cid, :title, :pos)'
        );
        $insItem = $db->prepare(
            'INSERT INTO checklist_items (checklist_id, content, is_checked, position, assigned_to, due_date)
             VALUES (:clid, :content, 0, :pos, :assigned, NULL)'
        );
        foreach ($cls->fetchAll() as $cl) {
            $insCl->execute(['cid' => $newCardId, 'title' => $cl['title'], 'pos' => $cl['position']]);
            $newClId = (int) $db->lastInsertId();

            $itemsStmt = $db->prepare(
                'SELECT content, position, assigned_to FROM checklist_items
                 WHERE checklist_id = :id ORDER BY position ASC'
            );
            $itemsStmt->execute(['id' => $cl['id']]);
            foreach ($itemsStmt->fetchAll() as $it) {
                $insItem->execute([
                    'clid'     => $newClId,
                    'content'  => $it['content'],
                    'pos'      => $it['position'],
                    'assigned' => $it['assigned_to'],
                ]);
            }
        }

        $newCard = $this->cardModel->getSummary($newCardId);
        if ($newCard) $newCard['is_watching'] = 1;

        $this->publishSSE($boardId, SSE_CARD_CREATED, ['card' => $newCard]);
        $this->logActivity($boardId, $newCardId, 'card_cloned', [
            'source_id' => $cardId,
            'title'     => $src['title'],
        ]);

        $this->json([
            'success' => true,
            'card_id' => $newCardId,
            'card'    => $newCard,
        ]);
        $this->pushGoogleSync('card', $newCardId);
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

        // Bottom of the target list — we're adding a card to it.
        $newPosition = $this->cardModel->getNextPosition('list_id', $targetListId);

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
        // Cross-board moves change the assignee set (we strip non-members),
        // so multiple users may need their calendar events deleted/created.
        $this->pushGoogleSync('card', $cardId);
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
        $this->pushGoogleSync('card', $cardId);
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
        $this->pushGoogleSync('card', $cardId);
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
        $this->pushGoogleSync('card', $cardId);
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
        $this->pushGoogleSync('card', $cardId);
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
