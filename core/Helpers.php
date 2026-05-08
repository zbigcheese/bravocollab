<?php
/**
 * Project-wide helper functions. Loaded from index.php so every PHP-
 * rendered view can use them.
 */

if (!function_exists('asset_url')) {
    /**
     * Append a cache-busting query string (the file's mtime) to a static
     * asset path. Any deploy that changes the file changes the URL, so
     * browsers and PWA caches automatically refetch instead of serving
     * a stale copy. Falls back to the bare path if the file can't be
     * resolved on disk so a missing asset never throws.
     *
     *   <link rel="stylesheet" href="<?php echo asset_url('public/css/app.css'); ?>">
     */
    function asset_url(string $relPath): string {
        static $rootDir;
        if ($rootDir === null) {
            $rootDir = dirname(__DIR__);
        }
        $clean = ltrim($relPath, '/');
        $full  = $rootDir . '/' . $clean;
        if (is_file($full)) {
            return $relPath . '?v=' . filemtime($full);
        }
        return $relPath;
    }
}
