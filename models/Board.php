<?php

class Board extends Model
{
    protected string $table = 'boards';

    public function getForUser(int $userId, bool $isAdmin = false): array
    {
        if ($isAdmin) {
            return $this->query(
                'SELECT b.*, u.display_name as creator_name,
                    (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = b.id) as member_count
                 FROM boards b
                 JOIN users u ON b.created_by = u.id
                 WHERE b.is_archived = 0
                 ORDER BY b.updated_at DESC'
            )->fetchAll();
        }

        return $this->query(
            'SELECT b.*, u.display_name as creator_name,
                (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = b.id) as member_count
             FROM boards b
             JOIN board_members bm ON b.id = bm.board_id
             JOIN users u ON b.created_by = u.id
             WHERE bm.user_id = :user_id AND b.is_archived = 0
             ORDER BY b.updated_at DESC',
            ['user_id' => $userId]
        )->fetchAll();
    }

    public function getWithDetails(int $boardId): ?array
    {
        $board = $this->find($boardId);
        if (!$board) return null;

        // Get lists with cards
        $lists = $this->query(
            'SELECT * FROM lists WHERE board_id = :board_id AND is_archived = 0 ORDER BY position ASC',
            ['board_id' => $boardId]
        )->fetchAll();

        foreach ($lists as &$list) {
            $list['cards'] = $this->query(
                'SELECT c.*,
                    GROUP_CONCAT(DISTINCT cl.label_id) as label_ids,
                    (SELECT COUNT(*) FROM card_assignments ca WHERE ca.card_id = c.id) as assignee_count,
                    (SELECT COUNT(*) FROM attachments a WHERE a.card_id = c.id) as attachment_count,
                    (SELECT COUNT(*) FROM comments cm WHERE cm.card_id = c.id) as comment_count,
                    (SELECT CONCAT(
                        (SELECT COUNT(*) FROM checklist_items ci JOIN checklists ch ON ci.checklist_id = ch.id WHERE ch.card_id = c.id AND ci.is_checked = 1),
                        "/",
                        (SELECT COUNT(*) FROM checklist_items ci JOIN checklists ch ON ci.checklist_id = ch.id WHERE ch.card_id = c.id)
                    )) as checklist_progress
                 FROM cards c
                 LEFT JOIN card_labels cl ON c.id = cl.card_id
                 WHERE c.list_id = :list_id AND c.is_archived = 0
                 GROUP BY c.id
                 ORDER BY c.position ASC',
                ['list_id' => $list['id']]
            )->fetchAll();

            // Get assignees for each card
            foreach ($list['cards'] as &$card) {
                $card['assignees'] = $this->query(
                    'SELECT u.id, u.display_name FROM card_assignments ca
                     JOIN users u ON ca.user_id = u.id
                     WHERE ca.card_id = :card_id',
                    ['card_id' => $card['id']]
                )->fetchAll();

                $card['coordinator'] = null;
                if (!empty($card['coordinator_id'])) {
                    $card['coordinator'] = $this->query(
                        'SELECT id, display_name FROM users WHERE id = :id',
                        ['id' => $card['coordinator_id']]
                    )->fetch() ?: null;
                }

                $card['labels'] = $card['label_ids']
                    ? $this->query(
                        'SELECT l.* FROM labels l
                         JOIN card_labels cl ON l.id = cl.label_id
                         WHERE cl.card_id = :card_id',
                        ['card_id' => $card['id']]
                    )->fetchAll()
                    : [];
            }
        }

        // Get members
        $members = $this->query(
            'SELECT u.id, u.display_name, u.email, bm.role as board_role
             FROM board_members bm
             JOIN users u ON bm.user_id = u.id
             WHERE bm.board_id = :board_id
             ORDER BY u.display_name ASC',
            ['board_id' => $boardId]
        )->fetchAll();

        // Get labels
        $labels = $this->query(
            'SELECT * FROM labels WHERE board_id = :board_id ORDER BY id ASC',
            ['board_id' => $boardId]
        )->fetchAll();

        $board['lists'] = $lists;
        $board['members'] = $members;
        $board['labels'] = $labels;

        return $board;
    }
}
