<?php
/**
 * OpenRT Web Interface - Main Page
 * Complete overhaul for offline operation with openRTTUI.pl integration
 */

function runOpenRTCommand($command, $args = []) {
    $cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive " . $command;
    if (!empty($args)) {
        $cmd .= " " . implode(" ", array_map('escapeshellarg', $args));
    }
    $cmd .= " 2>&1";
    
    $output = [];
    $return_var = 0;
    exec($cmd, $output, $return_var);
    
    return [
        'success' => $return_var === 0,
        'output' => $output,
        'return_code' => $return_var,
        'raw_output' => implode("\n", $output)
    ];
}

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'status':
            $result = runOpenRTCommand('status');
            $status_data = [
                'success' => $result['success'],
                'output' => $result['raw_output'],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            // Parse the output to extract key information
            $status_info = [
                'pools_found' => false,
                'pools_imported' => false,
                'agents_available' => false,
                'drive_attached' => false
            ];
            
            if ($result['success']) {
                $output_text = $result['raw_output'];
                if (strpos($output_text, 'No Round Trip drives detected') !== false) {
                    $status_info['drive_attached'] = false;
                } else if (strpos($output_text, 'Round Trip Drive Status') !== false || 
                          strpos($output_text, 'Drive detected') !== false) {
                    $status_info['drive_attached'] = true;
                }
                
                if (preg_match('/Pools Found:\s*(\d+)/', $output_text, $matches)) {
                    $status_info['pools_found'] = intval($matches[1]) > 0;
                }
                
                if (preg_match('/Pools Imported:\s*(\d+)/', $output_text, $matches)) {
                    $status_info['pools_imported'] = intval($matches[1]) > 0;
                }
                
                if (strpos($output_text, 'Available Agents:') !== false) {
                    $status_info['agents_available'] = true;
                }
            }
            
            $status_data['info'] = $status_info;
            echo json_encode($status_data);
            exit;
            
        case 'import':
            $path = isset($_GET['path']) ? $_GET['path'] : '';
            $args = $path ? [$path] : [];
            $result = runOpenRTCommand('import', $args);
            echo json_encode([
                'success' => $result['success'],
                'output' => $result['raw_output'],
                'message' => $result['success'] ? 'Pool imported successfully' : 'Import failed'
            ]);
            exit;
            
        case 'list-agents':
            $result = runOpenRTCommand('list-agents');
            echo json_encode([
                'success' => $result['success'],
                'output' => $result['raw_output'],
                'agents' => $result['output']
            ]);
            exit;
            
        case 'cleanup':
            $result = runOpenRTCommand('cleanup');
            $result = runOpenRTCommand('cleanup-clones');
            echo json_encode([
                'success' => $result['success'],
                'output' => $result['raw_output'],
                'message' => $result['success'] ? 'Cleanup completed' : 'Cleanup failed'
            ]);
            exit;
            
        case 'cleanup-clones':
            $result = runOpenRTCommand('cleanup-clones');
            echo json_encode([
                'success' => $result['success'],
                'output' => $result['raw_output'],
                'message' => $result['success'] ? 'Clone cleanup completed' : 'Clone cleanup failed'
            ]);
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenRT Control Panel - <?php echo $_SERVER['SERVER_ADDR']; ?></title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'D-DIN', sans-serif;
            background-color: #191D27;
            color: #E0E0E0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background-color: #12151B;
            border-bottom: 2px solid #6DA5B4;
            padding: 1rem;
        }
        .navbar-brand {
            color: #EDEDED !important;
            font-size: 1.75rem;
            font-weight: bold;
        }
        .logo {
            max-height: 50px;
            margin-left: auto;
        }
        .main-container {
            flex: 1;
            padding: 2rem;
        }
        .status-card {
            background-color: #1E232E;
            border: 1px solid #354C4B;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .status-card h4 {
            color: #E0E0E0;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-indicator.connected { background-color: #6CA872; }
        .status-indicator.disconnected { background-color: #B05648; }
        .status-indicator.unknown { background-color: #C4AC62; }
        
        .status-output {
            background-color: #12151B;
            border-radius: 5px;
            padding: 1rem;
            font-size: 0.9rem;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .status-table {
            width: 100%;
            color: #E0E0E0;
        }
        
        .status-table th {
            background-color: #1E232E;
            color: #6DA5B4;
            padding: 0.75rem;
            text-align: left;
            border-bottom: 2px solid #354C4B;
            font-weight: 600;
        }
        
        .status-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #354C4B;
        }
        
        .status-table tr:hover td {
            background-color: #274C4C;
        }
        
        .status-label {
            font-weight: bold;
            color: #7BCBCD;
            width: 30%;
        }
        
        .status-value {
            color: #E0E0E0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: bold;
        }
        
        .status-badge.ready { background-color: #6CA872; color: white; }
        .status-badge.imported { background-color: #7BCBCD; color: #191D27; }
        .status-badge.available { background-color: #C4AC62; color: #191D27; }
        .status-badge.not-available { background-color: #354C4B; color: #E0E0E0; }
        
        .pool-info {
            background-color: #1E232E;
            padding: 0.5rem;
            border-radius: 4px;
            margin: 0.25rem 0;
        }
        
        .drive-info {
            display: inline-block;
            background-color: #274C4C;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }
        
        .action-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .action-card {
            background-color: #1E232E;
            border: 1px solid #354C4B;
            border-radius: 8px;
            padding: 1.5rem;
            transition: transform 0.2s;
        }
        

        .action-card h5 {
            color: #E0E0E0;
            margin-bottom: 1rem;
        }
        
        .action-card .description {
            color: #E0E0E0;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .btn-action {
            width: 100%;
            padding: 0.75rem;
            font-size: 1.1rem;
            border-radius: 5px;
            border: none;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .btn-check { background-color: #6CA872; }
        .btn-check:hover { background-color: #5A9560; }
        
        .btn-import { background-color: #6DA5B4; }
        .btn-import:hover { background-color: #5C8D9C; }
        
        .btn-export { background-color: #79BE7E; }
        .btn-export:hover { background-color: #6CA872; }
        
        .btn-terminal { background-color: #BD7BBF; }
        .btn-terminal:hover { background-color: #A669A8; }
        
        .btn-cleanup { background-color: #B05648; }
        .btn-cleanup:hover { background-color: #984538; }
        
        .btn-explore { background-color: #7BCBCD; }
        .btn-explore:hover { background-color: #6BBABC; }
        
        .input-group {
            margin-bottom: 1rem;
        }
        
        .input-group input {
            background-color: #12151B;
            border: 1px solid #354C4B;
            color: #E0E0E0;
            padding: 0.5rem;
            border-radius: 5px 0 0 5px;
        }
        
        .input-group button {
            border-radius: 0 5px 5px 0;
        }
        
        .loading {
            position: relative;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            margin: auto;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            border: 3px solid transparent;
            border-top-color: #E0E0E0;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .footer {
            background-color: #12151B;
            border-top: 1px solid #1E232E;
            padding: 1rem;
            text-align: center;
            color: #7BCBCD;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        
        .modal-content {
            background-color: #1E232E;
            margin: 10% auto;
            padding: 2rem;
            border: 1px solid #6DA5B4;
            border-radius: 10px;
            width: 80%;
            max-width: 600px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .modal-header h3 {
            color: #6DA5B4;
            margin: 0;
        }
        
        .close-modal {
            color: #7BCBCD;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #EDEDED;
        }
        
        .modal-body {
            background-color: #12151B;
            border-radius: 5px;
            padding: 1rem;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .quick-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .quick-link {
            flex: 1;
            padding: 0.5rem;
            background-color: #354C4B;
            border-radius: 5px;
            text-align: center;
            text-decoration: none;
            color: #6DA5B4;
            transition: background-color 0.3s;
        }
        
        .quick-link:hover {
            background-color: #354C4B;
            color: #7BCBCD;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid d-flex align-items-center">
            <span class="navbar-brand">
                OpenRT - <?php echo $_SERVER['SERVER_ADDR']; ?>
            </span>
            <img src="assets/images/openRT.png" alt="OpenRT Logo" class="logo">
        </div>
    </nav>
    
    <div class="main-container container">
        <div class="status-card">
            <h4>
                System Status 
                <span id="statusIndicator" class="status-indicator unknown"></span>
                <button class="btn btn-sm btn-success" onclick="checkStatus()" style="margin-left: auto;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </h4>
            <div id="statusOutput" class="status-output">
                Checking system status...
            </div>
        </div>
        
        <div class="action-cards">
            
            <div class="action-card">
                <h5><i class="fas fa-download"></i> Import Pool</h5>
                <p class="description">Import ZFS pools from Round Trip drives</p>
                <div class="input-group">
                    <button class="btn-action btn-import" onclick="importPool()">
                        <i class="fas fa-database"></i> Import Pool
                    </button>
                    <input class="mt-2" type="text" id="importPath" placeholder="Optional: /dev/sdX (leave empty for auto)"><br>

                </div>
            </div>
            
            <div class="action-card">
                <h5><i class="fas fa-file-export"></i> Recovery Wizard</h5>
                <p class="description">Export and mount agent snapshots step-by-step</p>
                <button class="btn-action btn-export" onclick="window.location.href='wizard.php'">
                    <i class="fas fa-magic"></i> Launch Recovery Wizard
                </button>
            </div>
            

            
            <div class="action-card">
                <h5><i class="fas fa-folder-open"></i> File Explorer</h5>
                <p class="description">Browse mounted snapshots and files</p>
                <button class="btn-action btn-explore" onclick="window.location.href='explore.php'">
                    <i class="fas fa-folder-tree"></i> Browse Files
                </button>
            </div>

            <div class="action-card">
                <h5><i class="fas fa-terminal"></i> Diagnostic Terminal</h5>
                <p class="description">Web-based shell for system diagnostics</p>
                <button class="btn-action btn-terminal" onclick="window.location.href='terminal.php'">
                    <i class="fas fa-terminal"></i> Open Terminal
                </button>
            </div>
            
            <div class="action-card">
                <h5><i class="fas fa-broom"></i> Cleanup</h5>
                <p class="description">Clean up mounted snapshots and resources</p>
                <button class="btn-action btn-cleanup" onclick="cleanup()">
                    <i class="fas fa-trash-alt"></i> Run Cleanup
                </button>
            </div>
            
            <div class="action-card">
                <h5><i class="fas fa-copy"></i> Cleanup Old Clones</h5>
                <p class="description">Remove old clone snapshots from past mount attempts</p>
                <button class="btn-action btn-cleanup" onclick="cleanupClones()">
                    <i class="fas fa-clone"></i> Cleanup Clones
                </button>
            </div>
        </div>
        
        <div class="quick-links">
            <a href="log_viewer.php" class="quick-link">
                <i class="fas fa-file-alt"></i> View Logs
            </a>
            <a href="phpinfo.php" class="quick-link">
                <i class="fas fa-info-circle"></i> PHP Info
            </a>
        </div>
    </div>
    
    <div class="footer">
        <p>OpenRT Web Interface - All operations use openRTTUI.pl</p>
        <small>System: <?php echo gethostname(); ?> | IP: <?php echo $_SERVER['SERVER_ADDR']; ?></small>
    </div>
    
    <!-- Result Modal -->
    <div id="resultModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Operation Result</h3>
                <span class="close-modal" onclick="closeModal()">&times;</span>
            </div>
            <div id="modalBody" class="modal-body status-output">
            </div>
        </div>
    </div>
    
    <script>
        let statusCheckInterval;
        
        function updateStatusIndicator(connected) {
            const indicator = document.getElementById('statusIndicator');
            indicator.className = 'status-indicator ' + (connected ? 'connected' : 'disconnected');
        }
        
        async function checkStatus() {
            const statusOutput = document.getElementById('statusOutput');
            const refreshButton = document.querySelector('button[onclick="checkStatus()"]');
            const refreshIcon = refreshButton.querySelector('i');
            
            // Start rotating the refresh icon
            refreshIcon.classList.add('fa-spin');
            refreshButton.disabled = true;
            
            try {
                const response = await fetch('index.php?action=status');
                const data = await response.json();
                
                if (data.success) {
                    // Try to parse the output as JSON
                    let statusInfo = null;
                    try {
                        statusInfo = JSON.parse(data.output);
                    } catch (e) {
                        // If not JSON, display as plain text
                        statusOutput.innerHTML = `<pre style="color: #6CA872; margin: 0;">${data.output || 'Status check completed'}</pre>`;
                        updateStatusIndicator(data.info.drive_attached);
                        return;
                    }
                    
                    // Build formatted status display
                    let html = '<table class="status-table">';
                    
                    // System Status
                    html += '<tr>';
                    html += '<td class="status-label">System Status</td>';
                    html += '<td class="status-value">';
                    let statusClass = 'not-available';
                    if (statusInfo.status === 'Imported') statusClass = 'imported';
                    else if (statusInfo.status === 'Ready') statusClass = 'ready';
                    else if (statusInfo.status === 'Available') statusClass = 'available';
                    html += `<span class="status-badge ${statusClass}">${statusInfo.status || 'Unknown'}</span>`;
                    html += '</td>';
                    html += '</tr>';
                    
                    // Timestamp
                    html += '<tr>';
                    html += '<td class="status-label">Last Updated</td>';
                    html += `<td class="status-value">${statusInfo.timestamp || 'Unknown'}</td>`;
                    html += '</tr>';
                    
                    // Drives
                    if (statusInfo.drives && statusInfo.drives.length > 0) {
                        html += '<tr>';
                        html += '<td class="status-label">Attached Drives</td>';
                        html += '<td class="status-value">';
                        statusInfo.drives.forEach(drive => {
                            html += `<span class="drive-info"><i class="fas fa-hdd"></i> ${drive.name} (${drive.type}, ${drive.size})</span>`;
                        });
                        html += '</td>';
                        html += '</tr>';
                    }
                    
                    // Imported Pools
                    if (statusInfo.imported_pools && statusInfo.imported_pools.length > 0) {
                        html += '<tr>';
                        html += '<td class="status-label">Imported Pools</td>';
                        html += '<td class="status-value">';
                        statusInfo.imported_pools.forEach(pool => {
                            html += '<div class="pool-info">';
                            html += `<strong>${pool.name}</strong> `;
                            if (pool.is_rt_pool) html += '<span class="status-badge ready">Round Trip</span> ';
                            html += `<br>Size: ${pool.size}, Allocated: ${pool.allocated}`;
                            html += '</div>';
                        });
                        html += '</td>';
                        html += '</tr>';
                    }
                    
                    // Available Pools (not imported)
                    if (statusInfo.available_pools && statusInfo.available_pools.length > 0) {
                        html += '<tr>';
                        html += '<td class="status-label">Available Pools</td>';
                        html += '<td class="status-value">';
                        statusInfo.available_pools.forEach(pool => {
                            html += `<div class="pool-info"><strong>${pool.name}</strong> (${pool.state || 'Ready to import'})</div>`;
                        });
                        html += '</td>';
                        html += '</tr>';
                    }
                    
                    // Pool Status Flags
                    html += '<tr>';
                    html += '<td class="status-label">Pool Status</td>';
                    html += '<td class="status-value">';
                    if (statusInfo.has_drives) {
                        html += '<i class="fas fa-check-circle" style="color: #6CA872;"></i> Drives Detected ';
                    } else {
                        html += '<i class="fas fa-times-circle" style="color: #dc3545;"></i> No Drives ';
                    }
                    
                    if (statusInfo.has_imported_pool) {
                        html += '<i class="fas fa-check-circle" style="color: #28a745;"></i> Pool Imported ';
                    } else if (statusInfo.has_available_pool) {
                        html += '<i class="fas fa-exclamation-circle" style="color: #ffc107;"></i> Pool Available ';
                    } else {
                        html += '<i class="fas fa-times-circle" style="color: #6c757d;"></i> No Pools ';
                    }
                    html += '</td>';
                    html += '</tr>';
                    
                    html += '</table>';
                    
                    statusOutput.innerHTML = html;
                    updateStatusIndicator(statusInfo.has_drives || data.info.drive_attached);
                } else {
                    statusOutput.innerHTML = `<div class="alert alert-danger" style="background-color: #dc3545; color: white; border: none;">\n                        <i class="fas fa-exclamation-triangle"></i> Error checking status\n                        <pre style="margin-top: 0.5rem; color: white;">${data.output || 'Unknown error'}</pre>\n                    </div>`;
                    updateStatusIndicator(false);
                }
            } catch (error) {
                statusOutput.innerHTML = `<div class="alert alert-danger" style="background-color: #dc3545; color: white; border: none;">\n                    <i class="fas fa-times-circle"></i> Error: ${error.message}\n                </div>`;
                updateStatusIndicator(false);
            } finally {
                // Stop rotating the refresh icon and re-enable button
                refreshIcon.classList.remove('fa-spin');
                refreshButton.disabled = false;
            }
        }
        
        async function checkRoundTrip() {
            const statusOutput = document.getElementById('statusOutput');
            statusOutput.innerHTML = 'Scanning for Round Trip drives...';
            
            try {
                const response = await fetch('index.php?action=status');
                const data = await response.json();
                
                if (data.success) {
                    // Parse and display status in modal
                    let modalContent = data.output;
                    try {
                        const statusInfo = JSON.parse(data.output);
                        modalContent = '<div style="color: #fff;">';
                        modalContent += `<p><strong>Status:</strong> ${statusInfo.status}</p>`;
                        if (statusInfo.drives && statusInfo.drives.length > 0) {
                            modalContent += '<p><strong>Detected Drives:</strong></p><ul>';
                            statusInfo.drives.forEach(drive => {
                                modalContent += `<li>${drive.name} - ${drive.type} (${drive.size})</li>`;
                            });
                            modalContent += '</ul>';
                        }
                        if (statusInfo.available_pools && statusInfo.available_pools.length > 0) {
                            modalContent += '<p><strong>Available Pools:</strong></p><ul>';
                            statusInfo.available_pools.forEach(pool => {
                                modalContent += `<li>${pool.name}</li>`;
                            });
                            modalContent += '</ul>';
                        }
                        modalContent += '</div>';
                    } catch (e) {
                        // Use raw output if not JSON
                    }
                    
                    showModal('Round Trip Detection', modalContent);
                    
                    // Refresh the main status display
                    await checkStatus();
                    updateStatusIndicator(data.info.drive_attached);
                    
                    if (data.info.drive_attached && !data.info.pools_imported) {
                        if (confirm('Round Trip drive detected! Would you like to import the pool?')) {
                            await importPool();
                        }
                    }
                } else {
                    showModal('Detection Failed', data.output || 'Unknown error');
                }
            } catch (error) {
                showModal('Error', 'Failed to check for Round Trip: ' + error.message);
            }
        }
        
        async function importPool() {
            const pathInput = document.getElementById('importPath');
            const path = pathInput.value.trim();
            
            showModal('Importing Pool', 'Please wait, importing ZFS pool...');
            
            try {
                const url = path ? `index.php?action=import&path=${encodeURIComponent(path)}` 
                                : 'index.php?action=import';
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    showModal('Import Successful', data.output || 'Pool imported successfully');
                    pathInput.value = '';
                    checkStatus(); // Refresh status
                } else {
                    showModal('Import Failed', data.output || 'Failed to import pool');
                }
            } catch (error) {
                showModal('Error', 'Import error: ' + error.message);
            }
        }
        
        async function cleanup() {
            if (!confirm('This will unmount all snapshots and clean up resources. Continue?')) {
                return;
            }
            
            showModal('Cleanup', 'Running cleanup...');
            
            try {
                const response = await fetch('index.php?action=cleanup');
                const data = await response.json();
                
                if (data.success) {
                    showModal('Cleanup Complete', data.output || 'Cleanup completed successfully');
                    checkStatus(); // Refresh status
                } else {
                    showModal('Cleanup Failed', data.output || 'Cleanup failed');
                }
            } catch (error) {
                showModal('Error', 'Cleanup error: ' + error.message);
            }
        }
        
        async function cleanupClones() {
            if (!confirm('This will remove old clone snapshots to free up space. Continue?')) {
                return;
            }
            
            showModal('Cleanup Clones', 'Cleaning up old clones...');
            
            try {
                const response = await fetch('index.php?action=cleanup-clones');
                const data = await response.json();
                
                if (data.success) {
                    showModal('Clone Cleanup Complete', data.output || 'Clone cleanup completed successfully');
                    checkStatus(); // Refresh status
                } else {
                    showModal('Clone Cleanup Failed', data.output || 'Clone cleanup failed');
                }
            } catch (error) {
                showModal('Error', 'Clone cleanup error: ' + error.message);
            }
        }
        
        function showModal(title, content) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalBody').innerHTML = content;
            document.getElementById('resultModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('resultModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('resultModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // Initial status check and periodic refresh
        document.addEventListener('DOMContentLoaded', () => {
            checkStatus();
            // Check status every 30 seconds
            statusCheckInterval = setInterval(checkStatus, 30000);
        });
        
        // Clean up interval on page unload
        window.addEventListener('beforeunload', () => {
            if (statusCheckInterval) {
                clearInterval(statusCheckInterval);
            }
        });
    </script>
</body>
</html>