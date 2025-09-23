<?php
/**
 * OpenRT Recovery Wizard
 * Step-by-step wizard to select agent, snapshot, and mount
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
        case 'get-agents':
            $result = runOpenRTCommand('list-agents');
            $agents = [];
            
            if ($result['success']) {
                // Try to parse as JSON first
                $json_data = json_decode($result['raw_output'], true);
                
                if ($json_data && isset($json_data['agents'])) {
                    // JSON format - use the agents directly
                    foreach ($json_data['agents'] as $agent) {
                        $agents[] = [
                            'id' => $agent['id'] ?? 'unknown',
                            'hostname' => $agent['hostname'] ?? $agent['name'] ?? 'Unknown',
                            'display_name' => $agent['hostname'] ?? $agent['name'] ?? 'Unknown',
                            'snapshot_count' => $agent['snapshot_count'] ?? 0,
                            'last_backup' => $agent['last_backup'] ?? 'Unknown',
                            'os' => $agent['os'] ?? 'Unknown',
                            'fqdn' => $agent['fqdn'] ?? '',
                            'details' => [
                                "Hostname: " . ($agent['hostname'] ?? 'Unknown'),
                                "OS: " . ($agent['os'] ?? 'Unknown'),
                                "Snapshots: " . ($agent['snapshot_count'] ?? 0),
                                "Last Backup: " . ($agent['last_backup'] ?? 'Unknown')
                            ]
                        ];
                    }
                } else {
                    // Fallback to text parsing
                    $lines = $result['output'];
                    $current_agent = null;
                    
                    foreach ($lines as $line) {
                        // Look for agent headers (typically starts with agent ID or name)
                        if (preg_match('/^([a-f0-9]{32})\s+(.*)$/i', $line, $matches)) {
                            if ($current_agent) {
                                $agents[] = $current_agent;
                            }
                            $current_agent = [
                                'id' => $matches[1],
                                'display_name' => trim($matches[2]),
                                'details' => []
                            ];
                        } elseif ($current_agent && trim($line)) {
                            // Capture agent details
                            if (preg_match('/Hostname:\s*(.+)/', $line, $m)) {
                                $current_agent['hostname'] = trim($m[1]);
                            } elseif (preg_match('/Last Backup:\s*(.+)/', $line, $m)) {
                                $current_agent['last_backup'] = trim($m[1]);
                            } elseif (preg_match('/Snapshots:\s*(\d+)/', $line, $m)) {
                                $current_agent['snapshot_count'] = intval($m[1]);
                            }
                            $current_agent['details'][] = trim($line);
                        }
                    }
                    
                    if ($current_agent) {
                        $agents[] = $current_agent;
                    }
                }
            }
            
            echo json_encode([
                'success' => $result['success'],
                'agents' => $agents,
                'raw_output' => $result['raw_output']
            ]);
            exit;
            
        case 'get-snapshots':
            $agent = $_GET['agent'] ?? '';
            if (!$agent) {
                echo json_encode(['success' => false, 'error' => 'No agent specified']);
                exit;
            }
            
            $result = runOpenRTCommand('list-snapshots', [$agent]);
            $snapshots = [];
            
            if ($result['success']) {
                // Try to parse as JSON first
                $json_data = json_decode($result['raw_output'], true);
                
                if ($json_data && isset($json_data['snapshots'])) {
                    // JSON format - use the snapshots directly
                    foreach ($json_data['snapshots'] as $snapshot) {
                        $snapshots[] = [
                            'epoch' => $snapshot['epoch'] ?? '',
                            'date' => $snapshot['date'] ?? date('Y-m-d H:i:s', intval($snapshot['epoch'] ?? 0)),
                            'description' => $snapshot['creation'] ?? $snapshot['name'] ?? '',
                            'name' => $snapshot['name'] ?? ''
                        ];
                    }
                } else {
                    // Fallback to text parsing
                    foreach ($result['output'] as $line) {
                        // Look for snapshot entries (typically contain epoch timestamps)
                        if (preg_match('/(\d{10,})\s+(.+)/', $line, $matches)) {
                            $snapshots[] = [
                                'epoch' => $matches[1],
                                'date' => date('Y-m-d H:i:s', intval($matches[1])),
                                'description' => trim($matches[2])
                            ];
                        }
                    }
                }
            }
            
            echo json_encode([
                'success' => $result['success'],
                'snapshots' => $snapshots,
                'raw_output' => $result['raw_output']
            ]);
            exit;
            
        case 'mount':
            $agent = $_GET['agent'] ?? '';
            $snapshot = $_GET['snapshot'] ?? '';
            
            if (!$agent) {
                echo json_encode(['success' => false, 'error' => 'No agent specified']);
                exit;
            }
            
            $args = [$agent];
            if ($snapshot) {
                $args[] = $snapshot;
            }
            
            $result = runOpenRTCommand('mount', $args);
            
            // Extract mount path from output if successful
            $mount_path = '';
            if ($result['success']) {
                foreach ($result['output'] as $line) {
                    if (preg_match('/mounted at:\s*(.+)/i', $line, $matches)) {
                        $mount_path = trim($matches[1]);
                        break;
                    }
                }
            }
            
            echo json_encode([
                'success' => $result['success'],
                'mount_path' => $mount_path,
                'raw_output' => $result['raw_output']
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
    <title>OpenRT Recovery Wizard</title>
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
            border-bottom: 2px solid #C4AC62;
            padding: 1rem;
        }
        .navbar-brand {
            color: #EDEDED !important;
            font-size: 1.5rem;
            font-weight: bold;
        }
        .wizard-container {
            flex: 1;
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
            width: 100%;
        }
        .wizard-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3rem;
            position: relative;
        }
        .wizard-progress::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #354C4B;
            z-index: -1;
        }
        .wizard-step {
            flex: 1;
            text-align: center;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #354C4B;
            color: #7BCBCD;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: bold;
            transition: all 0.3s;
        }
        .wizard-step.active .step-number {
            background-color: #C4AC62;
            color: #191D27;
            transform: scale(1.2);
        }
        .wizard-step.completed .step-number {
            background-color: #6CA872;
            color: #191D27;
        }
        .step-title {
            color: #7BCBCD;
            font-size: 0.9rem;
        }
        .wizard-step.active .step-title {
            color: #C4AC62;
            font-weight: bold;
        }
        .wizard-content {
            background-color: #1E232E;
            border: 1px solid #354C4B;
            border-radius: 10px;
            padding: 2rem;
            min-height: 400px;
        }
        .wizard-content h3 {
            color: #C4AC62;
            margin-bottom: 1.5rem;
        }
        .selection-list {
            background-color: #12151B;
            border-radius: 8px;
            border: 1px solid #354C4B;
            margin-bottom: 2rem;
            max-height: 400px;
            overflow-y: auto;
        }
        .selection-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #354C4B;
            cursor: pointer;
            transition: all 0.3s;
        }
        .selection-item:last-child {
            border-bottom: none;
        }
        .selection-item:hover {
            background-color: #1E232E;
        }
        .selection-item.selected {
            background-color: #1e3a1e;
            border-left: 4px solid #6CA872;
        }
        .selection-item .icon {
            font-size: 1.5rem;
            color: #6DA5B4;
            margin-right: 1rem;
            width: 2rem;
            text-align: center;
        }
        .selection-item .content {
            flex: 1;
        }
        .selection-item .title {
            font-size: 1.1rem;
            font-weight: bold;
            color: #EDEDED;
            margin-bottom: 0.25rem;
        }
        .selection-item .details {
            color: #7BCBCD;
            font-size: 0.9rem;
        }
        .selection-item .badge {
            background-color: #C4AC62;
            color: #E0E0E0;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-left: 1rem;
        }
        .selection-item.latest .badge {
            background-color: #C4AC62;
            color: #191D27;
        }
        .wizard-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
        }
        .btn-wizard {
            padding: 0.75rem 2rem;
            border-radius: 5px;
            border: none;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-back {
            background-color: #354C4B;
            color: #E0E0E0;
        }
        .btn-back:hover {
            background-color: #274C4C;
        }
        .btn-next {
            background-color: #C4AC62;
            color: #E0E0E0;
        }
        .btn-next:hover {
            background-color: #B39B52;
        }
        .btn-next:disabled {
            background-color: #354C4B;
            cursor: not-allowed;
        }
        .btn-mount {
            background-color: #6CA872;
            color: #E0E0E0;
        }
        .btn-mount:hover {
            background-color: #5A9560;
        }
        .loading-spinner {
            text-align: center;
            padding: 3rem;
        }
        .loading-spinner i {
            font-size: 3rem;
            color: #C4AC62;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .result-message {
            padding: 2rem;
            text-align: center;
            background-color: #12151B;
            border-radius: 8px;
            margin: 2rem 0;
        }
        .result-message.success {
            border: 2px solid #6CA872;
        }
        .result-message.error {
            border: 2px solid #B05648;
        }
        .result-message h4 {
            margin-bottom: 1rem;
        }
        .result-message.success h4 {
            color: #6CA872;
        }
        .result-message.error h4 {
            color: #B05648;
        }
        .mount-path {
            background-color: #1E232E;
            padding: 1rem;
            border-radius: 5px;
            margin-top: 1rem;
            font-family: monospace;
            color: #6CA872;
        }
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #7BCBCD;
        }
        .raw-output {
            background-color: #191D27;
            color: #6CA872;
            padding: 1rem;
            border-radius: 5px;
            font-family: monospace;
            font-size: 0.85rem;
            max-height: 200px;
            overflow-y: auto;
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid d-flex align-items-center">
            <span class="navbar-brand">
                <i class="fas fa-magic"></i> OpenRT Recovery Wizard
            </span>
            <a href="index.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-arrow-left"></i> Back to Main
            </a>
        </div>
    </nav>
    
    <div class="wizard-container">
        <div class="wizard-progress">
            <div class="wizard-step active" id="step1">
                <div class="step-number">1</div>
                <div class="step-title">Select Agent</div>
            </div>
            <div class="wizard-step" id="step2">
                <div class="step-number">2</div>
                <div class="step-title">Choose Snapshot</div>
            </div>
            <div class="wizard-step" id="step3">
                <div class="step-number">3</div>
                <div class="step-title">Mount & Access</div>
            </div>
        </div>
        
        <div class="wizard-content">
            <!-- Step 1: Select Agent -->
            <div id="step1-content" class="step-content">
                <h3><i class="fas fa-server"></i> Select an Agent</h3>
                <div id="agentsList" class="selection-list">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner"></i>
                        <p>Loading agents...</p>
                    </div>
                </div>
                <div class="wizard-buttons">
                    <button class="btn-wizard btn-back" onclick="window.location.href='index.php'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button class="btn-wizard btn-next" id="nextToStep2" disabled onclick="goToStep2()">
                        Next <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
            
            <!-- Step 2: Choose Snapshot -->
            <div id="step2-content" class="step-content" style="display: none;">
                <h3><i class="fas fa-clock"></i> Choose a Snapshot</h3>
                <div id="snapshotsList" class="selection-list">
                </div>
                <div class="wizard-buttons">
                    <button class="btn-wizard btn-back" onclick="goToStep1()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button class="btn-wizard btn-next" id="nextToStep3" disabled onclick="goToStep3()">
                        Next <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
            
            <!-- Step 3: Mount & Access -->
            <div id="step3-content" class="step-content" style="display: none;">
                <h3><i class="fas fa-check-circle"></i> Review & Mount</h3>
                <div id="mountSummary">
                    <div class="result-message">
                        <h4>Ready to Mount</h4>
                        <p><strong>Agent:</strong> <span id="selectedAgentName"></span></p>
                        <p><strong>Snapshot:</strong> <span id="selectedSnapshotDate"></span></p>
                        <button class="btn-wizard btn-mount" onclick="performMount()">
                            <i class="fas fa-play"></i> Mount Now
                        </button>
                    </div>
                </div>
                <div id="mountResult" style="display: none;">
                </div>
                <div class="wizard-buttons">
                    <button class="btn-wizard btn-back" onclick="goBackToStep2()">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                    <button class="btn-wizard btn-next" onclick="window.location.href='explore.php'">
                        Browse Files <i class="fas fa-folder-open"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let selectedAgent = null;
        let selectedSnapshot = null;
        let agents = [];
        let snapshots = [];
        
        // Load agents on page load
        document.addEventListener('DOMContentLoaded', () => {
            loadAgents();
        });
        
                async function loadAgents() {
            try {
                const response = await fetch('wizard.php?action=get-agents');
                const data = await response.json();
                
                const agentsList = document.getElementById('agentsList');
                
                if (data.success && data.agents && data.agents.length > 0) {
                    agents = data.agents;
                    agentsList.innerHTML = '';
                    
                    data.agents.forEach(agent => {
                        const item = document.createElement('div');
                        item.className = 'selection-item';
                        item.onclick = () => selectAgent(agent);
                        
                        const hostname = agent.hostname || agent.display_name || 'Unknown Host';
                        const snapshots = agent.snapshot_count || 0;
                        const lastBackup = agent.last_backup || 'Unknown';
                        const os = agent.os || 'Unknown OS';
                        
                        item.innerHTML = `
                            <div class="icon">
                                <i class="fas fa-desktop"></i>
                            </div>
                            <div class="content">
                                <div class="title">${hostname}</div>
                                <div class="details">
                                    ${os} • ID: ${agent.id.substring(0, 12)}... • Last Backup: ${lastBackup}
                                </div>
                            </div>
                            <div class="badge">${snapshots} snapshot${snapshots !== 1 ? 's' : ''}</div>
                        `;
                        
                        agentsList.appendChild(item);
                    });
                } else if (data.raw_output) {
                    // If no parsed agents, show raw output
                    agentsList.innerHTML = `
                        <div class="no-data">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No agents found or unable to parse agent list</p>
                            <div class="raw-output">${data.raw_output}</div>
                        </div>
                    `;
                } else {
                    agentsList.innerHTML = `
                        <div class="no-data">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No agents available</p>
                            <p>Please ensure a Round Trip pool is imported first</p>
                        </div>
                    `;
                }
            } catch (error) {
                document.getElementById('agentsList').innerHTML = `
                    <div class="no-data">
                        <i class="fas fa-times-circle"></i>
                        <p>Error loading agents: ${error.message}</p>
                    </div>
                `;
            }
        }
        
        function selectAgent(agent) {
            selectedAgent = agent;
            
            // Update UI
            document.querySelectorAll('.selection-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Enable next button
            document.getElementById('nextToStep2').disabled = false;
        }
        
        async function goToStep2() {
            if (!selectedAgent) return;
            
            // Update progress
            document.getElementById('step1').classList.remove('active');
            document.getElementById('step1').classList.add('completed');
            document.getElementById('step2').classList.add('active');
            
            // Show step 2 content
            document.getElementById('step1-content').style.display = 'none';
            document.getElementById('step2-content').style.display = 'block';
            
            // Load snapshots
            loadSnapshots();
        }
        
        async function loadSnapshots() {
            const snapshotsList = document.getElementById('snapshotsList');
            snapshotsList.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner"></i>
                    <p>Loading snapshots...</p>
                </div>
            `;
            
            try {
                const response = await fetch(`wizard.php?action=get-snapshots&agent=${selectedAgent.id}`);
                const data = await response.json();
                
                
                if (data.success && data.snapshots && data.snapshots.length > 0) {
                    snapshots = data.snapshots;
                    snapshotsList.innerHTML = '';
                    
                    // Add option for latest snapshot
                    const latestItem = document.createElement('div');
                    latestItem.className = 'selection-item latest';
                    latestItem.onclick = () => selectSnapshot(null, true);
                    latestItem.innerHTML = `
                        <div class="icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="content">
                            <div class="title">Latest Snapshot</div>
                            <div class="details">Mount the most recent backup • Recommended for most users</div>
                        </div>
                        <div class="badge">Recommended</div>
                    `;
                    snapshotsList.appendChild(latestItem);
                    
                    // Add individual snapshots
                    data.snapshots.forEach((snapshot, index) => {
                        const item = document.createElement('div');
                        item.className = 'selection-item';
                        item.onclick = () => selectSnapshot(snapshot);
                        
                        const date = new Date(snapshot.date);
                        const formattedDate = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
                        const description = snapshot.description || snapshot.name || '';
                        
                        item.innerHTML = `
                            <div class="icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="content">
                                <div class="title">${formattedDate}</div>
                                <div class="details">
                                    Epoch: ${snapshot.epoch}${description ? ` • ${description}` : ''}
                                </div>
                            </div>
                            <div class="badge">#${data.snapshots.length - index}</div>
                        `;
                        
                        snapshotsList.appendChild(item);
                    });
                } else if (data.raw_output) {
                    snapshotsList.innerHTML = `
                        <div class="no-data">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No snapshots found or unable to parse snapshot list</p>
                            <div class="raw-output">${data.raw_output}</div>
                        </div>
                    `;
                } else {
                    snapshotsList.innerHTML = `
                        <div class="no-data">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>No snapshots available for this agent</p>
                        </div>
                    `;
                }
            } catch (error) {
                snapshotsList.innerHTML = `
                    <div class="no-data">
                        <i class="fas fa-times-circle"></i>
                        <p>Error loading snapshots: ${error.message}</p>
                    </div>
                `;
            }
        }
        
        function selectSnapshot(snapshot, isLatest = false) {
            selectedSnapshot = isLatest ? 'latest' : snapshot;
            
            // Update UI
            document.querySelectorAll('#snapshotsList .selection-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Enable next button
            document.getElementById('nextToStep3').disabled = false;
        }
        
        function goToStep3() {
            if (!selectedAgent || selectedSnapshot === null) return;
            
            // Update progress
            document.getElementById('step2').classList.remove('active');
            document.getElementById('step2').classList.add('completed');
            document.getElementById('step3').classList.add('active');
            
            // Show step 3 content
            document.getElementById('step2-content').style.display = 'none';
            document.getElementById('step3-content').style.display = 'block';
            
            // Update summary
            document.getElementById('selectedAgentName').textContent = 
                selectedAgent.hostname || selectedAgent.display_name || selectedAgent.id;
            document.getElementById('selectedSnapshotDate').textContent = 
                selectedSnapshot === 'latest' ? 'Latest available' : selectedSnapshot.date;
        }
        
        async function performMount() {
            const mountResult = document.getElementById('mountResult');
            const mountSummary = document.getElementById('mountSummary');
            
            mountSummary.style.display = 'none';
            mountResult.style.display = 'block';
            mountResult.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner"></i>
                    <p>Mounting snapshot...</p>
                </div>
            `;
            
            try {
                let url = `wizard.php?action=mount&agent=${selectedAgent.id}`;
                if (selectedSnapshot !== 'latest' && selectedSnapshot.epoch) {
                    url += `&snapshot=${selectedSnapshot.epoch}`;
                }
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    mountResult.innerHTML = `
                        <div class="result-message success">
                            <h4><i class="fas fa-check-circle"></i> Mount Successful!</h4>
                            <p>The snapshot has been successfully mounted.</p>
                            ${data.mount_path ? `
                                <p><strong>Mount Path:</strong></p>
                                <div class="mount-path">${data.mount_path}</div>
                            ` : ''}
                            <div class="raw-output">${data.raw_output}</div>
                        </div>
                    `;
                } else {
                    mountResult.innerHTML = `
                        <div class="result-message error">
                            <h4><i class="fas fa-times-circle"></i> Mount Failed</h4>
                            <p>Unable to mount the selected snapshot.</p>
                            <div class="raw-output">${data.raw_output}</div>
                        </div>
                    `;
                }
            } catch (error) {
                mountResult.innerHTML = `
                    <div class="result-message error">
                        <h4><i class="fas fa-times-circle"></i> Error</h4>
                        <p>An error occurred: ${error.message}</p>
                    </div>
                `;
            }
        }
        
        function goToStep1() {
            // Reset progress
            document.getElementById('step2').classList.remove('active', 'completed');
            document.getElementById('step1').classList.remove('completed');
            document.getElementById('step1').classList.add('active');
            
            // Show step 1 content
            document.getElementById('step2-content').style.display = 'none';
            document.getElementById('step1-content').style.display = 'block';
            
            // Reset selection
            selectedSnapshot = null;
            document.getElementById('nextToStep3').disabled = true;
        }
        
        function goBackToStep2() {
            // Reset progress
            document.getElementById('step3').classList.remove('active', 'completed');
            document.getElementById('step2').classList.remove('completed');
            document.getElementById('step2').classList.add('active');
            
            // Show step 2 content
            document.getElementById('step3-content').style.display = 'none';
            document.getElementById('step2-content').style.display = 'block';
            
            // Reset mount result
            document.getElementById('mountResult').style.display = 'none';
            document.getElementById('mountSummary').style.display = 'block';
        }
    </script>
</body>
</html>
