<?php
/**
 * OpenRT File Explorer - Browse mounted snapshot directories
 */

// Get current path from URL parameter, default to /rtMount
$current_path = $_GET['path'] ?? '/rtMount';
$current_path = realpath($current_path) ?: '/rtMount';

// Security: Only allow browsing within /rtMount
if (!str_starts_with($current_path, '/rtMount')) {
    $current_path = '/rtMount';
}

// Handle AJAX requests for directory contents
if (isset($_GET['action']) && $_GET['action'] === 'get_contents') {
    header('Content-Type: application/json');
    
    if (!is_dir($current_path)) {
        echo json_encode(['error' => 'Directory not found']);
        exit;
    }
    
    $items = [];
    $entries = scandir($current_path);
    
    foreach ($entries as $entry) {
        if ($entry === '.') continue;
        
        $full_path = $current_path . '/' . $entry;
        $is_dir = is_dir($full_path);
        $file_size = $is_dir ? 0 : filesize($full_path);
        $size_display = $is_dir ? '-' : formatBytes($file_size);
        $modified_timestamp = filemtime($full_path);
        $modified = date('Y-m-d H:i:s', $modified_timestamp);
        
        $items[] = [
            'name' => $entry,
            'path' => $full_path,
            'is_dir' => $is_dir,
            'size' => $size_display,
            'size_bytes' => $file_size,
            'modified' => $modified,
            'modified_timestamp' => $modified_timestamp,
            'icon' => $is_dir ? 'fas fa-folder' : getFileIcon($entry)
        ];
    }
    
    // Default sort: directories first, then by name
    $sort_by = $_GET['sort'] ?? 'name';
    $sort_order = $_GET['order'] ?? 'asc';
    
    usort($items, function($a, $b) use ($sort_by, $sort_order) {
        // Always keep directories first unless sorting by type
        if ($sort_by !== 'type' && $a['is_dir'] !== $b['is_dir']) {
            return $b['is_dir'] - $a['is_dir'];
        }
        
        $result = 0;
        switch ($sort_by) {
            case 'size':
                $result = $a['size_bytes'] <=> $b['size_bytes'];
                break;
            case 'modified':
                $result = $a['modified_timestamp'] <=> $b['modified_timestamp'];
                break;
            case 'type':
                // Sort by directory status first, then by name
                if ($a['is_dir'] !== $b['is_dir']) {
                    $result = $b['is_dir'] - $a['is_dir'];
                } else {
                    $result = strcasecmp($a['name'], $b['name']);
                }
                break;
            case 'name':
            default:
                $result = strcasecmp($a['name'], $b['name']);
                break;
        }
        
        return $sort_order === 'desc' ? -$result : $result;
    });
    
    echo json_encode([
        'current_path' => $current_path,
        'parent_path' => dirname($current_path),
        'items' => $items,
        'sort_by' => $sort_by,
        'sort_order' => $sort_order
    ]);
    exit;
}

// Handle folder download as ZIP - streaming version
if (isset($_GET['action']) && $_GET['action'] === 'download_folder') {
    $folder_path = $_GET['path'] ?? '';
    
    // Security checks
    $real_path = realpath($folder_path);
    if (!$real_path || !str_starts_with($real_path, '/rtMount')) {
        http_response_code(403);
        die("Access denied");
    }
    
    if (!is_dir($real_path)) {
        http_response_code(404);
        die("Folder not found");
    }
    
    $folder_name = basename($real_path);
    $zip_filename = $folder_name . '.zip';
    
    // Set headers for streaming ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . addslashes($zip_filename) . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Transfer-Encoding: chunked');
    
    // Disable output buffering to enable streaming
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Use system zip command to stream directly to output
    $parent_dir = dirname($real_path);
    $folder_name = basename($real_path);
    
    // Build the zip command that outputs to stdout
    $cmd = sprintf(
        'cd %s && zip -r - %s 2>/dev/null',
        escapeshellarg($parent_dir),
        escapeshellarg($folder_name)
    );
    
    // Execute and stream directly to browser
    $handle = popen($cmd, 'r');
    if (!$handle) {
        http_response_code(500);
        die("Failed to create ZIP stream");
    }
    
    // Stream the ZIP data in chunks
    while (!feof($handle)) {
        $chunk = fread($handle, 8192);
        if ($chunk !== false && $chunk !== '') {
            echo $chunk;
            if (ob_get_level()) {
                ob_flush();
            }
            flush();
        }
    }
    
    $exit_code = pclose($handle);
    if ($exit_code !== 0) {
        // If zip command failed, we can't really recover at this point
        // since headers are already sent, but log the error
        error_log("ZIP command failed with exit code: $exit_code");
    }
    exit;
}

