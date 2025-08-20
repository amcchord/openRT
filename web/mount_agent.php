<?php
header('Content-Type: application/json');

if (!isset($_POST['agent_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing agent_id parameter'
    ]);
    exit;
}

$agent_id = $_POST['agent_id'];
$output = [];
$return_var = 0;

// This prevents command injection and directory traversal attacks.
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $agent_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid agent_id format'
    ]);
}

// First run cleanup for this specific agent to ensure no stale mounts
exec("sudo /usr/local/openRT/openRTApp/rtFileMount.pl -cleanup='$agent_id' 2>&1", $output, $return_var);
if ($return_var !== 0) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to cleanup existing mounts for agent',
        'output' => implode("\n", $output)
    ]);
    exit;
}

// Clear output array for the mount command
$output = [];

// Execute mount command for all snapshots of the agent
exec("sudo /usr/local/openRT/openRTApp/rtFileMount.pl -j '$agent_id' all 2>&1", $output, $return_var);

// Get the JSON output
$json_output = implode("\n", $output);
$result = json_decode($json_output, true);

// If we got valid JSON, use it for the response
if (json_last_error() === JSON_ERROR_NONE && $result) {
    echo json_encode($result);
} else {
    // Otherwise, construct our own response
    $response = [
        'success' => $return_var === 0,
        'output' => $json_output
    ];

    if ($return_var !== 0) {
        $response['error'] = 'Mount failed with code ' . $return_var;
    }

    echo json_encode($response);
} 
