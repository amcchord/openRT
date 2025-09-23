<?php
/**
 * Set automount status
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$enabled = isset($input['enabled']) ? (bool)$input['enabled'] : false;

// Set automount status
$automount_file = '/usr/local/openRT/status/automount_enabled';
$status_dir = dirname($automount_file);

// Create status directory if it doesn't exist
if (!is_dir($status_dir)) {
    mkdir($status_dir, 0755, true);
}

// Write status
file_put_contents($automount_file, $enabled ? '1' : '0');

// If enabling, trigger an immediate check using openRTTUI.pl
if ($enabled) {
    // Run import in background
    exec("sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import > /dev/null 2>&1 &");
}

echo json_encode([
    'success' => true,
    'enabled' => $enabled,
    'message' => $enabled ? 'Automount enabled' : 'Automount disabled'
]);