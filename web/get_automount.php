<?php
/**
 * Get automount status
 */

header('Content-Type: application/json');

// Check if automount service/flag is enabled
$automount_file = '/usr/local/openRT/status/automount_enabled';
$enabled = file_exists($automount_file) && trim(file_get_contents($automount_file)) === '1';

echo json_encode([
    'enabled' => $enabled,
    'timestamp' => time()
]);