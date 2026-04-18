<?php

class Card extends Model
{
    protected string $table = 'cards';

    // Lightweight thumbnail projection — everything the board-view card preview needs.
    public function getSummary(int $cardId): ?array
    {
        $row = $this->query(
            "SELECT c.id, c.list_id, c.title, c.description, c.position,
                    c.due_date, c.start_date, c.due_complete, c.coordinator_id, c.is_archived,
                    (SELECT COUNT(*) FROM attachments a WHERE a.card_id = c.id) as attachment_count,
                    (SELECT COUNT(*) FROM comments cm WHERE cm.card_id = c.id) as comment_count,
                    CONCAT(
                        (SELECT COUNT(*) FROM checklist_items ci JOIN checklists ch ON ci.checklist_id = ch.id WHERE ch.card_id = c.id AND ci.is_checked = 1),
                        '/',
                        (SELECT COUNT(*) FROM checklist_items ci JOIN checklists ch ON ci.checklist_id = ch.id WHERE ch.card_id = c.id)
                    ) as checklist_progress
             FROM cards c
             WHERE c.id = :id",
            ['id' => $cardId]
        )->fetch();

        if (!$row) return null;

        $row['assignees'] = $this->query(
            'SELECT u.id, u.display_name FROM card_assignments ca
             JOIN users u ON ca.user_id = u.id
             WHERE ca.card_id = :cid',
            ['cid' => $cardId]
        )->fetchAll();

        $row['labels'] = $this->query(
            'SELECT l.* FROM labels l
             JOIN card_labels cl ON l.id = cl.label_id
             WHERE cl.card_id = :cid',
            ['cid' => $cardId]
        )->fetchAll();

        $row['coordinator'] = null;
        if (!empty($row['coordinator_id'])) {
            $row['coordinator'] = $this->query(
                'SELECT id, display_name FROM users WHERE id = :id',
                ['id' => $row['coordinator_id']]
            )->fetch() ?: null;
        }

        return $row;
    }

    public function getFullDetail(int $cardId): ?array
    {
        $card = $this->find($cardId);
        if (!$card) return null;

        // Assignees
        $card['assignees'] = $this->query(
            'SELECT u.id, u.display_name, u.email FROM card_assignments ca
             JOIN users u ON ca.user_id = u.id
             WHERE ca.card_id = :card_id',
            ['card_id' => $cardId]
        )->fetchAll();

        // Coordinator (nullable single user)
        $card['coordinator'] = null;
        if (!empty($card['coordinator_id'])) {
            $card['coordinator'] = $this->query(
                'SELECT id, display_name, email FROM users WHERE id = :id',
                ['id' => $card['coordinator_id']]
            )->fetch() ?: null;
        }

        // Labels
        $card['labels'] = $this->query(
            'SELECT l.* FROM labels l
             JOIN card_labels cl ON l.id = cl.label_id
             WHERE cl.card_id = :card_id',
            ['card_id' => $cardId]
        )->fetchAll();

        // Comments (newest first; client groups by parent_id so order
        // applies independently to roots and to replies under each root).
        $card['comments'] = $this->query(
            'SELECT c.*, u.display_name as author_name FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.card_id = :card_id
             ORDER BY c.created_at DESC',
            ['card_id' => $cardId]
        )->fetchAll();

        // Checklists with items (include assignee info)
        $card['checklists'] = $this->query(
            'SELECT * FROM checklists WHERE card_id = :card_id ORDER BY position ASC',
            ['card_id' => $cardId]
        )->fetchAll();

        foreach ($card['checklists'] as &$checklist) {
            $checklist['items'] = $this->query(
                'SELECT ci.*, u.display_name as checked_by_name, ua.display_name as assigned_to_name
                 FROM checklist_items ci
                 LEFT JOIN users u ON ci.checked_by = u.id
                 LEFT JOIN users ua ON ci.assigned_to = ua.id
                 WHERE ci.checklist_id = :cl_id ORDER BY ci.position ASC',
                ['cl_id' => $checklist['id']]
            )->fetchAll();
        }

        // Attachments
        $card['attachments'] = $this->query(
            'SELECT a.*, u.display_name as uploader_name FROM attachments a
             JOIN users u ON a.user_id = u.id
             WHERE a.card_id = :card_id ORDER BY a.created_at DESC',
            ['card_id' => $cardId]
        )->fetchAll();

        // Creator
        $creator = $this->query(
            'SELECT display_name FROM users WHERE id = :id',
            ['id' => $card['created_by']]
        )->fetch();
        $card['creator_name'] = $creator ? $creator['display_name'] : 'Unknown';

        return $card;
    }
}
