<?php
$boardId = (int) ($_GET['id'] ?? 0);
if (!$boardId) {
    header('Location: index.php?page=dashboard');
    exit;
}
?>
<link rel="stylesheet" href="public/css/board_views.css">
<div class="board-wrapper" id="boardWrapper" data-board-id="<?php echo $boardId; ?>" data-is-admin="<?php echo Auth::isAdmin() ? '1' : '0'; ?>">
    <div class="board-header" id="boardHeader">
        <div class="board-header-left">
            <h1 class="board-title" id="boardTitle"></h1>
        </div>
        <div class="board-header-right">
            <div class="board-members-preview" id="boardMembersPreview"></div>
            <?php if (Auth::isAdmin()): ?>
            <button class="btn btn-sm btn-secondary" id="manageMembersBtn">Members</button>
            <?php endif; ?>
            <?php if (Auth::isAdmin()): ?>
            <button class="btn btn-sm btn-secondary" id="boardMenuBtn">&#8943;</button>
            <?php endif; ?>
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

<script src="public/vendor/sortable.min.js"></script>
<script src="public/js/board.js"></script>
<script src="public/js/card_modal.js"></script>
<script src="public/js/board_views.js"></script>
<script src="public/js/sse_client.js"></script>
