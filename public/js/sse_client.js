/**
 * BravoCollab - SSE Client
 */
const SSEClient = {
    source: null,
    boardId: null,
    reconnectAttempts: 0,

    connect(boardId) {
        this.boardId = boardId;
        this.disconnect();

        const url = `sse.php?board_id=${boardId}`;
        this.source = new EventSource(url);

        this.source.addEventListener('connected', () => {
            this.reconnectAttempts = 0;
        });

        // Board-level events (always process — these affect the board view)
        this.source.addEventListener('card_created', (e) => {
            Board.handleCardCreated(JSON.parse(e.data));
        });

        this.source.addEventListener('card_updated', (e) => {
            const data = JSON.parse(e.data);
            const cardId = parseInt(data.card?.id || data.card_id);
            // Always refresh the board thumbnail (labels/assignees/coordinator need this);
            // modal suppression only applies to the actor's own open modal, not the board.
            const openCardId = CardModal.currentCard ? parseInt(CardModal.currentCard.id) : null;
            if (CardModal.isSuppressed() && openCardId === cardId) return;
            Board.handleCardUpdated(data);
        });

        this.source.addEventListener('card_moved', (e) => {
            Board.handleCardMoved(JSON.parse(e.data));
        });

        this.source.addEventListener('card_archived', (e) => {
            Board.handleCardArchived(JSON.parse(e.data));
        });

        this.source.addEventListener('list_created', (e) => {
            Board.handleListCreated(JSON.parse(e.data));
        });

        this.source.addEventListener('list_updated', (e) => {
            Board.handleListUpdated(JSON.parse(e.data));
        });

        this.source.addEventListener('list_reordered', (e) => {
            Board.handleListReordered(JSON.parse(e.data));
        });

        this.source.addEventListener('list_archived', (e) => {
            Board.handleListArchived(JSON.parse(e.data));
        });

        // Card sub-item events — refresh the board thumbnail (counts/labels) and,
        // if the modal is open on the same card for another user's change, reopen it.
        const handleSubItemEvent = (data) => {
            const cardId = parseInt(data.card_id);
            if (!cardId) return;
            // Always refresh board thumbnail for counts/progress
            Board.refreshCardThumbnail(cardId);
            // Refresh open modal only if it's someone else's change on the currently-open card
            const openCardId = CardModal.currentCard ? parseInt(CardModal.currentCard.id) : null;
            if (openCardId === cardId && !CardModal.isSuppressed()) {
                CardModal.open(cardId);
            }
        };

        this.source.addEventListener('comment_added', (e) => handleSubItemEvent(JSON.parse(e.data)));
        this.source.addEventListener('comment_updated', (e) => handleSubItemEvent(JSON.parse(e.data)));
        this.source.addEventListener('comment_deleted', (e) => handleSubItemEvent(JSON.parse(e.data)));
        this.source.addEventListener('checklist_changed', (e) => handleSubItemEvent(JSON.parse(e.data)));
        this.source.addEventListener('attachment_added', (e) => handleSubItemEvent(JSON.parse(e.data)));
        this.source.addEventListener('attachment_deleted', (e) => handleSubItemEvent(JSON.parse(e.data)));

        // Board-level label changes (created/updated/deleted) — reload board data
        // so all cards pick up new colors/names without reconnecting SSE.
        this.source.addEventListener('label_changed', () => {
            Board.refreshBoardData();
        });

        // Notification count updates
        this.source.addEventListener('notification_count', (e) => {
            const data = JSON.parse(e.data);
            Notifications.updateBadge(data.count);
        });

        this.source.onerror = () => {
            this.reconnectAttempts++;
        };
    },

    disconnect() {
        if (this.source) {
            this.source.close();
            this.source = null;
        }
    },
};
