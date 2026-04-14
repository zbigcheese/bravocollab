/**
 * BravoOrganizer - Card Detail Modal
 */
const CardModal = {
    currentCard: null,
    boardMembers: [],
    boardLabels: [],

    async open(cardId) {
        try {
            const res = await App.api('cards.get', { id: cardId }, 'GET');
            this.currentCard = res.card;
            this.boardMembers = Board.data?.members || [];
            this.boardLabels = Board.data?.labels || [];
            this.render();
        } catch (e) {
            App.showToast('Failed to load card', 'error');
        }
    },

    render() {
        const c = this.currentCard;
        const existing = document.getElementById('cardDetailModal');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.id = 'cardDetailModal';
        overlay.className = 'modal-overlay card-detail-modal';

        overlay.innerHTML = `
            <div class="modal">
                <div class="modal-header">
                    <textarea class="card-detail-title" id="cardTitle" rows="1">${App.escapeHtml(c.title)}</textarea>
                    <button class="modal-close" id="closeCardModal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="card-detail-layout">
                        <div class="card-detail-main">
                            ${this.renderMembersSection()}
                            ${this.renderLabelsSection()}
                            ${this.renderDueDateSection()}
                            ${this.renderDescriptionSection()}
                            ${this.renderChecklistsSection()}
                            ${this.renderAttachmentsSection()}
                            ${this.renderCommentsSection()}
                        </div>
                        <div class="card-detail-sidebar">
                            <h4 style="font-size:12px;color:var(--color-text-light);text-transform:uppercase;margin-bottom:4px;">Add to card</h4>
                            <button class="btn btn-secondary btn-sm" id="sidebarMembers">Members</button>
                            <button class="btn btn-secondary btn-sm" id="sidebarLabels">Labels</button>
                            <button class="btn btn-secondary btn-sm" id="sidebarChecklist">Checklist</button>
                            <button class="btn btn-secondary btn-sm" id="sidebarDueDate">Due Date</button>
                            <button class="btn btn-secondary btn-sm" id="sidebarAttachment">Attachment</button>
                            <hr style="border:none;border-top:1px solid var(--color-border);margin:8px 0;">
                            <h4 style="font-size:12px;color:var(--color-text-light);text-transform:uppercase;margin-bottom:4px;">Actions</h4>
                            <button class="btn btn-secondary btn-sm" id="sidebarArchive">Archive</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        this.bindModalEvents(overlay);
        this.autoResizeTextarea(document.getElementById('cardTitle'));
    },

    renderMembersSection() {
        const c = this.currentCard;
        if (!c.assignees || c.assignees.length === 0) return '';

        const chips = c.assignees.map(a => `
            <span class="card-member-chip" data-user-id="${a.id}">
                ${App.avatarHtml(a.display_name, 'sm')}
                ${App.escapeHtml(a.display_name)}
                <span class="remove" data-user-id="${a.id}">&times;</span>
            </span>
        `).join('');

        return `
            <div class="card-section" id="membersSection">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <h3>Members</h3>
                </div>
                <div class="card-members">${chips}</div>
            </div>
        `;
    },

    renderLabelsSection() {
        const c = this.currentCard;
        if (!c.labels || c.labels.length === 0) return '';

        const pills = c.labels.map(l => `
            <span class="card-label-pill" style="background:${l.color}">${App.escapeHtml(l.name || '')}</span>
        `).join('');

        return `
            <div class="card-section" id="labelsSection">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    <h3>Labels</h3>
                </div>
                <div class="card-labels-list">${pills}</div>
            </div>
        `;
    },

    renderDueDateSection() {
        const c = this.currentCard;
        if (!c.due_date) return '';

        const due = App.formatDueDate(c.due_date);
        const cls = c.due_complete ? 'complete' : due.class;

        return `
            <div class="card-section" id="dueDateSection">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                    <h3>Due Date</h3>
                </div>
                <label class="due-date-display ${cls}" style="cursor:pointer;">
                    <input type="checkbox" ${c.due_complete ? 'checked' : ''} id="dueCompleteCheck" style="margin-right:4px;">
                    ${new Date(c.due_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}
                </label>
            </div>
        `;
    },

    renderDescriptionSection() {
        const c = this.currentCard;
        const hasDesc = c.description && c.description.trim();

        return `
            <div class="card-section">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>
                    <h3>Description</h3>
                </div>
                <div id="descriptionContainer">
                    <div class="card-description-display" id="descDisplay" ${hasDesc ? '' : 'style="display:none"'}>${App.escapeHtml(c.description || '')}</div>
                    <div class="${hasDesc ? 'card-description-placeholder' : 'card-description-display card-description-placeholder'}" id="descPlaceholder" ${hasDesc ? 'style="display:none"' : ''}>Add a more detailed description...</div>
                    <textarea class="card-description-edit" id="descEdit" style="display:none">${App.escapeHtml(c.description || '')}</textarea>
                    <div id="descActions" style="display:none;margin-top:8px;">
                        <button class="btn btn-primary btn-sm" id="saveDesc">Save</button>
                        <button class="btn btn-secondary btn-sm" id="cancelDesc">Cancel</button>
                    </div>
                </div>
            </div>
        `;
    },

    renderChecklistsSection() {
        const c = this.currentCard;
        if (!c.checklists || c.checklists.length === 0) return '';

        return c.checklists.map(cl => {
            const total = cl.items.length;
            const checked = cl.items.filter(i => i.is_checked).length;
            const pct = total > 0 ? Math.round((checked / total) * 100) : 0;

            const items = cl.items.map(item => `
                <div class="checklist-item ${item.is_checked ? 'checked' : ''}" data-item-id="${item.id}">
                    <input type="checkbox" ${item.is_checked ? 'checked' : ''} data-item-id="${item.id}" data-checklist-id="${cl.id}">
                    <span class="checklist-item-content">${App.escapeHtml(item.content)}</span>
                    <button class="delete-item" data-item-id="${item.id}" data-checklist-id="${cl.id}">&times;</button>
                </div>
            `).join('');

            return `
                <div class="card-section checklist-section" data-checklist-id="${cl.id}">
                    <div class="card-section-header">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <h3>${App.escapeHtml(cl.title)}</h3>
                        <button class="btn btn-sm btn-secondary delete-checklist" data-checklist-id="${cl.id}" style="margin-left:auto;">Delete</button>
                    </div>
                    <div class="checklist-progress-bar"><div class="checklist-progress-fill" style="width:${pct}%"></div></div>
                    ${items}
                    <div class="add-checklist-item">
                        <input type="text" placeholder="Add an item..." data-checklist-id="${cl.id}" class="add-item-input">
                        <button class="btn btn-primary btn-sm add-item-btn" data-checklist-id="${cl.id}">Add</button>
                    </div>
                </div>
            `;
        }).join('');
    },

    renderAttachmentsSection() {
        const c = this.currentCard;
        if (!c.attachments || c.attachments.length === 0) return '';

        const items = c.attachments.map(a => {
            const thumb = a.is_image && a.thumbnail_path
                ? `<img class="attachment-thumb lightbox-trigger" src="api.php?action=attachments.download&id=${a.id}&thumb=1" data-attachment-id="${a.id}" alt="">`
                : `<div class="attachment-icon">${this.getFileExt(a.original_name)}</div>`;

            const size = this.formatFileSize(a.file_size);

            return `
                <div class="attachment-item" data-attachment-id="${a.id}">
                    ${thumb}
                    <div class="attachment-info">
                        <div class="attachment-name">${App.escapeHtml(a.original_name)}</div>
                        <div class="attachment-meta">${size} &middot; ${App.formatDate(a.created_at)} &middot; ${App.escapeHtml(a.uploader_name)}</div>
                    </div>
                    <div class="attachment-actions">
                        <a href="api.php?action=attachments.download&id=${a.id}" class="btn btn-sm btn-secondary" download>Download</a>
                        <button class="btn btn-sm btn-danger delete-attachment" data-attachment-id="${a.id}">&times;</button>
                    </div>
                </div>
            `;
        }).join('');

        return `
            <div class="card-section" id="attachmentsSection">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    <h3>Attachments</h3>
                </div>
                <div class="attachment-list">${items}</div>
            </div>
        `;
    },

    renderCommentsSection() {
        const c = this.currentCard;
        const comments = (c.comments || []).map(cm => `
            <div class="comment-item" data-comment-id="${cm.id}">
                ${App.avatarHtml(cm.author_name)}
                <div class="comment-body">
                    <div class="comment-header">
                        <span class="comment-author">${App.escapeHtml(cm.author_name)}</span>
                        <span class="comment-time">${App.formatDate(cm.created_at)}${cm.is_edited ? ' (edited)' : ''}</span>
                    </div>
                    <div class="comment-text">${App.escapeHtml(cm.body)}</div>
                    <div class="comment-actions">
                        <button class="edit-comment" data-comment-id="${cm.id}">Edit</button>
                        <button class="delete-comment" data-comment-id="${cm.id}">Delete</button>
                    </div>
                </div>
            </div>
        `).join('');

        return `
            <div class="card-section" id="commentsSection">
                <div class="card-section-header">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <h3>Comments</h3>
                </div>
                <div class="comment-form" style="margin-bottom:16px;">
                    <textarea id="newComment" placeholder="Write a comment..."></textarea>
                    <button class="btn btn-primary btn-sm" id="submitComment" style="margin-top:6px;">Save</button>
                </div>
                <div id="commentsList">${comments || '<p class="text-muted text-sm">No comments yet.</p>'}</div>
            </div>
        `;
    },

    bindModalEvents(overlay) {
        const c = this.currentCard;

        // Close
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });
        document.getElementById('closeCardModal').addEventListener('click', () => overlay.remove());
        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape' && document.getElementById('cardDetailModal')) {
                // Don't close if lightbox is open
                if (document.querySelector('.lightbox-overlay')) return;
                overlay.remove();
                document.removeEventListener('keydown', handler);
            }
        });

        // Title edit
        const titleEl = document.getElementById('cardTitle');
        titleEl.addEventListener('blur', async () => {
            const newTitle = titleEl.value.trim();
            if (newTitle && newTitle !== c.title) {
                await App.api('cards.update', { id: c.id, title: newTitle });
                c.title = newTitle;
                this.refreshBoardCard();
            }
        });
        titleEl.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
        });

        // Description
        const descDisplay = document.getElementById('descDisplay');
        const descPlaceholder = document.getElementById('descPlaceholder');
        const descEdit = document.getElementById('descEdit');
        const descActions = document.getElementById('descActions');

        const showDescEdit = () => {
            descDisplay.style.display = 'none';
            descPlaceholder.style.display = 'none';
            descEdit.style.display = 'block';
            descActions.style.display = 'block';
            descEdit.focus();
        };

        descDisplay?.addEventListener('click', showDescEdit);
        descPlaceholder?.addEventListener('click', showDescEdit);

        document.getElementById('saveDesc')?.addEventListener('click', async () => {
            const desc = descEdit.value.trim();
            await App.api('cards.update', { id: c.id, description: desc });
            c.description = desc;
            descEdit.style.display = 'none';
            descActions.style.display = 'none';
            if (desc) {
                descDisplay.textContent = desc;
                descDisplay.style.display = 'block';
                descPlaceholder.style.display = 'none';
            } else {
                descDisplay.style.display = 'none';
                descPlaceholder.style.display = 'block';
            }
            this.refreshBoardCard();
        });

        document.getElementById('cancelDesc')?.addEventListener('click', () => {
            descEdit.style.display = 'none';
            descActions.style.display = 'none';
            descEdit.value = c.description || '';
            if (c.description) {
                descDisplay.style.display = 'block';
            } else {
                descPlaceholder.style.display = 'block';
            }
        });

        // Due complete toggle
        document.getElementById('dueCompleteCheck')?.addEventListener('change', async (e) => {
            const complete = e.target.checked;
            await App.api('cards.update', { id: c.id, due_complete: complete });
            c.due_complete = complete;
            this.refreshBoardCard();
        });

        // Comments
        document.getElementById('submitComment')?.addEventListener('click', () => this.addComment());
        document.getElementById('newComment')?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.ctrlKey) this.addComment();
        });

        // Delegated comment actions
        overlay.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.edit-comment');
            if (editBtn) this.editComment(parseInt(editBtn.dataset.commentId));

            const deleteBtn = e.target.closest('.delete-comment');
            if (deleteBtn) this.deleteComment(parseInt(deleteBtn.dataset.commentId));

            // Checklist item toggle
            const checkbox = e.target.closest('.checklist-item input[type="checkbox"]');
            if (checkbox) this.toggleChecklistItem(parseInt(checkbox.dataset.itemId), parseInt(checkbox.dataset.checklistId), checkbox.checked);

            // Delete checklist item
            const delItem = e.target.closest('.delete-item');
            if (delItem) this.deleteChecklistItem(parseInt(delItem.dataset.itemId), parseInt(delItem.dataset.checklistId));

            // Add checklist item
            const addItemBtn = e.target.closest('.add-item-btn');
            if (addItemBtn) this.addChecklistItem(parseInt(addItemBtn.dataset.checklistId));

            // Delete checklist
            const delCl = e.target.closest('.delete-checklist');
            if (delCl) this.deleteChecklist(parseInt(delCl.dataset.checklistId));

            // Delete attachment
            const delAtt = e.target.closest('.delete-attachment');
            if (delAtt) this.deleteAttachment(parseInt(delAtt.dataset.attachmentId));

            // Lightbox trigger
            const lightboxTrigger = e.target.closest('.lightbox-trigger');
            if (lightboxTrigger) {
                e.preventDefault();
                this.openLightbox(parseInt(lightboxTrigger.dataset.attachmentId));
            }

            // Remove member
            const removeMember = e.target.closest('.card-member-chip .remove');
            if (removeMember) {
                this.unassignMember(parseInt(removeMember.dataset.userId));
            }
        });

        // Add checklist item on Enter
        overlay.addEventListener('keydown', (e) => {
            if (e.target.matches('.add-item-input') && e.key === 'Enter') {
                e.preventDefault();
                this.addChecklistItem(parseInt(e.target.dataset.checklistId));
            }
        });

        // Sidebar buttons
        document.getElementById('sidebarMembers')?.addEventListener('click', () => this.showMemberPicker());
        document.getElementById('sidebarLabels')?.addEventListener('click', () => this.showLabelPicker());
        document.getElementById('sidebarChecklist')?.addEventListener('click', () => this.addChecklist());
        document.getElementById('sidebarDueDate')?.addEventListener('click', () => this.showDueDatePicker());
        document.getElementById('sidebarAttachment')?.addEventListener('click', () => this.triggerAttachmentUpload());
        document.getElementById('sidebarArchive')?.addEventListener('click', () => this.archiveCard());
    },

    // ---- Comments ----
    async addComment() {
        const textarea = document.getElementById('newComment');
        const body = textarea.value.trim();
        if (!body) return;

        try {
            const res = await App.api('comments.create', {
                card_id: this.currentCard.id,
                body: body,
            });
            if (res.success) {
                textarea.value = '';
                this.open(this.currentCard.id); // Refresh
            }
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async editComment(commentId) {
        const comment = this.currentCard.comments.find(cm => cm.id === commentId);
        if (!comment) return;

        const commentEl = document.querySelector(`.comment-item[data-comment-id="${commentId}"] .comment-text`);
        const originalText = comment.body;

        commentEl.innerHTML = `
            <textarea class="comment-form-edit" style="width:100%;min-height:60px;padding:8px;border:2px solid var(--color-primary);border-radius:4px;font-size:14px;font-family:var(--font-family);">${App.escapeHtml(originalText)}</textarea>
            <div style="margin-top:6px;">
                <button class="btn btn-primary btn-sm save-edit-comment">Save</button>
                <button class="btn btn-secondary btn-sm cancel-edit-comment">Cancel</button>
            </div>
        `;

        const textarea = commentEl.querySelector('textarea');
        textarea.focus();

        commentEl.querySelector('.save-edit-comment').addEventListener('click', async () => {
            const newBody = textarea.value.trim();
            if (!newBody) return;
            try {
                await App.api('comments.update', { id: commentId, body: newBody });
                this.open(this.currentCard.id);
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });

        commentEl.querySelector('.cancel-edit-comment').addEventListener('click', () => {
            commentEl.textContent = originalText;
        });
    },

    async deleteComment(commentId) {
        try {
            await App.api('comments.delete', { id: commentId });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ---- Checklists ----
    async addChecklist() {
        const title = prompt('Checklist title:', 'Checklist');
        if (!title) return;

        try {
            await App.api('checklists.create', { card_id: this.currentCard.id, title });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async deleteChecklist(checklistId) {
        try {
            await App.api('checklists.delete', { id: checklistId });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async toggleChecklistItem(itemId, checklistId, checked) {
        try {
            await App.api('checklists.toggle_item', { id: itemId, is_checked: checked });
            // Update local state
            for (const cl of this.currentCard.checklists) {
                const item = cl.items.find(i => i.id === itemId);
                if (item) {
                    item.is_checked = checked ? 1 : 0;
                    break;
                }
            }
            // Update progress bar
            const section = document.querySelector(`.checklist-section[data-checklist-id="${checklistId}"]`);
            if (section) {
                const items = section.querySelectorAll('.checklist-item input[type="checkbox"]');
                const total = items.length;
                const done = Array.from(items).filter(i => i.checked).length;
                const pct = total > 0 ? Math.round((done / total) * 100) : 0;
                section.querySelector('.checklist-progress-fill').style.width = pct + '%';
                // Toggle line-through
                const itemEl = section.querySelector(`.checklist-item[data-item-id="${itemId}"]`);
                if (itemEl) itemEl.classList.toggle('checked', checked);
            }
            this.refreshBoardCard();
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async addChecklistItem(checklistId) {
        const input = document.querySelector(`.add-item-input[data-checklist-id="${checklistId}"]`);
        const content = input.value.trim();
        if (!content) return;

        try {
            await App.api('checklists.add_item', { checklist_id: checklistId, content });
            input.value = '';
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    async deleteChecklistItem(itemId, checklistId) {
        try {
            await App.api('checklists.delete_item', { id: itemId });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ---- Attachments ----
    triggerAttachmentUpload() {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = true;
        input.addEventListener('change', () => this.uploadFiles(input.files));
        input.click();
    },

    async uploadFiles(files) {
        for (const file of files) {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('card_id', this.currentCard.id);

            try {
                const res = await App.upload('attachments.upload', formData);
                if (res.error) throw new Error(res.error);
                App.showToast('File uploaded', 'success');
            } catch (e) {
                App.showToast(e.message || 'Upload failed', 'error');
            }
        }
        this.open(this.currentCard.id);
    },

    async deleteAttachment(attachmentId) {
        try {
            await App.api('attachments.delete', { id: attachmentId });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ---- Lightbox ----
    openLightbox(attachmentId) {
        const images = (this.currentCard.attachments || []).filter(a => a.is_image);
        let currentIdx = images.findIndex(a => a.id === attachmentId);
        if (currentIdx === -1) return;

        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';

        const show = (idx) => {
            const img = images[idx];
            overlay.innerHTML = `
                <button class="lightbox-close">&times;</button>
                ${images.length > 1 ? '<button class="lightbox-nav lightbox-prev">&lsaquo;</button>' : ''}
                <img class="lightbox-image" src="api.php?action=attachments.download&id=${img.id}" alt="${App.escapeHtml(img.original_name)}">
                ${images.length > 1 ? '<button class="lightbox-nav lightbox-next">&rsaquo;</button>' : ''}
                ${images.length > 1 ? `<div class="lightbox-counter">${idx + 1} / ${images.length}</div>` : ''}
            `;

            overlay.querySelector('.lightbox-close').addEventListener('click', () => overlay.remove());
            overlay.querySelector('.lightbox-prev')?.addEventListener('click', (e) => {
                e.stopPropagation();
                currentIdx = (currentIdx - 1 + images.length) % images.length;
                show(currentIdx);
            });
            overlay.querySelector('.lightbox-next')?.addEventListener('click', (e) => {
                e.stopPropagation();
                currentIdx = (currentIdx + 1) % images.length;
                show(currentIdx);
            });
        };

        show(currentIdx);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });

        const keyHandler = (e) => {
            if (e.key === 'Escape') { overlay.remove(); document.removeEventListener('keydown', keyHandler); }
            if (e.key === 'ArrowLeft') { currentIdx = (currentIdx - 1 + images.length) % images.length; show(currentIdx); }
            if (e.key === 'ArrowRight') { currentIdx = (currentIdx + 1) % images.length; show(currentIdx); }
        };
        document.addEventListener('keydown', keyHandler);
    },

    // ---- Members ----
    showMemberPicker() {
        const assignedIds = this.currentCard.assignees.map(a => a.id);

        const items = this.boardMembers.map(m => `
            <div class="member-picker-item ${assignedIds.includes(m.id) ? 'selected' : ''}" data-user-id="${m.id}">
                ${App.avatarHtml(m.display_name, 'sm')}
                <span>${App.escapeHtml(m.display_name)}</span>
                ${assignedIds.includes(m.id) ? '<span style="margin-left:auto;">&#10003;</span>' : ''}
            </div>
        `).join('');

        const modal = App.createModal('memberPickerModal', 'Members', `<div class="label-picker">${items}</div>`);

        modal.querySelectorAll('.member-picker-item').forEach(el => {
            el.addEventListener('click', async () => {
                const userId = parseInt(el.dataset.userId);
                const isAssigned = assignedIds.includes(userId);
                try {
                    if (isAssigned) {
                        await App.api('cards.unassign', { card_id: this.currentCard.id, user_id: userId });
                    } else {
                        await App.api('cards.assign', { card_id: this.currentCard.id, user_id: userId });
                    }
                    modal.remove();
                    this.open(this.currentCard.id);
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });
        });
    },

    async unassignMember(userId) {
        try {
            await App.api('cards.unassign', { card_id: this.currentCard.id, user_id: userId });
            this.open(this.currentCard.id);
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ---- Labels ----
    showLabelPicker() {
        const cardLabelIds = this.currentCard.labels.map(l => l.id);

        const items = this.boardLabels.map(l => `
            <div class="label-picker-item" data-label-id="${l.id}">
                <div class="label-picker-color" style="background:${l.color}">
                    ${App.escapeHtml(l.name || '')}
                    ${cardLabelIds.includes(l.id) ? '<span class="label-picker-check">&#10003;</span>' : ''}
                </div>
            </div>
        `).join('');

        const modal = App.createModal('labelPickerModal', 'Labels', `<div class="label-picker">${items}</div>`);

        modal.querySelectorAll('.label-picker-item').forEach(el => {
            el.addEventListener('click', async () => {
                const labelId = parseInt(el.dataset.labelId);
                const isAttached = cardLabelIds.includes(labelId);
                try {
                    if (isAttached) {
                        await App.api('labels.detach', { card_id: this.currentCard.id, label_id: labelId });
                    } else {
                        await App.api('labels.attach', { card_id: this.currentCard.id, label_id: labelId });
                    }
                    modal.remove();
                    this.open(this.currentCard.id);
                } catch (e) {
                    App.showToast(e.message, 'error');
                }
            });
        });
    },

    // ---- Due Date ----
    showDueDatePicker() {
        const current = this.currentCard.due_date ? this.currentCard.due_date.substring(0, 16) : '';

        const modal = App.createModal('dueDateModal', 'Due Date', `
            <div class="form-group">
                <label>Date & Time</label>
                <input type="datetime-local" id="dueDateInput" value="${current}">
            </div>
        `, `
            <button class="btn btn-secondary btn-sm" id="removeDueDate">Remove</button>
            <button class="btn btn-primary btn-sm" id="saveDueDate">Save</button>
        `);

        document.getElementById('saveDueDate').addEventListener('click', async () => {
            const val = document.getElementById('dueDateInput').value;
            if (!val) return;
            try {
                await App.api('cards.update', { id: this.currentCard.id, due_date: val });
                modal.remove();
                this.open(this.currentCard.id);
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });

        document.getElementById('removeDueDate').addEventListener('click', async () => {
            try {
                await App.api('cards.update', { id: this.currentCard.id, due_date: null });
                modal.remove();
                this.open(this.currentCard.id);
            } catch (e) {
                App.showToast(e.message, 'error');
            }
        });
    },

    // ---- Archive ----
    async archiveCard() {
        try {
            await App.api('cards.archive', { id: this.currentCard.id });
            document.getElementById('cardDetailModal')?.remove();
            Board.handleCardArchived({ card_id: this.currentCard.id });
            App.showToast('Card archived', 'info');
        } catch (e) {
            App.showToast(e.message, 'error');
        }
    },

    // ---- Helpers ----
    refreshBoardCard() {
        // Update the card in the board view
        if (Board.data) {
            for (const list of Board.data.lists) {
                const idx = list.cards.findIndex(c => c.id === this.currentCard.id);
                if (idx !== -1) {
                    Object.assign(list.cards[idx], this.currentCard);
                    Board.renderLists();
                    break;
                }
            }
        }
    },

    autoResizeTextarea(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
        el.addEventListener('input', () => {
            el.style.height = 'auto';
            el.style.height = el.scrollHeight + 'px';
        });
    },

    getFileExt(filename) {
        return (filename.split('.').pop() || '').toUpperCase().substring(0, 4);
    },

    formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    },
};
