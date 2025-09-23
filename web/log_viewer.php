<?php
function getOpenRTVersion() {
    $readmePath = '../openRTApp/README.md';
    if (file_exists($readmePath)) {
        $content = file_get_contents($readmePath);
        if ($content !== false) {
            // Get the last line that contains version info
            $lines = explode("\n", $content);
            foreach (array_reverse($lines) as $line) {
                if (preg_match('/VER\s+(.+)/', trim($line), $matches)) {
                    return trim($matches[1]);
                }
            }
        }
    }
    return '1.0'; // Default fallback version
}

function getAvailableTools() {
    $logsDir = '../logs/';
    $tools = [];
    
    if (is_dir($logsDir)) {
        $files = scandir($logsDir);
        foreach ($files as $file) {
            if (preg_match('/^(rt[A-Za-z]+)_/', $file, $matches)) {
                $tool = $matches[1];
                if (!in_array($tool, $tools)) {
                    $tools[] = $tool;
                }
            }
        }
    }
    
    sort($tools);
    return $tools;
}

function getLogsForTool($tool) {
    $logsDir = '../logs/';
    $logs = [];
    
    if (is_dir($logsDir)) {
        $files = scandir($logsDir);
        foreach ($files as $file) {
            if (preg_match('/^' . preg_quote($tool) . '_(\d{8})_(\d{6})_(\d+)\.log$/', $file, $matches)) {
                $date = $matches[1];
                $time = $matches[2];
                $pid = $matches[3];
                
                $filePath = $logsDir . $file;
                $fileSize = filesize($filePath);
                $timestamp = filemtime($filePath);
                
                $logs[] = [
                    'filename' => $file,
                    'date' => $date,
                    'time' => $time,
                    'pid' => $pid,
                    'size' => $fileSize,
                    'timestamp' => $timestamp,
                    'formatted_date' => date('Y-m-d H:i:s', strtotime($date . ' ' . $time))
                ];
            }
        }
    }
    
    // Sort by timestamp descending (newest first)
    usort($logs, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return $logs;
}

function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

$openRTVersion = getOpenRTVersion();
$availableTools = getAvailableTools();
$selectedTool = $_GET['tool'] ?? '';
$selectedLog = $_GET['log'] ?? '';
$logs = [];

if ($selectedTool) {
    $logs = getLogsForTool($selectedTool);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenRT Log Viewer</title>
    <!-- Local Bootstrap 5 CSS -->
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- D-Din Font -->
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <style>
        body {
            font-family: 'D-DIN', sans-serif;
            background-color: #191D27;
            color: #E0E0E0;
        }
        .logo {
            max-height: 50px;
            margin: 10px;
        }
        .navbar {
            background-color: #12151B;
            color: #E0E0E0;
            border-bottom: 1px solid #1E232E;
        }
        .navbar-brand {
            color: #EDEDED !important;
        }
        .card {
            background-color: #1E232E;
            border-color: #354C4B;
        }
        .card-header {
            background-color: #354C4B;
            color: #EDEDED;
            border-bottom-color: #1E232E;
        }
        .table.table-striped {
            color: #E0E0E0 !important;
            border-color: #354C4B;
        }
        .table.table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: #274C4C !important;
            color: #E0E0E0 !important;
            border-bottom-color: #354C4B;
        }
        .table.table-striped > tbody > tr:nth-of-type(even) > * {
            background-color: #1E232E !important;
            color: #E0E0E0 !important;
            border-bottom-color: #354C4B;
        }
        .table.table-striped > tbody > tr > td {
            border-color: #354C4B;
        }
        .text-muted {
            color: #7BCBCD !important;
        }
        .btn-tool {
            background-color: #6DA5B4;
            color: #E0E0E0;
            border: none;
            margin: 0.25rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }
        .btn-tool:hover {
            background-color: #5C8D9C;
            color: #EDEDED;
            text-decoration: none;
        }
        .btn-tool.active {
            background-color: #4B7B8A;
        }
        .btn-log {
            background-color: #6CA872;
            color: #191D27;
            border: none;
            margin: 0.1rem;
            padding: 0.3rem 0.6rem;
            border-radius: 0.25rem;
            text-decoration: none;
            display: inline-block;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }
        .btn-log:hover {
            background-color: #5A9560;
            color: #EDEDED;
            text-decoration: none;
        }
        .btn-back {
            background-color: #354C4B;
            color: #E0E0E0;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 1rem;
        }
        .btn-back:hover {
            background-color: #274C4C;
            color: #EDEDED;
            text-decoration: none;
        }
        .log-content {
            background-color: #12151B;
            color: #E0E0E0;
            padding: 1rem;
            border-radius: 0.375rem;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            max-height: 70vh;
            overflow-y: auto;
            border: 1px solid #354C4B;
        }
        .log-line {
            margin-bottom: 0.25rem;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-timestamp {
            color: #7BCBCD;
        }
        .log-level-info {
            color: #6DA5B4;
        }
        .log-level-warn {
            color: #C4AC62;
        }
        .log-level-error {
            color: #B05648;
        }
        .log-level-debug {
            color: #BD7BBF;
        }
        .container {
            padding-bottom: 70px;
        }
        .text-white {
            color: #E0E0E0 !important;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container-fluid">
            <a href="index.php" class="navbar-brand">OpenRT</a>
            <span class="navbar-text fw-bold" style="position: absolute; left: 50%; transform: translateX(-50%); color: white;">
                Log Viewer
            </span>   
            <img src="assets/images/openRT.png" alt="OpenRT Logo" class="logo">
        </div>
    </nav>

    <div class="container mt-4">
        <?php if (!$selectedTool): ?>
            <!-- Tool Selection -->
            <div class="row">
                <div class="col">
                    <div class="card">
                        <div class="card-header">
                            Select Tool
                        </div>
                        <div class="card-body">
                            <p class="text-white">Select an openRT tool to view its logs:</p>
                            <div class="d-flex flex-wrap">
                                <?php foreach ($availableTools as $tool): ?>
                                    <a href="?tool=<?php echo urlencode($tool); ?>" class="btn-tool">
                                        <?php echo htmlspecialchars($tool); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php if (empty($availableTools)): ?>
                                <p class="text-muted">No log files found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        
        <?php elseif (!$selectedLog): ?>
            <!-- Log Selection -->
            <div class="row">
                <div class="col">
                    <a href="log_viewer.php" class="btn-back">← Back to Tools</a>
                    <div class="card">
                        <div class="card-header">
                            Logs for <?php echo htmlspecialchars($selectedTool); ?>
                            <small class="text-muted float-end"><?php echo count($logs); ?> log files</small>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($logs)): ?>
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Process ID</th>
                                            <th>Size</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($log['formatted_date']); ?></td>
                                                <td><?php echo htmlspecialchars($log['pid']); ?></td>
                                                <td><?php echo formatFileSize($log['size']); ?></td>
                                                <td>
                                                    <a href="?tool=<?php echo urlencode($selectedTool); ?>&log=<?php echo urlencode($log['filename']); ?>" 
                                                       class="btn-log">View Log</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-muted">No log files found for <?php echo htmlspecialchars($selectedTool); ?>.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        
        <?php else: ?>
            <!-- Log Content -->
            <div class="row">
                <div class="col">
                    <a href="?tool=<?php echo urlencode($selectedTool); ?>" class="btn-back">← Back to <?php echo htmlspecialchars($selectedTool); ?> Logs</a>
                    <div class="card">
                        <div class="card-header">
                            Log: <?php echo htmlspecialchars($selectedLog); ?>
                            <small class="text-muted float-end">
                                <?php 
                                $logPath = '../logs/' . $selectedLog;
                                if (file_exists($logPath)) {
                                    echo formatFileSize(filesize($logPath)) . ' • ' . date('Y-m-d H:i:s', filemtime($logPath));
                                }
                                ?>
                            </small>
                        </div>
                        <div class="card-body">
                            <?php
                            $logPath = '../logs/' . $selectedLog;
                            if (file_exists($logPath) && is_readable($logPath)):
                                $content = file_get_contents($logPath);
                                if ($content !== false):
                                    $lines = explode("\n", $content);
                            ?>
                                <div class="log-content">
                                    <?php foreach ($lines as $line): ?>
                                        <?php if (trim($line)): ?>
                                            <div class="log-line">
                                                <?php
                                                // Color code log levels
                                                $formattedLine = htmlspecialchars($line);
                                                $formattedLine = preg_replace('/(\[.*?\])/', '<span class="log-timestamp">$1</span>', $formattedLine);
                                                $formattedLine = preg_replace('/\[INFO\]/', '<span class="log-level-info">[INFO]</span>', $formattedLine);
                                                $formattedLine = preg_replace('/\[WARN\]/', '<span class="log-level-warn">[WARN]</span>', $formattedLine);
                                                $formattedLine = preg_replace('/\[ERROR\]/', '<span class="log-level-error">[ERROR]</span>', $formattedLine);
                                                $formattedLine = preg_replace('/\[DEBUG\]/', '<span class="log-level-debug">[DEBUG]</span>', $formattedLine);
                                                echo $formattedLine;
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted">Unable to read log file contents.</p>
                            <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted">Log file not found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="fixed-bottom py-3" style="background-color: #12151B; border-top: 1px solid #1E232E;">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-white">OpenRT v<?php echo $openRTVersion; ?></span>
                <span class="text-muted">Log Viewer</span>
            </div>
        </div>
    </footer>

    <!-- Local JavaScript dependencies -->
    <script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
