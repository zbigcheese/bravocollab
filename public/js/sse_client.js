/**
 * BravoCollab - SSE Client
 */
const SSEClient = {
    source: null,
    boardId: null,
    reconnectAttempts: 0,
    maxReconnectDelay: 30000,

    connect(boardId) {
        this.boardId = boardId;
        this.disconnect();

        const url = `sse.php?board_id=${boardId}`;
        this.source = new EventSource(url);

        this.source.addEventListener('connected', () => {
            this.reconnectAttempts = 0;
        });

        // Board events
        this.source.addEventListener('card_created', (e) => {
            Board.handleCardCreated(JSON.parse(e.data));
        });

        this.source.addEventListener('card_updated', (e) => {
            Board.handleCardUpdated(JSON.parse(e.data));
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

        this.source.addEventListener('comment_added', (e) => {
            // If the card modal is open for this card, refresh it
            const data = JSON.parse(e.data);
            if (CardModal.currentCard && CardModal.currentCard.id === data.card_id) {
                CardModal.open(data.card_id);
            }
        });

        this.source.addEventListener('checklist_changed', (e) => {
            const data = JSON.parse(e.data);
            if (CardModal.currentCard && CardModal.currentCard.id === data.card_id) {
                CardModal.open(data.card_id);
            }
        });

        this.source.addEventListener('attachment_added', (e) => {
            const data = JSON.parse(e.data);
            if (CardModal.currentCard && CardModal.currentCard.id === data.card_id) {
                CardModal.open(data.card_id);
            }
        });

        this.source.addEventListener('attachment_deleted', (e) => {
            const data = JSON.parse(e.data);
            if (CardModal.currentCard && CardModal.currentCard.id === data.card_id) {
                CardModal.open(data.card_id);
            }
        });

        // Notification count updates
        this.source.addEventListener('notification_count', (e) => {
            const data = JSON.parse(e.data);
            Notifications.updateBadge(data.count);
        });

        this.source.onerror = () => {
            // EventSource auto-reconnects, but we track for exponential backoff logging
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
