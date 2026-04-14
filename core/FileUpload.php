<?php

class FileUpload
{
    public static function handle(array $file, int $maxSize = null): array
    {
        $config = require __DIR__ . '/../config/config.php';
        $maxSize = $maxSize ?? $config['max_upload_size'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($file['error']));
        }

        if ($file['size'] > $maxSize) {
            throw new RuntimeException('File exceeds maximum size of ' . self::formatBytes($maxSize));
        }

        // Validate MIME type using finfo (not client-reported type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('File type not allowed: ' . $mimeType);
        }

        $extension = self::getExtension($file['name'], $mimeType);
        $storedName = uniqid('', true) . '_' . bin2hex(random_bytes(8)) . '.' . $extension;

        $uploadDir = $config['upload_dir'] . '/attachments';
        $destPath = $uploadDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Failed to save uploaded file');
        }

        $isImage = in_array($mimeType, IMAGE_MIME_TYPES, true);
        $thumbnailPath = null;

        if ($isImage) {
            $thumbDir = $config['upload_dir'] . '/thumbnails';
            $thumbName = 'thumb_' . $storedName;
            $thumbDest = $thumbDir . '/' . $thumbName;

            if (self::generateThumbnail($destPath, $thumbDest)) {
                $thumbnailPath = $thumbName;
            }
        }

        return [
            'original_name'  => $file['name'],
            'stored_name'    => $storedName,
            'file_size'      => $file['size'],
            'mime_type'      => $mimeType,
            'is_image'       => $isImage ? 1 : 0,
            'thumbnail_path' => $thumbnailPath,
        ];
    }

    public static function generateThumbnail(string $source, string $dest, int $maxW = 300, int $maxH = 300): bool
    {
        $info = @getimagesize($source);
        if (!$info) return false;

        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg': $img = imagecreatefromjpeg($source); break;
            case 'image/png':  $img = imagecreatefrompng($source); break;
            case 'image/gif':  $img = imagecreatefromgif($source); break;
            case 'image/webp': $img = imagecreatefromwebp($source); break;
            default: return false;
        }

        if (!$img) return false;

        $origW = imagesx($img);
        $origH = imagesy($img);
        $ratio = min($maxW / $origW, $maxH / $origH, 1.0);
        $newW = max(1, (int) ($origW * $ratio));
        $newH = max(1, (int) ($origH * $ratio));

        $thumb = imagecreatetruecolor($newW, $newH);

        // Preserve transparency
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagecolortransparent($thumb, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        $result = imagejpeg($thumb, $dest, 85);

        imagedestroy($img);
        imagedestroy($thumb);

        return $result;
    }

    public static function deleteFile(string $storedName): void
    {
        $config = require __DIR__ . '/../config/config.php';
        $path = $config['upload_dir'] . '/attachments/' . $storedName;
        if (file_exists($path)) {
            unlink($path);
        }
        // Also try to delete thumbnail
        $thumbPath = $config['upload_dir'] . '/thumbnails/thumb_' . $storedName;
        if (file_exists($thumbPath)) {
            unlink($thumbPath);
        }
    }

    private static function getExtension(string $filename, string $mimeType): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Verify extension matches MIME type for images
        $mimeExtMap = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png'  => ['png'],
            'image/gif'  => ['gif'],
            'image/webp' => ['webp'],
        ];

        if (isset($mimeExtMap[$mimeType])) {
            if (!in_array($ext, $mimeExtMap[$mimeType], true)) {
                return $mimeExtMap[$mimeType][0];
            }
        }

        // Block dangerous extensions
        $blocked = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py'];
        if (in_array($ext, $blocked, true)) {
            return 'txt';
        }

        return $ext ?: 'bin';
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension',
            default               => 'Unknown upload error',
        };
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' bytes';
    }
}
