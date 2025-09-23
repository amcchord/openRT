<?php
/**
 * Check if an agent is mounted using openRTTUI.pl status
 */

header('Content-Type: application/json');

if (!isset($_GET['agent_id'])) {
    echo json_encode(['success' => false, 'error' => 'No agent ID provided']);
    exit;
}

$agent_id = $_GET['agent_id'];

// Use openRTTUI.pl status to check overall status
$cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive status 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

// Also check if there's a mount point for this specific agent
$mount_check = "mount | grep -q " . escapeshellarg("/mnt/openRT/{$agent_id}");
$is_mounted = (system($mount_check . " 2>/dev/null") === false) ? false : true;

// Alternative check - see if directory exists and has content
if (!$is_mounted) {
    $mount_dir = "/mnt/openRT/{$agent_id}";
    if (is_dir($mount_dir)) {
        // Check if directory has any content (mounted filesystems will have content)
        $files = scandir($mount_dir);
        $is_mounted = count($files) > 2; // More than just . and ..
    }
}

echo json_encode([
    'success' => true,
    'mounted' => $is_mounted,
    'agent_id' => $agent_id,
    'status_output' => implode("\n", $output)
]);