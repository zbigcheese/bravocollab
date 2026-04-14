<?php

// User roles
define('ROLE_ADMIN', 'admin');
define('ROLE_MEMBER', 'member');

// Board member roles
define('BOARD_ROLE_OWNER', 'owner');
define('BOARD_ROLE_MEMBER', 'member');

// Notification types
define('NOTIF_CARD_ASSIGNED', 'card_assigned');
define('NOTIF_CARD_UNASSIGNED', 'card_unassigned');
define('NOTIF_COMMENT_ADDED', 'comment_added');
define('NOTIF_COMMENT_MENTION', 'comment_mention');
define('NOTIF_DUE_SOON', 'due_soon');
define('NOTIF_DUE_OVERDUE', 'due_overdue');
define('NOTIF_BOARD_INVITED', 'board_invited');

// SSE event types
define('SSE_CARD_CREATED', 'card_created');
define('SSE_CARD_UPDATED', 'card_updated');
define('SSE_CARD_MOVED', 'card_moved');
define('SSE_CARD_ARCHIVED', 'card_archived');
define('SSE_LIST_CREATED', 'list_created');
define('SSE_LIST_UPDATED', 'list_updated');
define('SSE_LIST_REORDERED', 'list_reordered');
define('SSE_LIST_ARCHIVED', 'list_archived');
define('SSE_COMMENT_ADDED', 'comment_added');
define('SSE_COMMENT_UPDATED', 'comment_updated');
define('SSE_COMMENT_DELETED', 'comment_deleted');
define('SSE_LABEL_CHANGED', 'label_changed');
define('SSE_CHECKLIST_CHANGED', 'checklist_changed');
define('SSE_ATTACHMENT_ADDED', 'attachment_added');
define('SSE_ATTACHMENT_DELETED', 'attachment_deleted');
define('SSE_NOTIFICATION', 'notification');

// Default label colors
define('DEFAULT_LABEL_COLORS', [
    '#61BD4F', // green
    '#F2D600', // yellow
    '#FF9F1A', // orange
    '#EB5A46', // red
    '#C377E0', // purple
    '#0079BF', // blue
    '#00C2E0', // sky
    '#51E898', // lime
    '#FF78CB', // pink
    '#344563', // dark
]);

// Position gap for ordering
define('POSITION_GAP', 65536);

// Allowed upload MIME types
define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'text/plain', 'text/csv',
    'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
]);

// Image MIME types (subset of allowed)
define('IMAGE_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
]);
