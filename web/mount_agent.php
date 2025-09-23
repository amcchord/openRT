<?php
/**
 * Mount agent snapshots using openRTTUI.pl
 */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['agent_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$agent_id = $_POST['agent_id'];

// Use openRTTUI.pl to mount the agent
$cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive mount " . escapeshellarg($agent_id) . " 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

$success = $return_var === 0;

// Parse output to find mount points
$mounts = [];
if ($success) {
    foreach ($output as $line) {
        if (preg_match('/mounted at:\s*(.+)/i', $line, $matches)) {
            $mounts[] = trim($matches[1]);
        }
    }
}

echo json_encode([
    'success' => $success,
    'status' => $success ? 'success' : 'error',
    'mounts' => $mounts,
    'output' => implode("\n", $output),
    'message' => $success ? 'Agent mounted successfully' : 'Failed to mount agent'
]);