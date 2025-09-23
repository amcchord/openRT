<?php
/**
 * Get agent metadata using openRTTUI.pl
 */

header('Content-Type: application/json');

// Use openRTTUI.pl list-agents command
$cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive list-agents 2>&1";

$output = [];
$return_var = 0;
exec($cmd, $output, $return_var);

$agents = [];
$agent_count = 0;

if ($return_var === 0) {
    // Parse the agent list output
    $current_agent = null;
    $agent_id = '';
    
    foreach ($output as $line) {
        $line = trim($line);
        
        // Skip empty lines and headers
        if (empty($line) || strpos($line, '===') !== false || strpos($line, '---') !== false) {
            continue;
        }
        
        // Check for agent ID line (32 character hex string)
        if (preg_match('/^([a-f0-9]{32})\b/i', $line, $matches)) {
            // Save previous agent if exists
            if ($current_agent && $agent_id) {
                $agents[$agent_id] = $current_agent;
                $agent_count++;
            }
            
            // Start new agent
            $agent_id = $matches[1];
            $current_agent = [
                'id' => $agent_id,
                'hostname' => 'Unknown',
                'os' => 'Unknown',
                'snapshot_count' => 0,
                'last_backup' => null,
                'name' => $agent_id,
                'fqdn' => '',
                'Volumes' => []
            ];
        } elseif ($current_agent) {
            // Parse agent details
            if (preg_match('/Hostname:\s*(.+)/i', $line, $matches)) {
                $current_agent['hostname'] = trim($matches[1]);
                $current_agent['name'] = trim($matches[1]);
            } elseif (preg_match('/FQDN:\s*(.+)/i', $line, $matches)) {
                $current_agent['fqdn'] = trim($matches[1]);
            } elseif (preg_match('/OS:\s*(.+)/i', $line, $matches)) {
                $current_agent['os'] = trim($matches[1]);
            } elseif (preg_match('/Operating System:\s*(.+)/i', $line, $matches)) {
                $current_agent['os'] = trim($matches[1]);
            } elseif (preg_match('/Snapshots:\s*(\d+)/i', $line, $matches)) {
                $current_agent['snapshot_count'] = intval($matches[1]);
            } elseif (preg_match('/Last Backup:\s*(.+)/i', $line, $matches)) {
                $backup_str = trim($matches[1]);
                // Try to parse as timestamp or keep as string
                if (is_numeric($backup_str)) {
                    $current_agent['last_backup'] = intval($backup_str);
                } else {
                    $current_agent['last_backup'] = $backup_str;
                }
            } elseif (preg_match('/Volume\s+([^:]+):\s*(.+)/i', $line, $matches)) {
                // Parse volume information
                $mount_point = trim($matches[1]);
                $volume_info = trim($matches[2]);
                
                // Try to extract size information
                if (preg_match('/(\d+(?:\.\d+)?)\s*(GB|TB|MB)/i', $volume_info, $size_matches)) {
                    $size = floatval($size_matches[1]);
                    $unit = strtoupper($size_matches[2]);
                    
                    // Convert to bytes
                    $multiplier = 1;
                    switch ($unit) {
                        case 'TB':
                            $multiplier = 1024 * 1024 * 1024 * 1024;
                            break;
                        case 'GB':
                            $multiplier = 1024 * 1024 * 1024;
                            break;
                        case 'MB':
                            $multiplier = 1024 * 1024;
                            break;
                    }
                    
                    $capacity = $size * $multiplier;
                    
                    $current_agent['Volumes'][$mount_point] = [
                        'capacity' => strval($capacity),
                        'used' => strval($capacity * 0.5), // Estimate 50% usage if not provided
                        'available' => strval($capacity * 0.5)
                    ];
                }
            }
        }
    }
    
    // Save last agent if exists
    if ($current_agent && $agent_id) {
        $agents[$agent_id] = $current_agent;
        $agent_count++;
    }
}

// If no agents were parsed but command succeeded, try alternative parsing
if (empty($agents) && $return_var === 0) {
    // Fallback: look for any JSON files in metadata directory
    $metadata_dir = '/mnt/openRT/metadata';
    if (is_dir($metadata_dir)) {
        $files = glob($metadata_dir . '/*.json');
        foreach ($files as $file) {
            $agent_id = basename($file, '.json');
            $json_content = file_get_contents($file);
            if ($json_content) {
                $agent_data = json_decode($json_content, true);
                if ($agent_data) {
                    $agents[$agent_id] = array_merge(
                        ['id' => $agent_id],
                        $agent_data
                    );
                    $agent_count++;
                }
            }
        }
    }
}

// Prepare response
$response = [
    'timestamp' => time(),
    'agent_count' => $agent_count,
    'agents' => $agents,
    'raw_output' => implode("\n", $output)
];

echo json_encode($response);