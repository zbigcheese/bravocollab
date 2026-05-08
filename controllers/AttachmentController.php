<?php

require_once __DIR__ . '/../core/FileUpload.php';

class AttachmentController extends Controller
{
    public function upload(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $cardId = (int) ($_POST['card_id'] ?? 0);
        if (!$cardId) {
            $this->json(['error' => 'Card ID required'], 400);
            return;
        }

        $boardId = $this->getBoardIdForCard($cardId);
        if (!$boardId) {
            $this->json(['error' => 'Card not found'], 404);
            return;
        }
        $this->requireBoardAccess($boardId);

        $commentId = isset($_POST['comment_id']) && $_POST['comment_id'] !== ''
            ? (int) $_POST['comment_id']
            : null;
        if ($commentId) {
            $db0 = Database::get();
            $cs = $db0->prepare('SELECT id FROM comments WHERE id = :id AND card_id = :cid LIMIT 1');
            $cs->execute(['id' => $commentId, 'cid' => $cardId]);
            if (!$cs->fetch()) {
                $this->json(['error' => 'Comment not found on this card'], 400);
                return;
            }
        }

        if (empty($_FILES['file'])) {
            $this->json(['error' => 'No file uploaded'], 400);
            return;
        }

        try {
            $fileData = FileUpload::handle($_FILES['file']);
        } catch (RuntimeException $e) {
            $this->json(['error' => $e->getMessage()], 400);
            return;
        }

        $db = Database::get();
        $db->prepare(
            'INSERT INTO attachments (card_id, comment_id, user_id, original_name, stored_name, file_size, mime_type, is_image, thumbnail_path)
             VALUES (:card_id, :comment_id, :user_id, :original_name, :stored_name, :file_size, :mime_type, :is_image, :thumbnail_path)'
        )->execute([
            'card_id'        => $cardId,
            'comment_id'     => $commentId,
            'user_id'        => Auth::userId(),
            'original_name'  => $fileData['original_name'],
            'stored_name'    => $fileData['stored_name'],
            'file_size'      => $fileData['file_size'],
            'mime_type'      => $fileData['mime_type'],
            'is_image'       => $fileData['is_image'],
            'thumbnail_path' => $fileData['thumbnail_path'],
        ]);

        $attachmentId = (int) $db->lastInsertId();

        $this->publishSSE($boardId, SSE_ATTACHMENT_ADDED, [
            'card_id'       => $cardId,
            'comment_id'    => $commentId,
            'attachment_id' => $attachmentId,
        ]);

        $this->logActivity($boardId, $cardId, 'attachment_added', [
            'filename'   => $fileData['original_name'],
            'comment_id' => $commentId,
        ]);

        $this->json([
            'success'    => true,
            'attachment' => [
                'id'             => $attachmentId,
                'comment_id'     => $commentId,
                'original_name'  => $fileData['original_name'],
                'mime_type'      => $fileData['mime_type'],
                'is_image'       => $fileData['is_image'],
                'file_size'      => $fileData['file_size'],
                'thumbnail_path' => $fileData['thumbnail_path'],
            ],
        ]);
    }

    public function download(): void
    {
        $this->requireAuth();

        $id = (int) ($_GET['id'] ?? 0);
        $thumb = isset($_GET['thumb']);

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $boardId = $this->getBoardIdForCard($attachment['card_id']);
        $this->requireBoardAccess($boardId);

        $config = require __DIR__ . '/../config/config.php';

        if ($thumb && $attachment['thumbnail_path']) {
            $filePath = $config['upload_dir'] . '/thumbnails/' . $attachment['thumbnail_path'];
            $mimeType = 'image/jpeg';
            $fileName = 'thumb_' . $attachment['original_name'];
        } else {
            $filePath = $config['upload_dir'] . '/attachments/' . $attachment['stored_name'];
            $mimeType = $attachment['mime_type'];
            $fileName = $attachment['original_name'];
        }

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found on disk';
            return;
        }

        // Cache headers for images
        if ($attachment['is_image']) {
            header('Cache-Control: public, max-age=86400');
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));

        // Inline for images, download for others
        if ($attachment['is_image'] || $thumb) {
            header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
        }

        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    public function delete(): void
    {
        $this->requireAuth();
        $this->requirePost();
        $this->validateCSRF();

        $data = $this->getJSON();
        $id = (int) ($data['id'] ?? 0);

        $db = Database::get();
        $stmt = $db->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $attachment = $stmt->fetch();

        if (!$attachment) {
            $this->json(['error' => 'Attachment not found'], 404);
            return;
        }

        $boardId = $this->getBoardIdForCard($attachment['card_id']);
        $this->requireBoardAccess($boardId);

        // Delete file from disk
        FileUpload::deleteFile($attachment['stored_name']);

        // Delete from DB
        $db->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);

        $this->publishSSE($boardId, SSE_ATTACHMENT_DELETED, [
            'card_id'       => $attachment['card_id'],
            'attachment_id' => $id,
        ]);

        $this->json(['success' => true]);
    }
}
