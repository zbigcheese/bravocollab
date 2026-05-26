/**
 * BravoCollab - Tasks/subtasks UI (inside the card modal).
 *
 * Two display modes:
 *   - Root list: rendered inline at #tasksRootList alongside the rest of
 *     the card sections. Each task is a tappable row showing title,
 *     assignees, due date, comment/attachment counters, and (for assigned
 *     users) an Accept button.
 *   - Task page: full-bleed view that takes over the modal body when a
 *     task is opened. Shows breadcrumb (Card › Parent task › This task),
 *     description, due date, assignees, attachments, sub-task list, and
 *     comment thread.
 *
 * Sub-pages are kept stackable via a small history (so back-nav works);
 * closing the modal resets everything.
 *
 * SSE: CardModal forwards SSE_TASK_CHANGED events to TaskView.refresh()
 * which re-fetches the tree (and the current open task if any).
 */
const TaskView = {
    cardId: null,
    boardId: null,
    boardMembers: [],
    coordinatorId: null,
    tasks: [],
    openTaskId: null,
    openTask: null,
    // Stack of task IDs the user navigated into — feeds the back button.
    navStack: [],
    // Per-task-page staged file lists keyed by task id. Stored on the
    // instance so re-renders during the same session don't drop pending
    // files. Cleared when the task page closes.
    _stagedFiles: {},

    bootstrap(card, boardMembers) {
        this.cardId = parseInt(card.id);
        this.boardId = parseInt(card.board_id || (typeof Board !== 'undefined' ? Board.boardId : 0));
        this.boardMembers = boardMembers || [];
        this.coordinatorId = card.coordinator ? parseInt(card.coordinator.id) : null;
        this.openTaskId = null;
        this.openTask = null;
        this.navStack = [];

        document.getElementById('taskAddRootBtn')?.addEventListener('click', () => this.addRootTask());

        this.refresh();
    },

    async refresh() {
        try {
            const res = await App.api('tasks.list', { card_id: this.cardId }, 'GET');
            this.tasks = res.tasks || [];
            this.renderRootList();
            // Keep the currently-open task page in sync.
            if (this.openTaskId) {
                this.openTaskPage(this.openTaskId, /*push*/ false);
            }
        } catch (e) {
            // Likely a permissions or DB issue. Surface quietly.
            const el = document.getElementById('tasksRootList');
            if (el) el.innerHTML = '<div class="task-empty">Failed to load tasks</div>';
        }
    },

    // ---- Root list ----
    renderRootList() {
        const el = document.getElementById('tasksRootList');
        if (!el) return;
        if (!this.tasks.length) {
            el.innerHTML = '<div class="task-empty">No tasks yet. Click "+ Add task" to get started.</div>';
            return;
        }
        el.innerHTML = this.tasks.map(t => this.rowHtml(t)).join('');
        // Delegated click → open the task page.
        el.querySelectorAll('[data-task-id]').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('[data-noopen]')) return;
                const id = parseInt(row.dataset.taskId);
                this.openTaskPage(id, /*push*/ true);
            });
        });
    },

    rowHtml(t) {
        const id = parseInt(t.id);
        const dueHtml = t.due_date ? this.formatDuePill(t.due_date, t.is_complete) : '';
        const assigneesHtml = (t.assignees || []).slice(0, 3).map(a =>
            App.avatarHtml(a.display_name, 'sm')
        ).join('');
        const meAssigned = (t.assignees || []).find(a => parseInt(a.id) === App.userId);
        const acceptHtml = meAssigned
            ? (meAssigned.accepted_at
                ? '<span class="task-accepted-pill" data-noopen>&#10003; Accepted</span>'
                : `<button class="task-accept-btn" data-noopen data-task-id="${id}">Accept</button>`)
            : '';
        const counts = [];
        if (t.child_count > 0)       counts.push(`<span title="Sub-tasks">${t.child_count} sub</span>`);
        if (t.comment_count > 0)     counts.push(`<span title="Comments">&#128172; ${t.comment_count}</span>`);
        if (t.attachment_count > 0)  counts.push(`<span title="Attachments">&#128206; ${t.attachment_count}</span>`);
        const completeCls = parseInt(t.is_complete) === 1 ? ' is-complete' : '';

        return `
            <div class="task-row${completeCls}" data-task-id="${id}">
                <div class="task-row-main">
                    <div class="task-row-title">${App.escapeHtml(t.title)}</div>
                    ${counts.length ? `<div class="task-row-counts">${counts.join(' &middot; ')}</div>` : ''}
                </div>
                <div class="task-row-meta">
                    ${dueHtml}
                    ${assigneesHtml ? `<div class="task-row-assignees">${assigneesHtml}</div>` : ''}
                    ${acceptHtml}
                    <span class="task-row-chevron">&rsaquo;</span>
                </div>
            </div>
        `;
    },

    formatDuePill(due, isComplete) {
        const info = App.formatDueDate(due);
        const cls = isComplete ? 'complete' : info.class;
        return `<span class="task-due-pill ${cls}">${App.escapeHtml(info.text)}</span>`;
    },

    async addRootTask() {
        const title = prompt('Task title:');
        if (!title || !title.trim()) return;
        try {
            await App.api('tasks.create', { card_id: this.cardId, title: title.trim() });
            await this.refresh();
        } catch (e) {
            App.showToast(e.message || 'Failed to create task', 'error');
        }
    },

    // ---- Task page (full-bleed inside the modal) ----
    async openTaskPage(taskId, push) {
        try {
            const res = await App.api('tasks.get', { id: taskId }, 'GET');
            const task = res.task;
            if (!task) return;

            if (push && this.openTaskId && this.openTaskId !== taskId) {
                this.navStack.push(this.openTaskId);
            }

            this.openTaskId = parseInt(task.id);
            this.openTask = task;
            this.renderTaskPage(task);
        } catch (e) {
            App.showToast(e.message || 'Failed to open task', 'error');
        }
    },

    closeTaskPage() {
        this.openTaskId = null;
        this.openTask = null;
        this.navStack = [];
        // Drop any files staged for this session — they were never uploaded
        // and have no on-disk presence to clean up.
        this._stagedFiles = {};
        const overlay = document.getElementById('cardDetailModal');
        const existing = overlay?.querySelector('#taskPage');
        if (existing) existing.remove();
        const main = overlay?.querySelector('.card-detail-main');
        const sidebar = overlay?.querySelector('.card-detail-sidebar');
        if (main) main.style.display = '';
        if (sidebar) sidebar.style.display = '';
    },

    goBack() {
        if (this.navStack.length === 0) {
            this.closeTaskPage();
        } else {
            const prev = this.navStack.pop();
            this.openTaskPage(prev, /*push*/ false);
        }
    },

    renderTaskPage(task) {
        const overlay = document.getElementById('cardDetailModal');
        if (!overlay) return;

        const body = overlay.querySelector('.modal-body');
        const main = overlay.querySelector('.card-detail-main');
        const sidebar = overlay.querySelector('.card-detail-sidebar');
        // Hide the normal card body — replace with the task page.
        if (main) main.style.display = 'none';
        if (sidebar) sidebar.style.display = 'none';

        let page = overlay.querySelector('#taskPage');
        if (!page) {
            page = document.createElement('div');
            page.id = 'taskPage';
            page.className = 'task-page';
            body.appendChild(page);
        }

        page.innerHTML = this.taskPageHtml(task);
        this.bindTaskPageEvents(page, task);
    },

    breadcrumbHtml(task) {
        const crumbs = task.breadcrumb || [];
        if (crumbs.length === 0) return '';
        const parts = crumbs.map((c, i) => {
            const last = i === crumbs.length - 1;
            const label = App.escapeHtml(c.title || 'Untitled');
            if (last) return `<span class="task-crumb current">${label}</span>`;
            if (typeof c.card_id !== 'undefined') {
                return `<a class="task-crumb" data-crumb-card>${label}</a>`;
            }
            return `<a class="task-crumb" data-crumb-task="${c.task_id}">${label}</a>`;
        });
        return `<nav class="task-breadcrumb" aria-label="Breadcrumb">${parts.join('<span class="task-crumb-sep">›</span>')}</nav>`;
    },

    taskPageHtml(task) {
        const me = App.userId;
        const creatorIsMe = parseInt(task.created_by) === me;
        const canEdit = creatorIsMe; // server allows admins too, but UI doesn't need to advertise that
        const myAssignment = (task.assignees || []).find(a => parseInt(a.id) === me);
        const isAssigned = !!myAssignment;
        const accepted = myAssignment && myAssignment.accepted_at;

        const dueText = task.due_date ? this.formatDuePill(task.due_date, task.is_complete) : '<span class="task-empty-inline">No due date</span>';
        const description = task.description && task.description.trim()
            ? App.escapeHtml(task.description)
            : '<span class="task-empty-inline">No description.</span>';

        const assigneeChips = (task.assignees || []).map(a => `
            <span class="task-assignee-chip" data-user-id="${a.id}">
                ${App.avatarHtml(a.display_name, 'sm')}
                ${App.escapeHtml(a.display_name)}
                ${a.accepted_at ? '<span class="task-assignee-accepted" title="Accepted">&#10003;</span>' : ''}
                <span class="remove" data-task-unassign="${a.id}">&times;</span>
            </span>
        `).join('');

        const childrenHtml = (task.children || []).length
            ? task.children.map(c => this.rowHtml(c)).join('')
            : '<div class="task-empty">No sub-tasks.</div>';

        const attsHtml = (task.attachments || []).length
            ? task.attachments.map(a => this.attachmentHtml(a, parseInt(a.user_id) === me)).join('')
            : '<div class="task-empty">No attachments.</div>';

        const commentsHtml = (task.comments || []).length
            ? task.comments.map(c => this.commentHtml(c, parseInt(c.user_id) === me)).join('')
            : '<div class="task-empty">No comments yet.</div>';

        const completeCheck = `
            <label class="task-complete-toggle">
                <input type="checkbox" id="taskCompleteCheck" ${parseInt(task.is_complete) === 1 ? 'checked' : ''}>
                Mark complete
            </label>
        `;

        const acceptArea = isAssigned
            ? (accepted
                ? '<span class="task-accepted-pill">&#10003; You accepted this task</span>'
                : '<button class="btn btn-primary btn-sm" id="taskAcceptBtn">Accept task</button>')
            : '';

        return `
            ${this.breadcrumbHtml(task)}
            <div class="task-page-header">
                <button type="button" class="task-back-btn" id="taskBackBtn" aria-label="Back">&lsaquo; Back</button>
                <input type="text" class="task-title-input" id="taskTitleInput"
                       value="${App.escapeHtml(task.title)}" ${canEdit ? '' : 'readonly'}>
                ${completeCheck}
            </div>

            <div class="task-page-meta-row">
                <div class="task-meta-block">
                    <div class="task-meta-label">Due</div>
                    <div class="task-meta-value">${dueText}</div>
                    <button class="btn btn-sm btn-secondary" id="taskSetDueBtn">Set</button>
                    ${task.due_date ? '<button class="btn btn-sm btn-secondary" id="taskClearDueBtn">Clear</button>' : ''}
                </div>
                <div class="task-meta-block">
                    <div class="task-meta-label">Assignees</div>
                    <div class="task-assignees-list" id="taskAssigneesList">${assigneeChips || '<span class="task-empty-inline">No one assigned.</span>'}</div>
                    <button class="btn btn-sm btn-secondary" id="taskAssignBtn">+ Assign</button>
                </div>
                ${acceptArea ? `<div class="task-meta-block">${acceptArea}</div>` : ''}
            </div>

            <div class="task-page-section">
                <div class="task-section-header"><h4>Description</h4>${canEdit ? '<button class="btn btn-sm btn-secondary" id="taskEditDescBtn">Edit</button>' : ''}</div>
                <div class="task-description" id="taskDescription">${description}</div>
            </div>

            <div class="task-page-section">
                <div class="task-section-header"><h4>Sub-tasks</h4>
                    ${task.parent_task_id ? '' : '<button class="btn btn-sm btn-secondary" id="taskAddChildBtn">+ Add sub-task</button>'}
                </div>
                <div class="task-children-list" id="taskChildrenList">${childrenHtml}</div>
            </div>

            <div class="task-page-section">
                <div class="task-section-header"><h4>Attachments</h4>
                    <button class="btn btn-sm btn-secondary" id="taskAttachBtn">Upload</button>
                </div>
                <div class="task-attachments-list" id="taskAttachmentsList">${attsHtml}</div>
            </div>

            <div class="task-page-section">
                <div class="task-section-header"><h4>Comments</h4></div>
                <div class="task-comment-form">
                    <div style="position:relative;">
                        <textarea id="taskCommentInput" placeholder="Write a comment... Use @ to mention members"></textarea>
                        <div id="taskCommentMentionDropdown" class="mention-dropdown" style="display:none;"></div>
                    </div>
                    <div class="staged-attachments" id="taskCommentStaged"></div>
                    <div class="comment-form-actions">
                        <button class="btn btn-primary btn-sm" id="taskCommentSubmit">Comment</button>
                        <button type="button" class="attach-trigger-btn" id="taskCommentAttachBtn" title="Attach file" aria-label="Attach file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                        <button type="button" class="emoji-trigger-btn" id="taskCommentEmojiBtn" title="Insert emoji" aria-label="Insert emoji"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg></button>
                    </div>
                </div>
                <div class="task-comments-list" id="taskCommentsList">${commentsHtml}</div>
            </div>

            <div class="task-page-section task-page-actions">
                ${canEdit ? '<button class="btn btn-sm btn-danger" id="taskDeleteBtn">Delete task</button>' : ''}
            </div>
        `;
    },

    attachmentHtml(a, isOwn) {
        const isImg = parseInt(a.is_image) === 1;
        const thumb = isImg
            ? `<img class="task-attach-thumb" src="api.php?action=tasks.download_attachment&id=${a.id}&thumb=1" alt="">`
            : `<div class="task-attach-icon">${(a.original_name.split('.').pop() || '').toUpperCase().slice(0,4)}</div>`;
        const delBtn = isOwn
            ? `<button class="task-attach-del" data-task-attach-id="${a.id}" title="Delete">&times;</button>`
            : '';
        return `
            <div class="task-attachment" data-attach-id="${a.id}">
                ${thumb}
                <div class="task-attach-info">
                    <a href="api.php?action=tasks.download_attachment&id=${a.id}" download class="task-attach-name">${App.escapeHtml(a.original_name)}</a>
                    <div class="task-attach-meta">${App.escapeHtml(a.uploader_name || '')}</div>
                </div>
                ${delBtn}
            </div>
        `;
    },

    commentHtml(c, isOwn) {
        const atts = Array.isArray(c.attachments) ? c.attachments : [];
        const attsHtml = atts.length
            ? `<div class="comment-attachments">${atts.map(a => this.attachmentInCommentHtml(a, isOwn)).join('')}</div>`
            : '';
        return `
            <div class="task-comment" data-comment-id="${c.id}">
                ${App.avatarHtml(c.author_name, 'sm')}
                <div class="task-comment-body">
                    <div class="task-comment-header">
                        <span class="task-comment-author">${App.escapeHtml(c.author_name)}</span>
                        <span class="task-comment-time">${App.formatDate(c.created_at)}${parseInt(c.is_edited) === 1 ? ' (edited)' : ''}</span>
                    </div>
                    <div class="task-comment-text">${this.formatCommentBody(c.body)}</div>
                    ${attsHtml}
                    ${isOwn ? `<div class="task-comment-actions">
                        <button class="task-comment-edit" data-comment-id="${c.id}">Edit</button>
                        <button class="task-comment-delete" data-comment-id="${c.id}">Delete</button>
                    </div>` : ''}
                </div>
            </div>
        `;
    },

    /**
     * Format a comment body for display: linkify URLs, then replace any
     * @<member name> token with a styled mention chip. Reuses CardModal's
     * implementation when present so we stay in sync with its rules. The
     * input is already trusted to be plain text — escaping happens inside
     * the formatter.
     */
    formatCommentBody(body) {
        if (typeof CardModal !== 'undefined' && typeof CardModal.formatCommentBody === 'function') {
            // CardModal pulls names from its own boardMembers — same list we got.
            return CardModal.formatCommentBody(body);
        }
        return App.escapeHtml(body || '').replace(/\n/g, '<br>');
    },

    /**
     * Inline-in-comment attachment row. Images are clickable thumbnails
     * (opens the per-task lightbox); non-images are a downloadable file
     * tile. The owner gets a remove (×) button overlaid on hover.
     */
    attachmentInCommentHtml(a, isOwn) {
        const isImg = parseInt(a.is_image) === 1;
        const url = `api.php?action=tasks.download_attachment&id=${a.id}`;
        const delBtn = isOwn
            ? `<button class="comment-attach-delete task-attach-comment-del" data-task-attach-id="${a.id}" title="Remove" aria-label="Remove">&times;</button>`
            : '';
        if (isImg) {
            return `
                <div class="comment-attachment is-image" data-attachment-id="${a.id}">
                    <img class="comment-attach-thumb task-attach-lightbox"
                         src="${url}&thumb=1"
                         data-task-attach-lightbox="${a.id}"
                         alt="${App.escapeHtml(a.original_name)}">
                    ${delBtn}
                </div>
            `;
        }
        const ext = (a.original_name.split('.').pop() || '').toUpperCase().slice(0, 4);
        return `
            <div class="comment-attachment is-file" data-attachment-id="${a.id}">
                <a class="comment-attach-link" href="${url}" download>
                    <span class="comment-attach-icon">${ext}</span>
                    <span class="comment-attach-name">${App.escapeHtml(a.original_name)}</span>
                    <span class="comment-attach-size">${this.formatFileSize(a.file_size)}</span>
                </a>
                ${delBtn}
            </div>
        `;
    },

    formatFileSize(bytes) {
        bytes = parseInt(bytes) || 0;
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    },

    bindTaskPageEvents(page, task) {
        page.querySelector('#taskBackBtn')?.addEventListener('click', () => this.goBack());

        // Breadcrumb navigation.
        page.querySelectorAll('[data-crumb-card]').forEach(el => {
            el.addEventListener('click', () => this.closeTaskPage());
        });
        page.querySelectorAll('[data-crumb-task]').forEach(el => {
            el.addEventListener('click', () => {
                const id = parseInt(el.dataset.crumbTask);
                this.navStack = []; // jumping mid-chain resets the back stack
                this.openTaskPage(id, /*push*/ false);
            });
        });

        // Title edit on blur (creator only — server enforces too).
        const titleEl = page.querySelector('#taskTitleInput');
        if (titleEl && !titleEl.readOnly) {
            titleEl.addEventListener('blur', async () => {
                const v = titleEl.value.trim();
                if (!v || v === task.title) return;
                try {
                    await App.api('tasks.update', { id: task.id, title: v });
                    task.title = v;
                } catch (e) {
                    titleEl.value = task.title;
                    App.showToast(e.message || 'Failed to rename', 'error');
                }
            });
            titleEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
            });
        }

        // Complete toggle.
        page.querySelector('#taskCompleteCheck')?.addEventListener('change', async (e) => {
            try {
                await App.api('tasks.update', { id: task.id, is_complete: e.target.checked });
                task.is_complete = e.target.checked ? 1 : 0;
            } catch (err) {
                e.target.checked = !e.target.checked;
                App.showToast(err.message || 'Failed to update', 'error');
            }
        });

        // Due-date picker.
        page.querySelector('#taskSetDueBtn')?.addEventListener('click', () => this.showTaskDuePicker(task));
        page.querySelector('#taskClearDueBtn')?.addEventListener('click', async () => {
            try {
                await App.api('tasks.update', { id: task.id, due_date: null });
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });

        // Edit description.
        page.querySelector('#taskEditDescBtn')?.addEventListener('click', () => this.editDescriptionInline(task));

        // Sub-tasks add.
        page.querySelector('#taskAddChildBtn')?.addEventListener('click', async () => {
            const t = prompt('Sub-task title:');
            if (!t || !t.trim()) return;
            try {
                await App.api('tasks.create', {
                    card_id: this.cardId,
                    parent_task_id: task.id,
                    title: t.trim(),
                });
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });

        // Sub-task row clicks
        page.querySelectorAll('#taskChildrenList [data-task-id]').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('[data-noopen]')) return;
                const id = parseInt(row.dataset.taskId);
                this.openTaskPage(id, true);
            });
        });
        // Sub-task accept buttons
        page.querySelectorAll('.task-accept-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.acceptTask(parseInt(btn.dataset.taskId));
            });
        });

        // Accept (this task)
        page.querySelector('#taskAcceptBtn')?.addEventListener('click', () => this.acceptTask(task.id));

        // Assignee picker
        page.querySelector('#taskAssignBtn')?.addEventListener('click', () => this.showAssigneePicker(task));
        page.querySelectorAll('[data-task-unassign]').forEach(el => {
            el.addEventListener('click', async (e) => {
                e.stopPropagation();
                const uid = parseInt(el.dataset.taskUnassign);
                try {
                    await App.api('tasks.unassign', { task_id: task.id, user_id: uid });
                    await this.openTaskPage(task.id, false);
                } catch (err) { App.showToast(err.message, 'error'); }
            });
        });

        // Attachments
        page.querySelector('#taskAttachBtn')?.addEventListener('click', () => this.uploadAttachment(task));
        page.querySelectorAll('.task-attach-del').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.dataset.taskAttachId);
                try {
                    await App.api('tasks.delete_attachment', { id });
                    await this.openTaskPage(task.id, false);
                } catch (e) { App.showToast(e.message, 'error'); }
            });
        });

        // ---- Comments composer wiring ----
        const taskKey = String(task.id);
        if (!Array.isArray(this._stagedFiles[taskKey])) this._stagedFiles[taskKey] = [];
        const stagedList = this._stagedFiles[taskKey];

        const composerTextarea = page.querySelector('#taskCommentInput');
        const composerDropdown = page.querySelector('#taskCommentMentionDropdown');
        const composerAttachBtn = page.querySelector('#taskCommentAttachBtn');
        const composerEmojiBtn = page.querySelector('#taskCommentEmojiBtn');
        const composerStagedEl = page.querySelector('#taskCommentStaged');

        // @mention autocomplete — uses CardModal's helper which already knows
        // the boardMembers list (TaskView always runs inside an open card modal).
        if (composerTextarea && composerDropdown && typeof CardModal !== 'undefined') {
            CardModal.initMentionAutocomplete(composerTextarea, composerDropdown);
        }
        // Emoji picker
        if (composerTextarea && composerEmojiBtn && typeof EmojiPicker !== 'undefined') {
            EmojiPicker.attach(composerTextarea, composerEmojiBtn);
        }
        // File staging — CardModal already implements the input + chip-list pattern.
        if (composerAttachBtn && composerStagedEl && typeof CardModal !== 'undefined') {
            CardModal.attachFileStaging(composerAttachBtn, composerStagedEl, stagedList);
            // Re-render any files that were staged before this re-render.
            CardModal.renderStagedFiles(composerStagedEl, stagedList);
        }

        page.querySelector('#taskCommentSubmit')?.addEventListener('click', () => this.submitComment(task));
        composerTextarea?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.ctrlKey) this.submitComment(task);
        });

        page.querySelectorAll('.task-comment-edit').forEach(btn => {
            btn.addEventListener('click', () => this.editComment(task, parseInt(btn.dataset.commentId)));
        });
        page.querySelectorAll('.task-comment-delete').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = parseInt(btn.dataset.commentId);
                if (!confirm('Delete this comment?')) return;
                try {
                    await App.api('tasks.delete_comment', { id });
                    await this.openTaskPage(task.id, false);
                } catch (e) { App.showToast(e.message, 'error'); }
            });
        });

        // Delete an attachment that lives inside a task comment.
        page.querySelectorAll('.task-attach-comment-del').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = parseInt(btn.dataset.taskAttachId);
                try {
                    await App.api('tasks.delete_attachment', { id });
                    await this.openTaskPage(task.id, false);
                } catch (err) { App.showToast(err.message || 'Failed to delete', 'error'); }
            });
        });

        // Click an image thumbnail in a comment → lightbox sweep across all
        // task-level + per-comment images on this task.
        page.querySelectorAll('.task-attach-lightbox').forEach(img => {
            img.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.openLightbox(parseInt(img.dataset.taskAttachLightbox));
            });
        });

        // Delete task
        page.querySelector('#taskDeleteBtn')?.addEventListener('click', async () => {
            if (!confirm('Delete this task (and any sub-tasks)? This cannot be undone.')) return;
            try {
                await App.api('tasks.delete', { id: task.id });
                this.openTaskId = null;
                this.openTask = null;
                this.navStack = [];
                this.closeTaskPage();
                await this.refresh();
            } catch (e) { App.showToast(e.message, 'error'); }
        });
    },

    async acceptTask(taskId) {
        try {
            const res = await App.api('tasks.accept', { task_id: taskId });
            App.showToast(res.already_accepted ? 'Already accepted' : 'Task accepted', 'success');
            if (this.openTaskId === taskId) {
                await this.openTaskPage(taskId, false);
            } else {
                await this.refresh();
            }
        } catch (e) {
            App.showToast(e.message || 'Failed to accept', 'error');
        }
    },

    showAssigneePicker(task) {
        const existingIds = new Set((task.assignees || []).map(a => parseInt(a.id)));
        const candidates = this.boardMembers.filter(m => !existingIds.has(parseInt(m.id)));
        if (!candidates.length) {
            App.showToast('All board members are already assigned.', 'info');
            return;
        }
        const items = candidates.map(m => `
            <div class="member-picker-item" data-user-id="${m.id}">
                ${App.avatarHtml(m.display_name, 'sm')}
                <span>${App.escapeHtml(m.display_name)}</span>
            </div>
        `).join('');
        const modal = App.createModal('taskAssignPickerModal', 'Assign to task', `<div class="label-picker">${items}</div>`);
        modal.querySelectorAll('.member-picker-item').forEach(el => {
            el.addEventListener('click', async () => {
                const uid = parseInt(el.dataset.userId);
                try {
                    await App.api('tasks.assign', { task_id: task.id, user_id: uid });
                    modal.remove();
                    await this.openTaskPage(task.id, false);
                } catch (e) { App.showToast(e.message, 'error'); }
            });
        });
    },

    showTaskDuePicker(task) {
        const cur = task.due_date || '';
        const dateVal = cur.substring(0, 10);
        const timeVal = (cur.length > 10 && cur.substring(11, 16) !== '00:00') ? cur.substring(11, 16) : '';
        const modal = App.createModal('taskDueModal', 'Task due date', `
            <div class="form-group">
                <label>Date</label>
                <input type="date" id="taskDueDate" value="${dateVal}" style="width:100%;padding:8px;border:2px solid var(--color-border);border-radius:4px;">
            </div>
            <div class="form-group">
                <label>Time (optional)</label>
                <input type="time" id="taskDueTime" value="${timeVal}" style="width:100%;padding:8px;border:2px solid var(--color-border);border-radius:4px;">
            </div>
        `, '<button class="btn btn-primary" id="taskDueSave">Save</button>');
        document.getElementById('taskDueSave').addEventListener('click', async () => {
            const dv = document.getElementById('taskDueDate').value;
            const tv = document.getElementById('taskDueTime').value;
            if (!dv) { App.showToast('Pick a date', 'error'); return; }
            const due = tv ? `${dv} ${tv}:00` : dv;
            try {
                await App.api('tasks.update', { id: task.id, due_date: due });
                modal.remove();
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });
    },

    editDescriptionInline(task) {
        const el = document.getElementById('taskDescription');
        if (!el) return;
        const original = task.description || '';
        el.innerHTML = `
            <textarea class="task-desc-edit" id="taskDescEditArea" rows="4">${App.escapeHtml(original)}</textarea>
            <div style="display:flex;gap:8px;margin-top:6px;">
                <button class="btn btn-primary btn-sm" id="taskDescSave">Save</button>
                <button class="btn btn-secondary btn-sm" id="taskDescCancel">Cancel</button>
            </div>
        `;
        document.getElementById('taskDescEditArea').focus();
        document.getElementById('taskDescSave').addEventListener('click', async () => {
            const v = document.getElementById('taskDescEditArea').value;
            try {
                await App.api('tasks.update', { id: task.id, description: v });
                task.description = v;
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });
        document.getElementById('taskDescCancel').addEventListener('click', () => {
            // Restore the previous description display in place.
            this.openTaskPage(task.id, false);
        });
    },

    uploadAttachment(task) {
        const input = document.createElement('input');
        input.type = 'file';
        input.multiple = false;
        input.addEventListener('change', async () => {
            const file = input.files[0];
            if (!file) return;
            const fd = new FormData();
            fd.append('file', file);
            fd.append('task_id', task.id);
            try {
                const res = await App.upload('tasks.upload_attachment', fd);
                if (res.error) throw new Error(res.error);
                App.showToast('File uploaded', 'success');
                await this.openTaskPage(task.id, false);
            } catch (e) {
                App.showToast(e.message || 'Upload failed', 'error');
            }
        });
        input.click();
    },

    async submitComment(task) {
        const ta = document.getElementById('taskCommentInput');
        const body = (ta?.value || '').trim();
        const taskKey = String(task.id);
        const stagedList = this._stagedFiles[taskKey] || [];
        // Allow attach-only? No — mirror CardModal: body required so the
        // comment row has visible content.
        if (!body) return;

        try {
            const res = await App.api('tasks.add_comment', { task_id: task.id, body });
            const newCommentId = res?.comment_id;
            if (ta) ta.value = '';

            // Drain staged files into the new comment.
            if (newCommentId && stagedList.length) {
                const filesToUpload = stagedList.slice();
                stagedList.length = 0;
                const stagedEl = document.getElementById('taskCommentStaged');
                if (stagedEl && typeof CardModal !== 'undefined') {
                    CardModal.renderStagedFiles(stagedEl, stagedList);
                }
                await this.uploadCommentAttachments(filesToUpload, newCommentId);
            }

            await this.openTaskPage(task.id, false);
        } catch (e) {
            App.showToast(e.message || 'Failed to comment', 'error');
        }
    },

    /**
     * Upload each File against an existing task comment. Errors are
     * surfaced per-file so a single bad upload doesn't block the rest.
     * The caller is responsible for refreshing the open task page after.
     */
    async uploadCommentAttachments(files, taskCommentId) {
        if (!files || !files.length) return [];
        const uploaded = [];
        for (const file of files) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('task_id', this.openTaskId);
            fd.append('task_comment_id', taskCommentId);
            try {
                const res = await App.upload('tasks.upload_attachment', fd);
                if (res && res.error) throw new Error(res.error);
                if (res && res.attachment) uploaded.push(res.attachment);
            } catch (e) {
                App.showToast(e.message || 'Upload failed', 'error');
            }
        }
        return uploaded;
    },

    editComment(task, commentId) {
        const wrap = document.querySelector(`.task-comment[data-comment-id="${commentId}"] .task-comment-text`);
        if (!wrap) return;
        const c = (task.comments || []).find(x => parseInt(x.id) === commentId);
        if (!c) return;
        const original = c.body;

        // Reuse the same composer affordances (mention dropdown, emoji button,
        // attach button) inside the edit form so editing has feature parity
        // with composing a new comment.
        const editId = `taskCommentEdit_${commentId}`;
        wrap.innerHTML = `
            <div style="position:relative;">
                <textarea id="${editId}_area" class="task-comment-edit-area"></textarea>
                <div id="${editId}_mention" class="mention-dropdown" style="display:none;"></div>
            </div>
            <div class="comment-form-actions" style="margin-top:6px;">
                <button class="btn btn-primary btn-sm" data-task-comment-save>Save</button>
                <button class="btn btn-secondary btn-sm" data-task-comment-cancel>Cancel</button>
                <button type="button" class="attach-trigger-btn" id="${editId}_attach" title="Attach file" aria-label="Attach file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                <button type="button" class="emoji-trigger-btn" id="${editId}_emoji" title="Insert emoji" aria-label="Insert emoji"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg></button>
            </div>
        `;
        const ta = wrap.querySelector(`#${editId}_area`);
        ta.value = original;
        ta.focus();
        ta.selectionStart = ta.selectionEnd = original.length;

        if (typeof CardModal !== 'undefined') {
            CardModal.initMentionAutocomplete(ta, wrap.querySelector(`#${editId}_mention`));
        }
        if (typeof EmojiPicker !== 'undefined') {
            EmojiPicker.attach(ta, wrap.querySelector(`#${editId}_emoji`));
        }

        // Attach-during-edit uploads immediately against the existing
        // comment id — same UX as the card-comment edit flow.
        wrap.querySelector(`#${editId}_attach`)?.addEventListener('click', () => {
            const input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            input.addEventListener('change', async () => {
                const files = Array.from(input.files);
                if (!files.length) return;
                await this.uploadCommentAttachments(files, commentId);
                await this.openTaskPage(task.id, false);
            });
            input.click();
        });

        wrap.querySelector('[data-task-comment-save]').addEventListener('click', async () => {
            const v = ta.value.trim();
            if (!v) return;
            try {
                await App.api('tasks.update_comment', { id: commentId, body: v });
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });
        wrap.querySelector('[data-task-comment-cancel]').addEventListener('click', () => {
            // Restore the original formatted body in place.
            wrap.innerHTML = this.formatCommentBody(original);
        });
    },

    /**
     * Lightbox sweep across every image attached anywhere on the open task:
     * task-level attachments first, then every image inside a comment's
     * attachments. Mirrors CardModal.openLightbox' structure but with the
     * task download URL.
     */
    openLightbox(attachmentId) {
        if (!this.openTask) return;
        const taskImages = (this.openTask.attachments || []).filter(a => parseInt(a.is_image) === 1);
        const commentImages = (this.openTask.comments || [])
            .flatMap(cm => Array.isArray(cm.attachments) ? cm.attachments : [])
            .filter(a => parseInt(a.is_image) === 1);
        const images = [...taskImages, ...commentImages];

        const target = parseInt(attachmentId);
        let idx = images.findIndex(a => parseInt(a.id) === target);
        if (idx === -1) return;

        const overlay = document.createElement('div');
        overlay.className = 'lightbox-overlay';

        const show = (i) => {
            const img = images[i];
            const src = `api.php?action=tasks.download_attachment&id=${img.id}`;
            overlay.innerHTML = `
                <button class="lightbox-close" aria-label="Close">&times;</button>
                ${images.length > 1 ? '<button class="lightbox-nav lightbox-prev" aria-label="Previous">&lsaquo;</button>' : ''}
                <img class="lightbox-image" src="${src}" alt="${App.escapeHtml(img.original_name)}">
                ${images.length > 1 ? '<button class="lightbox-nav lightbox-next" aria-label="Next">&rsaquo;</button>' : ''}
                ${images.length > 1 ? `<div class="lightbox-counter">${i + 1} / ${images.length}</div>` : ''}
            `;
            overlay.querySelector('.lightbox-close').addEventListener('click', () => overlay.remove());
            overlay.querySelector('.lightbox-prev')?.addEventListener('click', (e) => {
                e.stopPropagation();
                idx = (idx - 1 + images.length) % images.length;
                show(idx);
            });
            overlay.querySelector('.lightbox-next')?.addEventListener('click', (e) => {
                e.stopPropagation();
                idx = (idx + 1) % images.length;
                show(idx);
            });
        };
        show(idx);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.remove();
        });

        const keyHandler = (e) => {
            if (e.key === 'Escape')      { overlay.remove(); document.removeEventListener('keydown', keyHandler); }
            if (e.key === 'ArrowLeft')   { idx = (idx - 1 + images.length) % images.length; show(idx); }
            if (e.key === 'ArrowRight')  { idx = (idx + 1) % images.length; show(idx); }
        };
        document.addEventListener('keydown', keyHandler);
    },
};
