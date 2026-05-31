<?php

namespace wiki;

require_once __DIR__ . '/WikiDatabase.inc.php';

const UPLOAD_DIR_NAME = 'external-files';
const MAX_FILE_SIZE   = 10 * 1024 * 1024; // 10 MB

// Only these MIME types are served inline (safe raster images + PDF).
// SVG is intentionally excluded — an SVG with embedded JS would execute in the
// browser on the same origin and cause XSS.
const INLINE_MIME_TYPES = [
    'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif',
    'application/pdf',
];

/**
 * Returns the absolute path to the upload directory, creating it on first call.
 * The directory lives outside public/ and gets a deny-all .htaccess as an extra
 * safeguard in case it is ever moved inside a web root by mistake.
 *
 * @return string Absolute filesystem path to the upload directory.
 * @throws \RuntimeException If the directory cannot be created.
 */
function get_upload_dir(): string {
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . UPLOAD_DIR_NAME;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750, TRUE)) {
            throw new \RuntimeException("Failed to create upload directory: $dir");
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
    }
    return $dir;
}

/**
 * Builds the absolute filesystem path for a stored file.
 *
 * @param string $storedName 32-char lowercase hex identifier of the file.
 * @return string Absolute path to the file on disk.
 */
function get_file_path(string $storedName): string {
    return get_upload_dir() . DIRECTORY_SEPARATOR . $storedName;
}

/**
 * Validates that a stored_name is a 32-char lowercase hex string (bin2hex of 16 random bytes).
 *
 * @param string $name Value to validate.
 * @return bool TRUE if valid, FALSE otherwise.
 */
function is_valid_stored_name(string $name): bool {
    return (bool)preg_match('/^[0-9a-f]{32}$/', $name);
}

/**
 * Validates, moves, and registers a single uploaded file.
 * On success, the file is written to disk and recorded in the DB.
 *
 * @param array  $file    Single entry from $_FILES (e.g. $_FILES['file']).
 * @param string $pageUrl Wiki page URL the file is attached to.
 * @param string $user    Username of the uploader.
 * @return array Result array with 'ok' (bool) and 'error' (string); 'error' is empty on success.
 */
function handle_upload(array $file, string $pageUrl, string $user): array {
    if (!isset($file['error'])) {
        return ['ok' => FALSE, 'error' => 'No file provided.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => FALSE, 'error' => upload_error_message($file['error'])];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['ok' => FALSE, 'error' => 'File exceeds maximum allowed size (10 MB).'];
    }

    $originalName = basename($file['name']);
    if ($originalName === '' || $originalName === '.') {
        return ['ok' => FALSE, 'error' => 'Empty filename.'];
    }

    // Detect MIME type server-side; ignore the browser-supplied value.
    $finfo    = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if ($mimeType === FALSE || $mimeType === '') {
        $mimeType = 'application/octet-stream';
    }

    // Collisions are astronomically unlikely (128-bit entropy), but the UNIQUE constraint
    // would cause an unhandled PDOException if one occurred — retry just in case.
    do {
        $storedName = bin2hex(random_bytes(16));
    } while (file_exists(get_upload_dir() . DIRECTORY_SEPARATOR . $storedName));
    $dest = get_upload_dir() . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => FALSE, 'error' => 'Failed to store file on disk.'];
    }

    WikiDatabase::getInstance()->saveFile($pageUrl, $originalName, $storedName, $mimeType, (int)$file['size'], $user);

    return ['ok' => TRUE, 'error' => ''];
}

/**
 * Maps a PHP UPLOAD_ERR_* code to a human-readable error message.
 *
 * @param int $code One of the PHP UPLOAD_ERR_* constants.
 * @return string Human-readable error description.
 */
function upload_error_message(int $code): string {
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form MAX_FILE_SIZE limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
    ];
    return $map[$code] ?? "Upload error (code $code).";
}

/**
 * Formats a byte count as a human-readable size string.
 *
 * @param int $bytes File size in bytes.
 * @return string Formatted size string (e.g. '2.3 MB', '512 KB', '800 B').
 */
function format_file_size(int $bytes): string {
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    if ($bytes >= 1024)        return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
