<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('fwerkor_mediaupload_max_mb');

$uploads=wp_upload_dir();
if (empty($uploads['error'])) {
    $root=trailingslashit($uploads['basedir']).'.fwerkor-mediaupload';
    if (is_dir($root)) {
        $items=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach($items as $item) {
            $item->isDir()?@rmdir($item->getPathname()):@unlink($item->getPathname());
        }
        @rmdir($root);
    }
}
