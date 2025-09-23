<?php
/**
 * Get system status using openRTTUI.pl
 */

header('Content-Type: application/json');

// Use openRTTUI.pl status command
$cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive status 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

$output_text = implode("\n", $output);

// Parse the status output
$status_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'Unknown',
    'available_pools' => [],
    'drives' => []
];

// Determine overall status
if (strpos($output_text, 'No Round Trip drives detected') !== false) {
    $status_data['status'] = 'Not Available';
} elseif (strpos($output_text, 'Round Trip Drive Status') !== false || 
         strpos($output_text, 'Drive detected') !== false) {
    if (preg_match('/Pools Imported:\s*(\d+)/', $output_text, $matches) && intval($matches[1]) > 0) {
        $status_data['status'] = 'Ready';
    } else {
        $status_data['status'] = 'Available';
    }
}

// Extract pool information
if (preg_match_all('/Pool:\s*([^\s]+)\s*\(([^)]+)\)/', $output_text, $matches)) {
    for ($i = 0; $i < count($matches[0]); $i++) {
        $status_data['available_pools'][] = [
            'name' => $matches[1][$i],
            'state' => $matches[2][$i]
        ];
    }
}

// Extract drive information
if (preg_match_all('/\/dev\/([a-z]+\d*)\s*-\s*([^,]+),\s*([^,]+)/', $output_text, $matches)) {
    for ($i = 0; $i < count($matches[0]); $i++) {
        $status_data['drives'][] = [
            'name' => '/dev/' . $matches[1][$i],
            'type' => trim($matches[2][$i]),
            'size' => trim($matches[3][$i])
        ];
    }
}

// Add raw output for debugging
$status_data['raw_output'] = $output_text;

echo json_encode($status_data);