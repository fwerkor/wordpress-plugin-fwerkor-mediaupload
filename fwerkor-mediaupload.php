<?php
/**
 * Plugin Name: FWERKOR Media Upload
 * Plugin URI: https://github.com/fwerkor/wordpress-plugin-fwerkor-mediaupload
 * Description: Reliable chunked large-file uploads for the WordPress media library.
 * Version: 1.0.1
 * Author: FWERKOR
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Media_Upload {
    private const OPTION_MAX_MB = 'fwerkor_mediaupload_max_mb';
    private const REST_NS = 'fwerkor-mediaupload/v1';
    private const VERSION = '1.0.1';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('rest_api_init', array($this, 'routes'));
    }

    public static function activate(): void {
        add_option(self::OPTION_MAX_MB, 2048, '', false);
    }

    public function menu(): void {
        add_media_page(
            'FWERKOR Media Upload',
            'Large Upload',
            'upload_files',
            'fwerkor-mediaupload',
            array($this, 'render')
        );
    }

    public function assets(string $hook): void {
        if ('media_page_fwerkor-mediaupload' !== $hook) {
            return;
        }

        wp_enqueue_script(
            'fwerkor-mediaupload',
            plugins_url('assets/uploader.js', __FILE__),
            array(),
            self::VERSION,
            true
        );

        wp_add_inline_script(
            'fwerkor-mediaupload',
            'window.FWERKOR_MEDIAUPLOAD=' . wp_json_encode(array(
                'endpoint' => esc_url_raw(rest_url(self::REST_NS . '/chunk')),
                'nonce' => wp_create_nonce('wp_rest'),
                'chunkSize' => 512 * 1024,
                'maxBytes' => $this->max_bytes(),
            )) . ';',
            'before'
        );
    }

    public function routes(): void {
        register_rest_route(
            self::REST_NS,
            '/chunk',
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'chunk'),
                'permission_callback' => static function (): bool {
                    return current_user_can('upload_files');
                },
            )
        );
    }

    public function render(): void {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('You are not allowed to upload files.', 'fwerkor-mediaupload'));
        }

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['fwerkor_mediaupload_settings'])) {
            check_admin_referer('fwerkor_mediaupload_settings');
            if (current_user_can('manage_options')) {
                $max = isset($_POST['max_mb']) ? absint($_POST['max_mb']) : 2048;
                update_option(self::OPTION_MAX_MB, max(16, min(10240, $max)), false);
            }
            wp_safe_redirect(admin_url('upload.php?page=fwerkor-mediaupload&updated=1'));
            exit;
        }

        $standard = wp_max_upload_size();
        $max_mb = (int) get_option(self::OPTION_MAX_MB, 2048);
        ?>
        <div class="wrap">
            <h1>FWERKOR Media Upload</h1>
            <p>Chunked uploads avoid the single-request PHP upload limit and finish as normal WordPress media attachments.</p>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
            <?php endif; ?>

            <div style="max-width:880px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px;margin:20px 0">
                <p style="margin-top:0"><strong>Chunked limit:</strong> <?php echo esc_html(size_format($this->max_bytes())); ?></p>
                <p><strong>Standard WordPress upload limit:</strong> <?php echo esc_html(size_format($standard)); ?></p>
                <p style="color:#646970">Files are sent in 512 KiB chunks and assembled server-side, so the chunked uploader can handle files larger than the normal PHP POST limit.</p>

                <div id="fwerkor-mediaupload-drop" style="border:2px dashed #c3c4c7;border-radius:10px;padding:38px;text-align:center;margin-top:22px">
                    <input id="fwerkor-mediaupload-file" type="file" multiple>
                    <p>Choose files or drag them here.</p>
                </div>
                <div id="fwerkor-mediaupload-list" style="margin-top:18px"></div>
            </div>

            <?php if (current_user_can('manage_options')) : ?>
                <form method="post" style="max-width:880px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:22px">
                    <?php wp_nonce_field('fwerkor_mediaupload_settings'); ?>
                    <input type="hidden" name="fwerkor_mediaupload_settings" value="1">
                    <h2 style="margin-top:0">Settings</h2>
                    <label>
                        <strong>Maximum file size (MiB)</strong><br>
                        <input type="number" name="max_mb" min="16" max="10240" value="<?php echo esc_attr((string) $max_mb); ?>" style="margin-top:8px;width:140px">
                    </label>
                    <p class="description">Applies to the chunked uploader only. Allowed file types still follow the WordPress media policy.</p>
                    <p><button type="submit" class="button button-primary">Save settings</button></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function chunk(WP_REST_Request $request) {
        $upload_id = strtolower(sanitize_text_field((string) $request->get_param('upload_id')));
        $index = absint($request->get_param('index'));
        $total = absint($request->get_param('total'));
        $filename = sanitize_file_name((string) $request->get_param('filename'));
        $filesize = (int) $request->get_param('filesize');

        if (!preg_match('/^[a-f0-9]{32}$/', $upload_id)) {
            return new WP_Error('invalid_upload_id', 'Invalid upload ID.', array('status' => 400));
        }
        if ($total < 1 || $total > 25000 || $index >= $total || '' === $filename) {
            return new WP_Error('invalid_chunk', 'Invalid chunk metadata.', array('status' => 400));
        }
        if ($filesize < 1 || $filesize > $this->max_bytes()) {
            return new WP_Error('file_too_large', 'File exceeds the configured upload limit.', array('status' => 413));
        }
        if (empty($_FILES['chunk']['tmp_name']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
            return new WP_Error('missing_chunk', 'Chunk payload missing.', array('status' => 400));
        }

        $chunk_size = (int) ($_FILES['chunk']['size'] ?? 0);
        if ($chunk_size < 1 || $chunk_size > 1024 * 1024) {
            return new WP_Error('chunk_too_large', 'Chunk exceeds 1 MiB.', array('status' => 413));
        }

        $this->cleanup_stale();

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('upload_dir', $uploads['error'], array('status' => 500));
        }

        $user_id = get_current_user_id();
        $base = trailingslashit($uploads['basedir']) . '.fwerkor-mediaupload/' . $user_id . '/' . $upload_id;
        if (!wp_mkdir_p($base)) {
            return new WP_Error('temp_dir', 'Could not create upload staging directory.', array('status' => 500));
        }

        $meta_file = $base . '/meta.json';
        $expected = array(
            'filename' => $filename,
            'filesize' => $filesize,
            'total' => $total,
        );

        if (file_exists($meta_file)) {
            $saved = json_decode((string) file_get_contents($meta_file), true);
            if (!is_array($saved) || $saved !== $expected) {
                return new WP_Error('metadata_mismatch', 'Upload metadata changed.', array('status' => 409));
            }
        } else {
            file_put_contents($meta_file, wp_json_encode($expected), LOCK_EX);
        }

        $chunk_path = $base . '/chunk-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);
        if (!move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path)) {
            return new WP_Error('chunk_write', 'Could not store upload chunk.', array('status' => 500));
        }

        if ($index + 1 < $total) {
            return new WP_REST_Response(array('ok' => true, 'complete' => false), 202);
        }

        for ($i = 0; $i < $total; $i++) {
            $path = $base . '/chunk-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (!is_file($path)) {
                return new WP_Error('missing_piece', 'One or more upload chunks are missing.', array('status' => 409));
            }
        }

        $final_tmp = $base . '/assembled';
        $out = fopen($final_tmp, 'wb');
        if (false === $out) {
            return new WP_Error('assemble_open', 'Could not assemble upload.', array('status' => 500));
        }

        for ($i = 0; $i < $total; $i++) {
            $path = $base . '/chunk-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            $in = fopen($path, 'rb');
            if (false === $in) {
                fclose($out);
                return new WP_Error('assemble_read', 'Could not read an upload chunk.', array('status' => 500));
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        if ((int) filesize($final_tmp) !== $filesize) {
            return new WP_Error('size_mismatch', 'Assembled file size does not match the original.', array('status' => 409));
        }

        $checked = wp_check_filetype_and_ext($final_tmp, $filename);
        $type = (string) ($checked['type'] ?? '');
        $proper = (string) ($checked['proper_filename'] ?? '');
        if ('' === $type || !in_array($type, get_allowed_mime_types(), true)) {
            return new WP_Error('file_type', 'This file type is not allowed by WordPress.', array('status' => 415));
        }
        if ('' !== $proper) {
            $filename = sanitize_file_name($proper);
        }

        wp_mkdir_p($uploads['path']);
        $filename = wp_unique_filename($uploads['path'], $filename);
        $destination = trailingslashit($uploads['path']) . $filename;

        if (!rename($final_tmp, $destination)) {
            return new WP_Error('finalize', 'Could not move the assembled file into the media library.', array('status' => 500));
        }

        @chmod($destination, 0644);

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $type,
                'post_title' => sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME)),
                'post_status' => 'inherit',
            ),
            $destination
        );

        if (is_wp_error($attachment_id)) {
            @unlink($destination);
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $destination);
        if (!is_wp_error($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        $this->remove_tree($base);

        return new WP_REST_Response(
            array(
                'ok' => true,
                'complete' => true,
                'attachmentId' => (int) $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
                'editUrl' => get_edit_post_link($attachment_id, 'raw'),
            ),
            201
        );
    }

    private function max_bytes(): int {
        return max(16, min(10240, (int) get_option(self::OPTION_MAX_MB, 2048))) * 1024 * 1024;
    }

    private function cleanup_stale(): void {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return;
        }

        $root = trailingslashit($uploads['basedir']) . '.fwerkor-mediaupload';
        if (!is_dir($root)) {
            return;
        }

        $cutoff = time() - DAY_IN_SECONDS;
        foreach (glob($root . '/*/*') ?: array() as $path) {
            if (is_dir($path) && filemtime($path) < $cutoff) {
                $this->remove_tree($path);
            }
        }
    }

    private function remove_tree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}

register_activation_hook(__FILE__, array('FWERKOR_Media_Upload', 'activate'));
new FWERKOR_Media_Upload();
