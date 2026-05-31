<?php
/*
 * In the wiki, there cann be uploaded files. These files are stored in a private folder (i.e., not in /public).
 * This file is used to retrieve the file. It checks whether the user is allowed to download the file or not,
 * and eventually transmits the file.
 */

session_start();

require_once __DIR__ . '/../globals.inc.php';
require_once __DIR__ . '/../auth/auth.inc.php';
require_once __DIR__ . '/../wiki/Wiki.inc.php';
require_once __DIR__ . '/../wiki/WikiFiles.inc.php';

// Files are identified by a ID that is stored in the wiki database.
$storedName = $_GET['id'] ?? '';
if (!\wiki\is_valid_stored_name($storedName)) {
    http_response_code(400);
    exit('400 Bad Request');
}

$db = \wiki\WikiDatabase::getInstance();
if ($db === NULL) {
    http_response_code(503);
    exit('503 Wiki not enabled');
}

$fileRow = $db->getFile($storedName);
if ($fileRow === NULL) {
    http_response_code(404);
    exit('404 Not Found');
}

// Files inherit the visibility of their page.
$page = $db->getPage($fileRow['page_url']);
if ($page === NULL || !\wiki\user_can_read($page['visibility'])) {
    http_response_code(403);
    exit('403 Forbidden');
}

$filePath = \wiki\get_file_path($storedName);
if ( ! is_file($filePath) ) {
    http_response_code(404);
    exit('404 File not found on disk');
}

$mimeType = $fileRow['mime_type'];
$filename = $fileRow['filename'];

header('Content-Type: ' . $mimeType);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($filePath));
// Files are immutable once uploaded; safe to cache privately.
header('Cache-Control: private, max-age=3600');
if ( in_array($mimeType, \wiki\INLINE_MIME_TYPES, TRUE) ) {
    header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
}

readfile($filePath);
