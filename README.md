# FWERKOR MediaUpload

A small WordPress plugin for managing and diagnosing media upload limits.

It does not pretend WordPress can bypass PHP. The effective limit is always the smaller of the configured WordPress limit and the PHP upload/post limits.

## Features
- Configurable Media upload limit
- Shows the PHP ceiling and effective limit
- Rejects oversized uploads with a clear error
- No telemetry
- No site-specific hostname or configuration

Production servers should set upload_max_filesize and post_max_size to the intended ceiling.
