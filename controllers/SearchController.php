<?php
/**
 * SearchController — global search across card titles, descriptions,
 * checklist items, and comments. Results are always scoped to boards the
 * current user has access to (board membership for regular users, all
 * non-personal boards plus their own personal board for site admins, with
 * the strict "personal boards are owner-only" exception).
 *
 * Two entry points:
 *   - quick(q):  small JSON for the live dropdown — top N matches across all
 *                source kinds, ranked by a simple priority (title > desc >
 *                checklist > comment).
 *   - full(q):   complete result list grouped by source kind for the
 *                dedicated search page.
 *
 * Both return matched-snippet "highlight" data so the client can render the
 * matched phrase in bold without re-running the regex.
 */

class SearchController extends Controller
{
    /** Hard cap on result size to keep query + render cheap. */
    private const QUICK_LIMIT = 8;
    private const FULL_LIMIT  = 60;
    /** Minimum query length — single letters explode result sets. */
    private const MIN_QUERY   = 2;
    /** Highlight snippet window (chars before/after the match). */
    private const SNIPPET_PAD = 60;

    public function quick(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $q = trim((string) ($_GET['q'] ?? ''));
        if (strlen($q) < self::MIN_QUERY) {
            $this->json(['results' => [], 'query' => $q]);
            return;
        }

        $rows = $this->search($q, self::QUICK_LIMIT, true);
        $this->json(['results' => $rows, 'query' => $q]);
    }

    public function full(): void
    {
        $this->requireAuth();
        $this->requireGet();

        $q = trim((string) ($_GET['q'] ?? ''));
        if (strlen($q) < self::MIN_QUERY) {
            $this->json(['results' => [], 'query' => $q, 'total' => 0]);
            return;
        }

        $rows = $this->search($q, self::FULL_LIMIT, false);
        $this->json(['results' => $rows, 'query' => $q, 'total' => count($rows)]);
    }

    /**
     * Run all four sub-queries, merge by card_id keeping the highest-priority
     * match per card (so the dropdown isn't cluttered with duplicates), then
     * trim to $limit. When $dedupePerCard is false the full-page view keeps
     * every match so users can see all hit kinds for the same card.
     *
     * @return array<int,array<string,mixed>>
     */
    private function search(string $q, int $limit, bool $dedupePerCard): array
    {
        $db = Database::get();
        $userId = Auth::userId();
        $isAdmin = Auth::isAdmin();

        // Board-access predicate. Personal boards are strict-owner; everything
        // else is "member of the board OR site admin". Compiled once so each
        // sub-query gets the same scoping.
        $accessClause = '(
            (b.is_personal = 1 AND b.created_by = :uid_p)
            OR (b.is_personal = 0 AND (
                :is_admin = 1
                OR EXISTS (SELECT 1 FROM board_members bm
                           WHERE bm.board_id = b.id AND bm.user_id = :uid_m)
            ))
        )';
        $accessParams = [
            'uid_p'    => $userId,
            'uid_m'    => $userId,
            'is_admin' => $isAdmin ? 1 : 0,
        ];

        $like = '%' . $this->escapeLike($q) . '%';

        // 1. Card title
        $stmt = $db->prepare(
            "SELECT c.id AS card_id, c.title AS card_title, l.board_id, b.title AS board_title,
                    c.title AS match_text, 'title' AS kind, 1 AS priority
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE c.is_archived = 0
               AND b.is_archived = 0
               AND c.title LIKE :q
               AND {$accessClause}
             LIMIT 200"
        );
        $stmt->execute($accessParams + ['q' => $like]);
        $titleHits = $stmt->fetchAll();

        // 2. Card description
        $stmt = $db->prepare(
            "SELECT c.id AS card_id, c.title AS card_title, l.board_id, b.title AS board_title,
                    c.description AS match_text, 'description' AS kind, 2 AS priority
             FROM cards c
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE c.is_archived = 0
               AND b.is_archived = 0
               AND c.description IS NOT NULL
               AND c.description LIKE :q
               AND {$accessClause}
             LIMIT 200"
        );
        $stmt->execute($accessParams + ['q' => $like]);
        $descHits = $stmt->fetchAll();

