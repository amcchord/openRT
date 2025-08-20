<?php
header('Content-Type: application/json');

if (!isset($_GET['agent_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing agent_id parameter'
    ]);
    exit;
}

$agent_id = $_GET['agent_id'];

// This prevents command injection and directory traversal attacks.
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $agent_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid agent_id format'
    ]);
}

$agent_path = "/agents/$agent_id/";
$mount_path = "/rtMount/$agent_id";
$escaped_agent_path = escapeshellarg($agent_path);
$escaped_mount_path = escapeshellarg($mount_path);

// Check if the directory exists and has mounted volumes
$output = [];
$return_var = 0;
exec("mount | grep $escaped_mount_path 2>&1", $output, $return_var);

// Also check for ZFS clones
$clone_output = [];
exec("zfs list -H -o name | grep 'mount_' | grep $escaped_agent_path 2>&1", $clone_output, $return_var);
// Filter clone output to only include mounted clones
$mounted_clones = [];
foreach ($clone_output as $clone) {
    $clone = trim($clone);
    if (!empty($clone)) {
        // Check if clone has a mountpoint and is mounted
        $mount_check = [];
        exec("zfs get -H -o value mounted $clone 2>/dev/null", $mount_check);
        if (!empty($mount_check) && trim($mount_check[0]) === 'yes') {
            $mounted_clones[] = $clone;
        }
    }
}
$clone_output = $mounted_clones;


// Return mounted status - consider either regular mounts or clones
echo json_encode([
    'success' => true,
    'mounted' => count($output) > 0 || count($clone_output) > 0,
    'mount_path' => $mount_path
]); 
