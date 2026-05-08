<?php
$boardId = (int) ($_GET['id'] ?? 0);
if (!$boardId) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Detect "this is the current user's personal board" so the JS can grant
// owner-as-admin UX (label/description/background editing) and switch off
// the member-related controls.
require_once __DIR__ . '/../../models/Board.php';
$_pageBoard = (new Board())->find($boardId);
$_isPersonalOwner = $_pageBoard
    && (int) ($_pageBoard['is_personal'] ?? 0) === 1
    && (int) $_pageBoard['created_by'] === Auth::userId();
$_isPersonal = $_pageBoard && (int) ($_pageBoard['is_personal'] ?? 0) === 1;
$_canAdmin = Auth::isAdmin() || $_isPersonalOwner;
?>
<link rel="stylesheet" href="<?php echo asset_url('public/css/board_views.css'); ?>">
<div class="board-wrapper" id="boardWrapper"
     data-board-id="<?php echo $boardId; ?>"
     data-is-admin="<?php echo $_canAdmin ? '1' : '0'; ?>"
     data-is-personal="<?php echo $_isPersonal ? '1' : '0'; ?>">
    <div class="board-header" id="boardHeader">
        <div class="board-header-left">
            <button type="button" class="board-burger-btn" id="boardBurgerBtn" aria-label="Board controls" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <span class="board-archived-label" id="boardArchivedLabel" hidden>(Archived)&nbsp;</span>
            <h1 class="board-title" id="boardTitle"></h1>
        </div>
        <div class="board-header-right">
            <div class="board-members-preview" id="boardMembersPreview"></div>
            <div class="board-header-controls" id="boardHeaderControls">
                <label class="archived-toggle" title="Show archived cards">
                    <input type="checkbox" id="showArchivedToggle">
                    <span class="archived-toggle-slider"></span>
                    <span class="archived-toggle-label">Show archived</span>
                </label>
                <?php if ($_canAdmin): ?>
                <?php if (!$_isPersonal): ?>
                <button class="btn btn-sm btn-secondary" id="manageMembersBtn">Members</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-secondary mobile-only" id="mobileEditLabels">Edit Labels</button>
                <button class="btn btn-sm btn-secondary mobile-only" id="mobileEditDescription">Edit Description</button>
                <button class="btn btn-sm btn-secondary mobile-only" id="mobileChangeBackground">Change Background</button>
                <?php if (!$_isPersonal): ?>
                <button class="btn btn-sm btn-secondary mobile-only" id="mobileArchiveBoard">Archive Board</button>
                <button class="btn btn-sm btn-secondary mobile-only" id="mobileRestoreBoard" hidden>Restore Board</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-secondary desktop-only" id="boardMenuBtn">&#8943;</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="board-canvas" id="boardCanvas">
        <div class="lists-container" id="listsContainer"></div>
        <div class="add-list-wrapper">
            <button class="add-list-btn" id="addListBtn">+ Add a list</button>
            <div class="add-list-form" id="addListForm" style="display:none;">
                <input type="text" id="newListTitle" placeholder="Enter list title..." maxlength="255">
                <div class="add-list-actions">
                    <button class="btn btn-primary btn-sm" id="submitNewList">Add List</button>
                    <button class="btn-icon" id="cancelNewList">&times;</button>
                </div>
            </div>
        </div>
    </div>
    <div class="board-view-pane" id="calendarPane" style="display:none;"></div>
    <div class="board-view-pane" id="timelinePane" style="display:none;"></div>

    <div class="view-switcher" id="viewSwitcher" role="tablist" aria-label="Switch board view">
        <button class="view-switcher-btn" data-view="board" role="tab" title="Board">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="4" height="16" rx="1"/><rect x="10" y="4" width="4" height="11" rx="1"/><rect x="17" y="4" width="4" height="7" rx="1"/></svg>
            <span>Board</span>
        </button>
        <button class="view-switcher-btn" data-view="calendar" role="tab" title="Calendar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg>
            <span>Calendar</span>
        </button>
        <button class="view-switcher-btn" data-view="timeline" role="tab" title="Timeline">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="15" y2="6"/><line x1="7" y1="12" x2="21" y2="12"/><line x1="5" y1="18" x2="17" y2="18"/></svg>
            <span>Timeline</span>
        </button>
    </div>
</div>

<script src="<?php echo asset_url('public/vendor/sortable.min.js'); ?>"></script>
<script src="<?php echo asset_url('public/js/board.js'); ?>"></script>
<script src="<?php echo asset_url('public/js/card_modal.js'); ?>"></script>
<script src="<?php echo asset_url('public/js/board_views.js'); ?>"></script>
<script src="<?php echo asset_url('public/js/sse_client.js'); ?>"></script>
