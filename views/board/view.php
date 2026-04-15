<?php
$boardId = (int) ($_GET['id'] ?? 0);
if (!$boardId) {
    header('Location: index.php?page=dashboard');
    exit;
}
?>
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
</div>

<script src="public/vendor/sortable.min.js"></script>
<script src="public/js/board.js"></script>
<script src="public/js/card_modal.js"></script>
<script src="public/js/sse_client.js"></script>
