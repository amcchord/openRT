<?php
/**
 * Web-based terminal for OpenRT diagnostics
 * This provides a secure terminal interface for running diagnostic commands
 */

session_start();

// Generate session token for security
if (!isset($_SESSION['terminal_token'])) {
    $_SESSION['terminal_token'] = bin2hex(random_bytes(32));
}

// Handle command execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
    header('Content-Type: application/json');
    
    // Verify token
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['terminal_token']) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    $command = $_POST['command'];
    
    // Get or initialize the current working directory
    if (!isset($_SESSION['cwd'])) {
        $_SESSION['cwd'] = '/usr/local/openRT/openRTApp';
    }
    
    // Handle built-in shell commands
    if (trim($command) === '') {
        echo json_encode(['output' => '', 'return_code' => 0]);
        exit;
    }
    
    // Handle cd command specially to maintain directory state
    if (preg_match('/^cd\s*(.*)$/', trim($command), $matches)) {
        $target_dir = trim($matches[1]);
        
        if ($target_dir === '' || $target_dir === '~') {
            $target_dir = '/root';
        } elseif ($target_dir === '-') {
            $target_dir = $_SESSION['prev_cwd'] ?? $_SESSION['cwd'];
        } elseif (!str_starts_with($target_dir, '/')) {
            // Relative path
            $target_dir = $_SESSION['cwd'] . '/' . $target_dir;
        }
        
        // Normalize the path
        $target_dir = realpath($target_dir);
        
        if ($target_dir && is_dir($target_dir)) {
            $_SESSION['prev_cwd'] = $_SESSION['cwd'];
            $_SESSION['cwd'] = $target_dir;
            echo json_encode([
                'output' => '',
                'return_code' => 0,
                'cwd' => $target_dir
            ]);
        } else {
            echo json_encode([
                'output' => "cd: no such file or directory: " . trim($matches[1]),
                'return_code' => 1,
                'cwd' => $_SESSION['cwd']
            ]);
        }
        exit;
    }
    
    // Handle pwd command
    if (trim($command) === 'pwd') {
        echo json_encode([
            'output' => $_SESSION['cwd'],
            'return_code' => 0,
            'cwd' => $_SESSION['cwd']
        ]);
        exit;
    }
    
    // Execute command as root with full bash shell in the current directory
    $output = [];
    $return_var = 0;
    
    // Build the full command with proper environment
    $full_command = sprintf(
        'cd %s && sudo -i bash -c %s 2>&1',
        escapeshellarg($_SESSION['cwd']),
        escapeshellarg("cd " . escapeshellarg($_SESSION['cwd']) . " && " . $command)
    );
    
    exec($full_command, $output, $return_var);
    
    echo json_encode([
        'output' => implode("\n", $output),
        'return_code' => $return_var,
        'cwd' => $_SESSION['cwd']
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenRT Terminal - Diagnostic Tool</title>
    <link href="assets/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <link href="assets/xterm/xterm.css" rel="stylesheet">
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
            border-bottom: 1px solid #1E232E;
            padding: 1rem;
        }
        .navbar-brand {
            color: #EDEDED !important;
            font-size: 1.5rem;
        }
        .terminal-container {
            flex: 1;
            padding: 1rem;
            display: flex;
            flex-direction: column;
        }
        #terminal {
            flex: 1;
            background-color: #191D27;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #354C4B;
        }
        .back-btn {
            position: absolute;
            right: 1rem;
            top: 1rem;
        }
        .info-panel {
            background-color: #1E232E;
            border-radius: 5px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .info-panel h5 {
            color: #6DA5B4;
            margin-bottom: 0.5rem;
        }
        .info-panel p {
            margin-bottom: 0.25rem;
            color: #adb5bd;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <span class="navbar-brand">OpenRT Terminal</span>
        <a href="index.php" class="btn btn-secondary back-btn">Back to Main</a>
    </nav>
    
    <div class="terminal-container">
        <div class="info-panel">
            <h5>Full Root Shell - Diagnostic Terminal</h5>
            <p><strong>WARNING:</strong> This is a complete bash shell with full root access for diagnostic purposes.</p>
            <p>You can run ANY Linux command including: mount, umount, zfs, zpool, systemctl, etc.</p>
            <p>Starting directory: <code>/usr/local/openRT/openRTApp</code></p>
            <p>Type <code>help</code> for common diagnostic commands.</p>
        </div>
        <div id="terminal"></div>
    </div>
    
    <script src="assets/xterm/xterm.min.js"></script>
    <script src="assets/xterm/xterm-addon-fit.js"></script>
    <script>
        const term = new Terminal({
            cursorBlink: true,
            fontSize: 14,
            fontFamily: 'Consolas, Monaco, monospace',
            theme: {
                background: '#000000',
                foreground: '#00ff00'
            }
        });
        
        const fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        
        term.open(document.getElementById('terminal'));
        fitAddon.fit();
        
        window.addEventListener('resize', () => fitAddon.fit());
        
        let currentLine = '';
        let currentDir = '/usr/local/openRT/openRTApp';
        const token = '<?php echo $_SESSION['terminal_token']; ?>';
        
        function getPrompt() {
            const shortDir = currentDir.replace(/^\/usr\/local\/openRT/, '~openRT');
            return `root@openrt:${shortDir}# `;
        }
        
        term.writeln('OpenRT Diagnostic Terminal (Full Root Shell)');
        term.writeln('Working directory: /usr/local/openRT/openRTApp');
        term.writeln('WARNING: This is a full root shell - use with caution!');
        term.writeln('');
        term.write(getPrompt());
        
        term.onData(data => {
            if (data === '\r') { // Enter key
                term.writeln('');
                if (currentLine.trim()) {
                    executeCommand(currentLine.trim());
                }
                currentLine = '';
            } else if (data === '\u007F') { // Backspace
                if (currentLine.length > 0) {
                    currentLine = currentLine.slice(0, -1);
                    term.write('\b \b');
                }
            } else if (data === '\u0003') { // Ctrl+C
                term.writeln('^C');
                currentLine = '';
                term.write(getPrompt());
            } else {
                currentLine += data;
                term.write(data);
            }
        });
        
        async function executeCommand(command) {
            if (command === 'help') {
                term.writeln('OpenRT Diagnostic Shell - Full Root Access');
                term.writeln('');
                term.writeln('This is a complete bash shell running as root.');
                term.writeln('You can run ANY Linux command for diagnostics.');
                term.writeln('');
                term.writeln('Common diagnostic commands:');
                term.writeln('  System: ls, pwd, cd, df, mount, ps, free, top, htop');
                term.writeln('  Network: ip, netstat, ss, ping, nslookup');
                term.writeln('  Storage: fdisk -l, lsblk, blkid, parted -l');
                term.writeln('  ZFS: zfs list, zpool status, zpool import');
                term.writeln('  Logs: journalctl, dmesg, tail -f /var/log/*');
                term.writeln('  OpenRT: ./openRTTUI.pl [command] (in interactive mode)');
                term.writeln('');
                term.writeln('Examples:');
                term.writeln('  ./openRTTUI.pl status');
                term.writeln('  ./openRTTUI.pl list-agents');
                term.writeln('  zpool import -f rtPool-12345');
                term.writeln('  mount /dev/sdb1 /mnt/temp');
                term.write(getPrompt());
                return;
            }
            
            if (command === 'clear') {
                term.clear();
                term.write(getPrompt());
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('command', command);
                formData.append('token', token);
                
                const response = await fetch('terminal.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                // Update current directory if provided
                if (result.cwd) {
                    currentDir = result.cwd;
                }
                
                if (result.output) {
                    // Split output by newlines and write each line separately
                    const lines = result.output.split('\n');
                    lines.forEach(line => {
                        if (line.length > 0) {
                            term.writeln(line);
                        } else {
                            term.writeln(''); // Empty line
                        }
                    });
                }
                if (result.return_code !== 0 && result.output) {
                    // Only show exit code if there was actual output
                    if (result.output.trim() !== '') {
                        term.writeln(`[Exit code: ${result.return_code}]`);
                    }
                }
            } catch (error) {
                term.writeln(`Error: ${error.message}`);
            }
            
            term.write(getPrompt());
        }
    </script>
</body>
</html>
