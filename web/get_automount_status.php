<?php
/**
 * Get detailed automount operation status
 */

header('Content-Type: application/json');

// Check if automount is running by looking for active openRTTUI.pl processes
$ps_output = [];
exec("ps aux | grep -E 'openRTTUI.pl.*import' | grep -v grep", $ps_output);

$running = !empty($ps_output);
$progress = 0;
$current_step = 'Idle';
$details = [];

if ($running) {
    // Automount is running, try to get status from openRTTUI.pl
    $status_cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive status 2>&1 | tail -20";
    $status_output = [];
    exec($status_cmd, $status_output);
    
    // Parse status to determine progress
    $status_text = implode("\n", $status_output);
    
    if (strpos($status_text, 'Scanning for drives') !== false) {
        $progress = 25;
        $current_step = 'Scanning for Round Trip drives...';
    } elseif (strpos($status_text, 'Importing pool') !== false) {
        $progress = 50;
        $current_step = 'Importing ZFS pool...';
    } elseif (strpos($status_text, 'Pool imported') !== false) {
        $progress = 75;
        $current_step = 'Mounting volumes...';
    } elseif (strpos($status_text, 'Ready') !== false) {
        $progress = 100;
        $current_step = 'Complete';
    }
    
    // Add recent status lines as details
    foreach ($status_output as $line) {
        if (trim($line)) {
            $details[] = [
                'time' => time(),
                'message' => trim($line)
            ];
        }
    }
} else {
    // Check if automount completed recently
    $log_file = '/var/log/openrt_automount.log';
    if (file_exists($log_file)) {
        $recent_lines = [];
        exec("tail -10 " . escapeshellarg($log_file), $recent_lines);
        
        foreach ($recent_lines as $line) {
            if (trim($line)) {
                // Try to extract timestamp if present
                $time = time();
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                    $time = strtotime($matches[1]);
                }
                
                $details[] = [
                    'time' => $time,
                    'message' => trim($line)
                ];
            }
        }
    }
}

echo json_encode([
    'status' => [
        'running' => $running,
        'progress' => $progress,
        'current_step' => $current_step,
        'details' => array_slice($details, -5) // Last 5 details only
    ],
    'timestamp' => time()
]);