# FWERKOR Media Upload

Reliable chunked large-file uploads for the WordPress media library.

## Features

- Dedicated Media > Large Upload page
- 512 KiB chunks, avoiding the normal single-request PHP upload limit
- Configurable maximum file size (2 GiB by default)
- WordPress capability, nonce, MIME and filename validation
- Normal WordPress attachments and generated metadata
- Drag-and-drop, multi-file progress, and stale upload cleanup
- No external service and no site-specific hostname

The standard WordPress uploader is left unchanged. Large files should use the plugin's chunked upload page.

## Requirements

WordPress 6.0+ and PHP 8.0+.

## License

GPL-2.0-or-later.