        // 3. Checklist items
        $stmt = $db->prepare(
            "SELECT c.id AS card_id, c.title AS card_title, l.board_id, b.title AS board_title,
                    ci.content AS match_text, ci.id AS item_id, 'checklist' AS kind, 3 AS priority
             FROM checklist_items ci
             JOIN checklists ch ON ci.checklist_id = ch.id
             JOIN cards c ON ch.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             WHERE c.is_archived = 0
               AND b.is_archived = 0
               AND ci.content LIKE :q
               AND {$accessClause}
             LIMIT 200"
        );
        $stmt->execute($accessParams + ['q' => $like]);
        $itemHits = $stmt->fetchAll();

        // 4. Comments
        $stmt = $db->prepare(
            "SELECT c.id AS card_id, c.title AS card_title, l.board_id, b.title AS board_title,
                    cm.body AS match_text, cm.id AS comment_id, u.display_name AS author_name,
                    'comment' AS kind, 4 AS priority
             FROM comments cm
             JOIN cards c ON cm.card_id = c.id
             JOIN lists l ON c.list_id = l.id
             JOIN boards b ON b.id = l.board_id
             JOIN users u ON cm.user_id = u.id
             WHERE c.is_archived = 0
               AND b.is_archived = 0
               AND cm.body LIKE :q
               AND {$accessClause}
             LIMIT 200"
        );
        $stmt->execute($accessParams + ['q' => $like]);
        $commentHits = $stmt->fetchAll();

        $all = array_merge($titleHits, $descHits, $itemHits, $commentHits);

        // Stable sort: priority ascending (title first), then card_id.
        usort($all, function ($a, $b) {
            $p = (int) $a['priority'] <=> (int) $b['priority'];
            if ($p !== 0) return $p;
            return (int) $b['card_id'] <=> (int) $a['card_id'];
        });

        // Per-card dedupe for the dropdown: keep the highest-priority hit only.
        if ($dedupePerCard) {
            $seen = [];
            $deduped = [];
            foreach ($all as $row) {
                $cid = (int) $row['card_id'];
                if (isset($seen[$cid])) continue;
                $seen[$cid] = true;
                $deduped[] = $row;
            }
            $all = $deduped;
        }

        $all = array_slice($all, 0, $limit);

        // Add highlight snippet + lowercase match offset for client rendering.
        foreach ($all as &$row) {
            $row['snippet'] = $this->buildSnippet((string) $row['match_text'], $q);
            $row['card_id'] = (int) $row['card_id'];
            $row['board_id'] = (int) $row['board_id'];
            if (isset($row['item_id']))    $row['item_id']    = (int) $row['item_id'];
            if (isset($row['comment_id'])) $row['comment_id'] = (int) $row['comment_id'];
        }
        unset($row);

        return $all;
    }

    /**
     * Escape the user query for safe LIKE matching — back-slash escapes the
     * three LIKE-special characters so a search for "50%" doesn't match
     * everything.
     */
    private function escapeLike(string $q): string
    {
        return strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /**
     * Center a window around the first case-insensitive occurrence of $q in
     * $text, ellipsizing the parts that don't fit. Returns the slice; the
     * client highlights matches inside it.
     */
    private function buildSnippet(string $text, string $q): string
    {
        $text = (string) $text;
        if ($text === '') return '';
        $pos = stripos($text, $q);
        if ($pos === false) {
            return mb_substr($text, 0, self::SNIPPET_PAD * 2);
        }
        $start = max(0, $pos - self::SNIPPET_PAD);
        $len   = self::SNIPPET_PAD * 2 + strlen($q);
        $slice = mb_substr($text, $start, $len);
        if ($start > 0)  $slice = '…' . $slice;
        $rawEnd = $start + $len;
        if ($rawEnd < mb_strlen($text)) $slice .= '…';
        return $slice;
    }
}