// Get mounted snapshots from openRTTUI.pl
function getMountedSnapshots() {
    $cmd = "sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive list-mounts 2>/dev/null";
    $output = shell_exec($cmd);
    
    $mounts = [];
    if ($output) {
        $data = json_decode($output, true);
        if ($data && isset($data['mounts'])) {
            $mounts = $data['mounts'];
        }
    }
    
    // Also get mount info from system mount command
    $mount_output = shell_exec("mount | grep rtMount");
    $mount_lines = $mount_output ? explode("\n", trim($mount_output)) : [];
    
    $system_mounts = [];
    foreach ($mount_lines as $line) {
        if (preg_match('/on (\/rtMount\/[^\s]+)/', $line, $matches)) {
            $mount_path = $matches[1];
            if (is_dir($mount_path)) {
                $path_parts = explode('/', $mount_path);
                $agent_id = $path_parts[2] ?? 'unknown';
                $snapshot_date = $path_parts[3] ?? 'unknown';
                
                $system_mounts[] = [
                    'agent_id' => $agent_id,
                    'agent_name' => $agent_id,
                    'snapshot_date' => $snapshot_date,
                    'mount_path' => $mount_path,
                    'final_mount' => $mount_path
                ];
            }
        }
    }
    
    return array_merge($mounts, $system_mounts);
}

function formatBytes($size) {
    if ($size == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($size) / log(1024));
    return round($size / pow(1024, $i), 2) . ' ' . $units[$i];
}

function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icon_map = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word', 'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel', 'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint', 'pptx' => 'fas fa-file-powerpoint',
        'jpg' => 'fas fa-file-image', 'jpeg' => 'fas fa-file-image', 'png' => 'fas fa-file-image', 'gif' => 'fas fa-file-image',
        'mp3' => 'fas fa-file-audio', 'wav' => 'fas fa-file-audio',
        'mp4' => 'fas fa-file-video', 'avi' => 'fas fa-file-video',
        'zip' => 'fas fa-file-archive', 'rar' => 'fas fa-file-archive', '7z' => 'fas fa-file-archive',
        'txt' => 'fas fa-file-alt', 'log' => 'fas fa-file-alt',
        'exe' => 'fas fa-cog', 'msi' => 'fas fa-cog'
    ];
    
    return $icon_map[$ext] ?? 'fas fa-file';
}

function formatMountTitle($mount) {
    $mount_path = $mount['final_mount'] ?? $mount['mount_path'];
    $agent_name = $mount['agent_name'] ?? $mount['agent_id'];
    
    // Check if mount path ends with a single letter (drive designation)
    if (preg_match('/\/([A-Z])$/', $mount_path, $matches)) {
        $drive_letter = $matches[1];
        return $agent_name . ' - ' . $drive_letter . ':';
    }
    
    // Default to just the agent name
    return $agent_name;
}

