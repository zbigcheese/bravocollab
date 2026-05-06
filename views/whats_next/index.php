<?php
require_once __DIR__ . '/../../core/Mailer.php';

$cetTz = new DateTimeZone('Europe/Belgrade');
// Optional ?date=YYYY-MM-DD anchors the window. Defaults to today CET.
$dateParam = $_GET['date'] ?? '';
$anchor = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    try { $anchor = new DateTime($dateParam, $cetTz); } catch (Throwable $e) { $anchor = null; }
}
if (!$anchor) {
    $anchor = new DateTime('now', $cetTz);
}
$anchor->setTime(0, 0, 0);

$sections = Mailer::buildWhatsNextSectionsForUser(Database::get(), Auth::userId(), $anchor);

// Totals — useful both for the empty-state copy and for the page header.
$cardsTotal = 0;
$itemsTotal = 0;
foreach ($sections as $s) {
    $cardsTotal += count($s['cards']);
    $itemsTotal += count($s['items']);
}

// Prev / next day links scoped to the same view.
$prevDate = (clone $anchor)->modify('-1 day')->format('Y-m-d');
$nextDate = (clone $anchor)->modify('+1 day')->format('Y-m-d');
$todayStr = (new DateTime('now', $cetTz))->format('Y-m-d');
$showingToday = $anchor->format('Y-m-d') === $todayStr;
?>
<div class="wn-page">
    <div class="wn-toolbar">
        <a class="wn-nav" href="index.php?page=whats_next&date=<?php echo $prevDate; ?>" aria-label="Previous day">‹</a>
        <div class="wn-title">
            <?php
                $fmt = $anchor->format('l, M j');
                if ($showingToday) echo "Today &middot; {$fmt}";
                else               echo $fmt;
            ?>
        </div>
        <a class="wn-nav" href="index.php?page=whats_next&date=<?php echo $nextDate; ?>" aria-label="Next day">›</a>
        <?php if (!$showingToday): ?>
            <a class="wn-today" href="index.php?page=whats_next">Today</a>
        <?php endif; ?>
    </div>

    <?php if (empty($sections)): ?>
        <div class="wn-empty">
            <h2>Nothing on your plate for the next 8 days.</h2>
            <p>Cards and tasks assigned to you, plus anything in your personal board, would show here.</p>
        </div>
    <?php else: ?>
        <p class="wn-summary">
            <?php echo $cardsTotal; ?> card<?php echo $cardsTotal === 1 ? '' : 's'; ?>
            and
            <?php echo $itemsTotal; ?> task<?php echo $itemsTotal === 1 ? '' : 's'; ?>
            coming up.
        </p>

        <?php foreach ($sections as $sec): ?>
            <div class="wn-section">
                <h2 class="wn-section-heading"><?php echo htmlspecialchars($sec['label']); ?></h2>
                <ul class="wn-list">
                    <?php foreach ($sec['cards'] as $c): ?>
                        <li class="wn-item wn-card">
                            <a href="index.php?page=board&id=<?php echo (int) $c['board_id']; ?>&card=<?php echo (int) $c['id']; ?>">
                                <span class="wn-item-title"><?php echo htmlspecialchars($c['title']); ?></span>
                                <span class="wn-item-meta"><?php echo htmlspecialchars($c['board_title']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($sec['items'] as $it): ?>
                        <li class="wn-item wn-task">
                            <a href="index.php?page=board&id=<?php echo (int) $it['board_id']; ?>&card=<?php echo (int) $it['card_id']; ?>">
                                <span class="wn-item-glyph" aria-hidden="true">&#9745;</span>
                                <span class="wn-item-title"><?php echo htmlspecialchars($it['content']); ?></span>
                                <span class="wn-item-meta"><?php echo htmlspecialchars($it['card_title']); ?> &middot; <?php echo htmlspecialchars($it['board_title']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.wn-page { max-width: 720px; margin: 24px auto; padding: 0 16px; }
.wn-toolbar {
    display: flex; align-items: center; gap: 8px;
    background: #fff; border-radius: 8px; padding: 8px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 14px;
}
.wn-title { flex: 1; font-weight: 700; color: #172b4d; text-align: center; font-size: 15px; }
.wn-nav, .wn-today {
    text-decoration: none; color: var(--color-text);
    background: var(--color-bg); border-radius: 4px;
    padding: 4px 12px; font-size: 14px; font-weight: 700;
}
.wn-nav:hover, .wn-today:hover { background: var(--color-border); }
.wn-summary { color: var(--color-text-light); margin: 0 4px 14px; font-size: 14px; }
.wn-empty { background: #fff; border-radius: 8px; padding: 40px 24px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.wn-empty h2 { color: #172b4d; font-size: 18px; margin: 0 0 8px; }
.wn-empty p  { color: var(--color-text-light); margin: 0; }

.wn-section { background: #fff; border-radius: 8px; padding: 14px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.wn-section-heading {
    font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em;
    color: #5e6c84; margin: 0 0 8px; font-weight: 700;
}
.wn-list { list-style: none; padding: 0; margin: 0; }
.wn-item { padding: 8px 0; border-bottom: 1px solid var(--color-border); }
.wn-item:last-child { border-bottom: none; }
.wn-item a { display: block; color: inherit; text-decoration: none; }
.wn-item a:hover .wn-item-title { color: var(--color-primary-dark); }
.wn-item-title { font-weight: 600; color: var(--color-text); margin-right: 6px; }
.wn-item-meta  { color: var(--color-text-light); font-size: 12px; display: block; margin-top: 2px; }
.wn-task .wn-item-glyph { color: var(--color-text-light); margin-right: 6px; }
</style>
