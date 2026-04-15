<?php

class Card extends Model
{
    protected string $table = 'cards';

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

        // Labels
        $card['labels'] = $this->query(
            'SELECT l.* FROM labels l
             JOIN card_labels cl ON l.id = cl.label_id
             WHERE cl.card_id = :card_id',
            ['card_id' => $cardId]
        )->fetchAll();

        // Comments (oldest first, with parent_id for threading)
        $card['comments'] = $this->query(
            'SELECT c.*, u.display_name as author_name FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.card_id = :card_id
             ORDER BY c.created_at ASC',
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