$server_ip = trim(shell_exec('hostname -I | awk \'{print $1}\''));
$mounted_snapshots = getMountedSnapshots();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenRT File Explorer</title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'D-DIN', sans-serif;
            background-color: #191D27;
            color: #E0E0E0;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background-color: #12151B;
            border-bottom: 2px solid #7BCBCD;
            padding: 1rem;
        }
        .navbar-brand {
            color: #EDEDED !important;
            font-size: 1.5rem;
            font-weight: bold;
        }
        .explorer-container {
            flex: 1;
            display: flex;
            height: calc(100vh - 80px);
        }
        .sidebar {
            width: 300px;
            background-color: #1E232E;
            border-right: 1px solid #354C4B;
            padding: 1rem;
            overflow-y: auto;
        }
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .breadcrumb-bar {
            background-color: #354C4B;
            padding: 1rem;
            border-bottom: 1px solid #354C4B;
        }
        .breadcrumb {
            background: none;
            margin: 0;
            padding: 0;
        }
        .breadcrumb-item {
            color: #7BCBCD;
        }
        .breadcrumb-item.active {
            color: #EDEDED;
        }
        .breadcrumb-item a {
            color: #7BCBCD;
            text-decoration: none;
        }
        .breadcrumb-item a:hover {
            color: #6CA872;
        }
        .file-list {
            flex: 1;
            overflow-y: auto;
        }
        .file-table {
            width: 100%;
            border-collapse: collapse;
        }
        .file-table th {
            background-color: #354C4B;
            color: #EDEDED;
            padding: 0.75rem;
            text-align: left;
            border-bottom: 2px solid #354C4B;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .file-table th:hover {
            background-color: #274C4C;
        }
        .file-table th .sort-indicator {
            float: right;
            margin-left: 0.5rem;
            opacity: 0.5;
        }
        .file-table th.sorted .sort-indicator {
            opacity: 1;
        }
        .file-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #354C4B;
            vertical-align: middle;
        }
        .file-table tr:hover td {
            background-color: #354C4B;
        }
        .file-icon {
            width: 40px;
            text-align: center;
            font-size: 1.2rem;
        }
        .file-icon.folder {
            color: #C4AC62;
        }
        .file-icon.parent {
            color: #7BCBCD;
        }
        .file-icon.file {
            color: #7BCBCD;
        }
        .file-name {
            font-weight: 500;
            color: #EDEDED;
            cursor: pointer;
        }
        .file-name:hover {
            color: #7BCBCD;
        }
        .file-size {
            text-align: right;
            color: #7BCBCD;
            font-size: 0.9rem;
            width: 100px;
        }
        .file-modified {
            color: #7BCBCD;
            font-size: 0.9rem;
            width: 150px;
        }
        .file-actions {
            width: 120px;
            text-align: right;
        }
        .btn-download {
            background-color: #6CA872;
            color: #E0E0E0;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            cursor: pointer;
            margin-left: 0.25rem;
        }
        .btn-download:hover {
            background-color: #5A9560;
        }
        .btn-download-folder {
            background-color: #C4AC62;
            color: #E0E0E0;
            border: none;
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            cursor: pointer;
            margin-left: 0.25rem;
        }
        .btn-download-folder:hover {
            background-color: #B39B52;
        }
        .mount-item {
            background-color: #12151B;
            border: 1px solid #354C4B;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        .mount-item:hover {
            border-color: #7BCBCD;
            background-color: #1E232E;
        }
        .mount-title {
            font-weight: bold;
            color: #7BCBCD;
            margin-bottom: 0.5rem;
        }
        .mount-details {
            font-size: 0.85rem;
            color: #7BCBCD;
        }
        .loading {
            text-align: center;
            padding: 3rem;
            color: #7BCBCD;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #354C4B;
        }
        .toolbar {
            background-color: #1E232E;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #354C4B;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .btn-toolbar {
            background-color: #274C4C;
            color: #E0E0E0;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        .btn-toolbar:hover {
            background-color: #6c757d;
        }
        .path-input {
            flex: 1;
            background-color: #12151B;
            border: 1px solid #495057;
            color: #EDEDED;
            padding: 0.5rem;
            border-radius: 4px;
        }
        .path-input:focus {
            outline: none;
            border-color: #7BCBCD;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid d-flex align-items-center">
            <span class="navbar-brand">
                <i class="fas fa-folder-open"></i> OpenRT File Explorer
            </span>
            <a href="index.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-arrow-left"></i> Back to Main
            </a>
        </div>
    </nav>
    
    <div class="explorer-container">
        <div class="sidebar">
            <h5 class="text-light mb-3">
                <i class="fas fa-hdd"></i> Mounted Snapshots
            </h5>
            <div id="mountedSnapshots">
                <?php if (empty($mounted_snapshots)): ?>
                    <div class="empty-state">
                        <i class="fas fa-info-circle mb-2"></i>
                        <p>No snapshots currently mounted</p>
                        <small>Use the Recovery Wizard to mount snapshots</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($mounted_snapshots as $mount): ?>
                        <div class="mount-item" onclick="navigateTo('<?php echo htmlspecialchars($mount['final_mount'] ?? $mount['mount_path']); ?>')">
                            <div class="mount-title">
                                <i class="fas fa-desktop"></i> 
                                <?php echo htmlspecialchars(formatMountTitle($mount)); ?>
                            </div>
                            <div class="mount-details">
                                <?php echo htmlspecialchars($mount['snapshot_date'] ?? 'Unknown date'); ?><br>
                                <small><?php echo htmlspecialchars($mount['final_mount'] ?? $mount['mount_path']); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="main-content">
            <div class="toolbar">
                <button class="btn-toolbar" onclick="goBack()">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button class="btn-toolbar" onclick="goUp()">
                    <i class="fas fa-arrow-up"></i> Up
                </button>
                <input type="text" class="path-input" id="pathInput" placeholder="Enter path..." onkeypress="handlePathInput(event)">
                <button class="btn-toolbar" onclick="refreshDirectory()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            
            <div class="breadcrumb-bar">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb" id="breadcrumb">
                    </ol>
                </nav>
            </div>
            
            <div class="file-list" id="fileList">
                <table class="file-table" id="fileTable" style="display: none;">
                    <thead>
                        <tr>
                            <th onclick="sortBy('name')" id="th-name">
                                Name
                                <span class="sort-indicator">
                                    <i class="fas fa-sort"></i>
                                </span>
                            </th>
                            <th onclick="sortBy('size')" id="th-size">
                                Size
                                <span class="sort-indicator">
                                    <i class="fas fa-sort"></i>
                                </span>
                            </th>
                            <th onclick="sortBy('modified')" id="th-modified">
                                Modified
                                <span class="sort-indicator">
                                    <i class="fas fa-sort"></i>
                                </span>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="fileTableBody">
                    </tbody>
                </table>
                <div class="loading" id="loadingIndicator">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading directory contents...</p>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let currentPath = '<?php echo addslashes($current_path); ?>';
        let history = [];
        let currentSort = 'name';
        let currentOrder = 'asc';
        
        function navigateTo(path) {
            if (currentPath !== path) {
                history.push(currentPath);
            }
            currentPath = path;
            document.getElementById('pathInput').value = path;
            loadDirectory();
        }
        
        function goBack() {
            if (history.length > 0) {
                currentPath = history.pop();
                document.getElementById('pathInput').value = currentPath;
                loadDirectory();
            }
        }
        
        function goUp() {
            const parentPath = currentPath.split('/').slice(0, -1).join('/') || '/';
            if (parentPath !== currentPath) {
                navigateTo(parentPath);
            }
        }
        
        function handlePathInput(event) {
            if (event.key === 'Enter') {
                const newPath = event.target.value;
                if (newPath !== currentPath) {
                    navigateTo(newPath);
                }
            }
        }
        
        function refreshDirectory() {
            loadDirectory();
        }
        
        function sortBy(column) {
            if (currentSort === column) {
                currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort = column;
                currentOrder = 'asc';
            }
            loadDirectory();
        }
        
        function updateSortIndicators() {
            // Reset all indicators
            document.querySelectorAll('.file-table th').forEach(th => {
                th.classList.remove('sorted');
                const icon = th.querySelector('.sort-indicator i');
                if (icon) {
                    icon.className = 'fas fa-sort';
                }
            });
            
            // Update current sort indicator
            const currentTh = document.getElementById(`th-${currentSort}`);
            if (currentTh) {
                currentTh.classList.add('sorted');
                const icon = currentTh.querySelector('.sort-indicator i');
                if (icon) {
                    icon.className = currentOrder === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
                }
            }
        }
        
        function updateBreadcrumb() {
            const breadcrumb = document.getElementById('breadcrumb');
            const parts = currentPath.split('/').filter(part => part !== '');
            
            let html = '<li class="breadcrumb-item"><a href="#" onclick="navigateTo(\'/\')">/</a></li>';
            let path = '';
            
            parts.forEach((part, index) => {
                path += '/' + part;
                if (index === parts.length - 1) {
                    html += `<li class="breadcrumb-item active">${part}</li>`;
                } else {
                    html += `<li class="breadcrumb-item"><a href="#" onclick="navigateTo('${path}')">${part}</a></li>`;
                }
            });
            
            breadcrumb.innerHTML = html;
        }
        
        async function loadDirectory() {
            const fileTable = document.getElementById('fileTable');
            const loadingIndicator = document.getElementById('loadingIndicator');
            const fileTableBody = document.getElementById('fileTableBody');
            
            // Show loading, hide table
            fileTable.style.display = 'none';
            loadingIndicator.style.display = 'block';
            
            updateBreadcrumb();
            
            try {
                const url = `explore.php?action=get_contents&path=${encodeURIComponent(currentPath)}&sort=${currentSort}&order=${currentOrder}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.error) {
                    loadingIndicator.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p>Error: ${data.error}</p>
                        </div>
                    `;
                    return;
                }
                
                // Update sort state
                currentSort = data.sort_by || 'name';
                currentOrder = data.sort_order || 'asc';
                updateSortIndicators();
                
                let html = '';
                
                // Add parent directory link if not at root
                if (currentPath !== '/' && currentPath !== '/rtMount') {
                    html += `
                        <tr onclick="navigateTo('${data.parent_path}')" style="cursor: pointer;">
                            <td>
                                <div style="display: flex; align-items: center;">
                                    <div class="file-icon parent">
                                        <i class="fas fa-arrow-up"></i>
                                    </div>
                                    <span class="file-name">..</span>
                                </div>
                            </td>
                            <td class="file-size">-</td>
                            <td class="file-modified">-</td>
                            <td class="file-actions">-</td>
                        </tr>
                    `;
                }
                
                if (data.items.length === 0 && currentPath === '/rtMount') {
                    loadingIndicator.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-folder-open fa-2x mb-3"></i>
                            <p>No mounted snapshots found</p>
                            <small>Use the Recovery Wizard to mount snapshots first</small>
                        </div>
                    `;
                    return;
                } else if (data.items.length === 0) {
                    html += `
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 3rem; color: #6c757d;">
                                <i class="fas fa-folder-open fa-2x mb-3"></i><br>
                                This directory is empty
                            </td>
                        </tr>
                    `;
                } else {
                    data.items.forEach(item => {
                        const iconClass = item.is_dir ? 'folder' : 'file';
                        const nameClickAction = item.is_dir ? `navigateTo('${item.path}')` : `downloadFile('${item.path}')`;
                        
                        let actionsHtml = '';
                        if (item.is_dir) {
                            actionsHtml = `
                                <button class="btn-download-folder" onclick="event.stopPropagation(); downloadFolder('${item.path}')" title="Download as ZIP">
                                    <i class="fas fa-download"></i> ZIP
                                </button>
                            `;
                        } else {
                            actionsHtml = `
                                <button class="btn-download" onclick="event.stopPropagation(); downloadFile('${item.path}')" title="Download file">
                                    <i class="fas fa-download"></i>
                                </button>
                            `;
                        }
                        
                        html += `
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center;">
                                        <div class="file-icon ${iconClass}">
                                            <i class="${item.icon}"></i>
                                        </div>
                                        <span class="file-name" onclick="${nameClickAction}">${item.name}</span>
                                    </div>
                                </td>
                                <td class="file-size">${item.size}</td>
                                <td class="file-modified">${item.modified}</td>
                                <td class="file-actions">${actionsHtml}</td>
                            </tr>
                        `;
                    });
                }
                
                fileTableBody.innerHTML = html;
                
                // Show table, hide loading
                loadingIndicator.style.display = 'none';
                fileTable.style.display = 'table';
                
            } catch (error) {
                loadingIndicator.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-times-circle fa-2x mb-3"></i>
                        <p>Failed to load directory</p>
                        <small>${error.message}</small>
                    </div>
                `;
            }
        }
        
        function downloadFile(filePath) {
            // Create a temporary form to download the file
            const form = document.createElement('form');
            form.method = 'GET';
            form.action = 'download.php';
            
            const pathInput = document.createElement('input');
            pathInput.type = 'hidden';
            pathInput.name = 'file';
            pathInput.value = filePath;
            
            form.appendChild(pathInput);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }
        
        function downloadFolder(folderPath) {
            const folderName = folderPath.split('/').pop();
            
            if (!confirm(`Download folder "${folderName}" as ZIP file?\n\nThis will create a compressed archive of all files and subfolders.\nLarge folders may take some time to download.`)) {
                return;
            }
            
            // Show loading indicator for the specific button
            const button = event.target.closest('.btn-download-folder');
            const originalHtml = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing...';
            button.disabled = true;
            
            // Create download link and trigger download
            const link = document.createElement('a');
            link.href = `explore.php?action=download_folder&path=${encodeURIComponent(folderPath)}`;
            link.download = folderName + '.zip';
            link.style.display = 'none';
            
            // Add to DOM and click
            document.body.appendChild(link);
            link.click();
            
            // Clean up
            setTimeout(() => {
                document.body.removeChild(link);
                button.innerHTML = '<i class="fas fa-check"></i> Started';
                button.style.backgroundColor = '#28a745';
                
                // Reset button after a few seconds
                setTimeout(() => {
                    button.innerHTML = originalHtml;
                    button.disabled = false;
                    button.style.backgroundColor = '';
                }, 3000);
            }, 500);
        }
        
        // Load initial directory
        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('pathInput').value = currentPath;
            loadDirectory();
        });
    </script>
</body>
</html>