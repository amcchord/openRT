<?php
/**
 * Unmount all agents using openRTTUI.pl cleanup
 */

header('Content-Type: application/json');

// Use openRTTUI.pl cleanup command
$cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive cleanup 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

$success = $return_var === 0;

// Parse output to find what was cleaned
$cleaned = [];
foreach ($output as $line) {
    if (preg_match('/(unmounted|removed|cleaned).*?:\s*(.+)/i', $line, $matches)) {
        $cleaned[] = trim($matches[2]);
    }
}

echo json_encode([
    'success' => $success,
    'status' => $success ? 'success' : 'error',
    'cleaned' => $cleaned,
    'output' => implode("\n", $output),
    'message' => $success ? 'All mounts cleaned up successfully' : 'Failed to clean up mounts'
]);