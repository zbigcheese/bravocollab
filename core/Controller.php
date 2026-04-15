<?php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/../config/constants.php';

class Controller
{
    protected function requireAuth(): void
    {
        if (!Auth::isLoggedIn()) {
            $this->json(['error' => 'Authentication required'], 401);
            exit;
        }
    }

    protected function requireAdmin(): void
    {
        $this->requireAuth();
        if (!Auth::isAdmin()) {
            $this->json(['error' => 'Admin access required'], 403);
            exit;
        }
    }

    protected function requireBoardAccess(int $boardId): void
    {
        $this->requireAuth();
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT 1 FROM `board_members` WHERE `board_id` = :board_id AND `user_id` = :user_id LIMIT 1'
        );
        $stmt->execute(['board_id' => $boardId, 'user_id' => Auth::userId()]);

        if (!$stmt->fetch()) {
            // Admins can access any board
            if (!Auth::isAdmin()) {
                $this->json(['error' => 'Board access denied'], 403);
                exit;
            }
        }
    }

    protected function validateCSRF(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !hash_equals(Auth::csrfToken(), $token)) {
            $this->json(['error' => 'Invalid CSRF token'], 403);
            exit;
        }
    }

    protected function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    protected function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }

    protected function getJSON(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function requirePost(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST method required'], 405);
            exit;
        }
    }

    protected function requireGet(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->json(['error' => 'GET method required'], 405);
            exit;
        }
    }

    // Event types where only the latest event per entity matters
    private static array $supersedableEvents = [
        SSE_CARD_UPDATED    => 'card_id',      // keyed by card id in payload or payload.card.id
        SSE_CARD_MOVED      => 'card_id',
        SSE_LIST_UPDATED    => 'list_id',
        SSE_LIST_REORDERED  => null,            // null = one per board (no sub-key)
        SSE_CHECKLIST_CHANGED => 'card_id',
        SSE_LABEL_CHANGED   => null,
    ];

    protected function publishSSE(int $boardId, string $eventType, array $payload): void
    {
        $db = Database::get();

        // Prune older superseded events of the same type/entity
        if (isset(self::$supersedableEvents[$eventType])) {
            $entityKey = self::$supersedableEvents[$eventType];

            if ($entityKey === null) {
                // One per board — delete all older events of this type for this board
                $db->prepare(
                    'DELETE FROM `sse_events` WHERE `board_id` = :board_id AND `event_type` = :event_type'
                )->execute(['board_id' => $boardId, 'event_type' => $eventType]);
            } else {
                // Per-entity — extract entity ID from payload
                $entityId = $payload[$entityKey]
                    ?? $payload['card']['id']
                    ?? $payload['list']['id']
                    ?? null;

                if ($entityId !== null) {
                    // JSON paths are safe literals (from $supersedableEvents, not user input)
                    $eKey = addslashes($entityKey);
                    $db->prepare(
                        "DELETE FROM `sse_events`
                         WHERE `board_id` = :board_id
                           AND `event_type` = :event_type
                           AND (JSON_UNQUOTE(JSON_EXTRACT(`payload`, '\$.{$eKey}')) = :eid1
                             OR JSON_UNQUOTE(JSON_EXTRACT(`payload`, '\$.card.id')) = :eid2
                             OR JSON_UNQUOTE(JSON_EXTRACT(`payload`, '\$.list.id')) = :eid3)"
                    )->execute([
                        'board_id'   => $boardId,
                        'event_type' => $eventType,
                        'eid1'       => (string) $entityId,
                        'eid2'       => (string) $entityId,
                        'eid3'       => (string) $entityId,
                    ]);
                }
            }
        }

        $db->prepare(
            'INSERT INTO `sse_events` (`board_id`, `event_type`, `payload`) VALUES (:board_id, :event_type, :payload)'
        )->execute([
            'board_id'   => $boardId,
            'event_type' => $eventType,
            'payload'    => json_encode($payload),
        ]);
    }

    protected function createNotification(int $userId, string $type, array $data): void
    {
        // Don't notify the actor
        if ($userId === Auth::userId()) {
            return;
        }
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO `notifications` (`user_id`, `type`, `data`) VALUES (:user_id, :type, :data)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'type'    => $type,
            'data'    => json_encode($data),
        ]);
    }

    protected function logActivity(int $boardId, ?int $cardId, string $action, ?array $detail = null): void
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO `activities` (`board_id`, `card_id`, `user_id`, `action`, `detail`) VALUES (:board_id, :card_id, :user_id, :action, :detail)'
        );
        $stmt->execute([
            'board_id' => $boardId,
            'card_id'  => $cardId,
            'user_id'  => Auth::userId(),
            'action'   => $action,
            'detail'   => $detail ? json_encode($detail) : null,
        ]);
    }

    protected function getBoardIdForCard(int $cardId): ?int
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'SELECT l.board_id FROM `cards` c JOIN `lists` l ON c.list_id = l.id WHERE c.id = :id'
        );
        $stmt->execute(['id' => $cardId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['board_id'] : null;
    }

    protected function getBoardIdForList(int $listId): ?int
    {
        $db = Database::get();
        $stmt = $db->prepare('SELECT `board_id` FROM `lists` WHERE `id` = :id');
        $stmt->execute(['id' => $listId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['board_id'] : null;
    }
}
