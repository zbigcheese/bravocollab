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
                    <textarea id="taskCommentInput" placeholder="Write a comment..."></textarea>
                    <button class="btn btn-primary btn-sm" id="taskCommentSubmit">Comment</button>
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
        return `
            <div class="task-comment" data-comment-id="${c.id}">
                ${App.avatarHtml(c.author_name, 'sm')}
                <div class="task-comment-body">
                    <div class="task-comment-header">
                        <span class="task-comment-author">${App.escapeHtml(c.author_name)}</span>
                        <span class="task-comment-time">${App.formatDate(c.created_at)}${parseInt(c.is_edited) === 1 ? ' (edited)' : ''}</span>
                    </div>
                    <div class="task-comment-text">${App.escapeHtml(c.body).replace(/\n/g, '<br>')}</div>
                    ${isOwn ? `<div class="task-comment-actions">
                        <button class="task-comment-edit" data-comment-id="${c.id}">Edit</button>
                        <button class="task-comment-delete" data-comment-id="${c.id}">Delete</button>
                    </div>` : ''}
                </div>
            </div>
        `;
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

        // Comments
        page.querySelector('#taskCommentSubmit')?.addEventListener('click', () => this.submitComment(task));
        page.querySelector('#taskCommentInput')?.addEventListener('keydown', (e) => {
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
        if (!body) return;
        try {
            await App.api('tasks.add_comment', { task_id: task.id, body });
            ta.value = '';
            await this.openTaskPage(task.id, false);
        } catch (e) { App.showToast(e.message, 'error'); }
    },

    editComment(task, commentId) {
        const wrap = document.querySelector(`.task-comment[data-comment-id="${commentId}"] .task-comment-text`);
        if (!wrap) return;
        const c = (task.comments || []).find(x => parseInt(x.id) === commentId);
        if (!c) return;
        const original = c.body;
        wrap.innerHTML = `
            <textarea class="task-comment-edit-area">${App.escapeHtml(original)}</textarea>
            <div style="display:flex;gap:6px;margin-top:4px;">
                <button class="btn btn-primary btn-sm" data-task-comment-save>Save</button>
                <button class="btn btn-secondary btn-sm" data-task-comment-cancel>Cancel</button>
            </div>
        `;
        const ta = wrap.querySelector('textarea');
        ta.focus();
        wrap.querySelector('[data-task-comment-save]').addEventListener('click', async () => {
            const v = ta.value.trim();
            if (!v) return;
            try {
                await App.api('tasks.update_comment', { id: commentId, body: v });
                await this.openTaskPage(task.id, false);
            } catch (e) { App.showToast(e.message, 'error'); }
        });
        wrap.querySelector('[data-task-comment-cancel]').addEventListener('click', () => {
            wrap.textContent = original;
        });
    },
};
