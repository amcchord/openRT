<?php
// Get parameters
$agent_id = isset($_GET['agent']) ? $_GET['agent'] : '';
$path = isset($_GET['path']) ? $_GET['path'] : '';

if (!$agent_id || !$path) {
    die("Missing required parameters");
}

// This prevents command injection and directory traversal attacks.
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $agent_id)) {
    die("Invalid agent id format");
}

// Construct full path
$base_path = "/rtMount/$agent_id";
$full_path = realpath($base_path . '/' . $path);

// Security checks
if (!str_starts_with(realpath($full_path), realpath($base_path))) {
    die("Invalid path");
}

if (!file_exists($full_path) || !is_file($full_path)) {
    die("File not found");
}

// Get file information
$file_size = filesize($full_path);
$file_name = basename($full_path);
$file_type = mime_content_type($full_path);

// Set headers for download
header('Content-Type: ' . $file_type);
header('Content-Length: ' . $file_size);
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Output file in chunks to handle large files
$handle = fopen($full_path, 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
