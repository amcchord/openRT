<?php
/**
 * Import/Export ZFS pools using openRTTUI.pl
 */

header('Content-Type: application/json');

if (!isset($_GET['action'])) {
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

$action = $_GET['action'];

switch ($action) {
    case 'import':
        // Import pools using openRTTUI.pl
        $path = isset($_GET['path']) ? $_GET['path'] : '';
        
        if ($path) {
            $cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import " . escapeshellarg($path);
        } else {
            $cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import";
        }
        
        $output = [];
        $return_var = 0;
        exec($cmd . " 2>&1", $output, $return_var);
        
        echo json_encode([
            'success' => $return_var === 0,
            'output' => implode("\n", $output),
            'message' => $return_var === 0 ? 'Pool imported successfully' : 'Import failed'
        ]);
        break;
        
    case 'export':
        // Export pools - this would typically be cleanup in openRTTUI.pl terms
        $cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive cleanup";
        
        $output = [];
        $return_var = 0;
        exec($cmd . " 2>&1", $output, $return_var);
        
        echo json_encode([
            'success' => $return_var === 0,
            'output' => implode("\n", $output),
            'message' => $return_var === 0 ? 'Pool exported and cleaned up successfully' : 'Export failed'
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}