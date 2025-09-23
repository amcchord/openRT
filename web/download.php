<?php
/**
 * OpenRT File Download Handler
 * Handles secure file downloads from mounted snapshots
 */

// Get file path parameter
$file_path = $_GET['file'] ?? '';

if (!$file_path) {
    http_response_code(400);
    die("No file specified");
}

// Security: Only allow downloads from /rtMount
$real_path = realpath($file_path);
if (!$real_path || !str_starts_with($real_path, '/rtMount')) {
    http_response_code(403);
    die("Access denied - file must be within /rtMount");
}

// Check if file exists and is readable
if (!file_exists($real_path) || !is_file($real_path) || !is_readable($real_path)) {
    http_response_code(404);
    die("File not found or not accessible");
}

// Get file information
$file_size = filesize($real_path);
$file_name = basename($real_path);
$file_type = mime_content_type($real_path) ?: 'application/octet-stream';

// Set headers for download
header('Content-Type: ' . $file_type);
header('Content-Length: ' . $file_size);
header('Content-Disposition: attachment; filename="' . addslashes($file_name) . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Handle large files efficiently
if ($file_size > 10 * 1024 * 1024) { // Files larger than 10MB
    // Use readfile for large files
    readfile($real_path);
} else {
    // Use chunked reading for smaller files
    $handle = fopen($real_path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
        fclose($handle);
    }
} 