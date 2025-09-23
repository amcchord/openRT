# OpenRT Web Interface Overhaul

## Overview
The OpenRT web interface has been completely overhauled to meet the following requirements:
- **Offline Operation**: All scripts and dependencies are stored locally for use without internet access
- **openRTTUI.pl Integration**: All round trip operations now use the openRTTUI.pl script exclusively
- **Enhanced Functionality**: New features including manual detection, import wizard, and web terminal

## Key Changes

### 1. Offline-Ready Dependencies
- Downloaded and stored xterm.js locally in `/usr/local/openRT/web/assets/xterm/`
- All Bootstrap, FontAwesome, and custom fonts already stored locally
- No external CDN dependencies - fully functional without internet

### 2. Main Page (`index.php`)
**New Features:**
- **Manual Round Trip Detection**: Button to explicitly check for attached Round Trip drives
- **Custom Import Path**: Allow users to specify a device path (e.g., /dev/sdb) for targeted import
- **Real-time Status Display**: Shows current system status with automatic refresh
- **Quick Actions Grid**: Clean card-based interface for all major operations

**All Operations via openRTTUI.pl:**
- Status checks: `openRTTUI.pl --non-interactive status`
- Import pools: `openRTTUI.pl --non-interactive import [path]`
- Cleanup: `openRTTUI.pl --non-interactive cleanup`

### 3. Recovery Wizard (`wizard.php`)
**Complete Rewrite as 3-Step Wizard:**
1. **Select Agent**: Browse and select from available backup agents
2. **Choose Snapshot**: Pick specific snapshot or use latest
3. **Mount & Access**: Review selection and perform mount operation

**Features:**
- Visual progress indicator
- Clean step-by-step interface
- Fallback to raw output display when parsing fails
- Direct integration with openRTTUI.pl commands

### 4. Web-Based Terminal (`terminal.php`)
**New Diagnostic Tool:**
- Secure web-based shell for diagnostics
- Limited to safe system commands
- Full support for openRTTUI.pl operations
- Session-based security tokens
- xterm.js for authentic terminal experience

**Allowed Commands:**
- System utilities: ls, pwd, date, df, mount, ps, free, etc.
- ZFS commands: zfs list, zpool status
- All openRTTUI.pl commands
- 10-second timeout for command execution

### 5. Updated PHP Scripts
All backend scripts now use openRTTUI.pl:

- **mount_agent.php**: Uses `openRTTUI.pl --non-interactive mount [agent]`
- **unmount_all.php**: Uses `openRTTUI.pl --non-interactive cleanup`
- **check_mount.php**: Uses `openRTTUI.pl --non-interactive status`
- **get_status.php**: Parses openRTTUI.pl status output
- **import_pool.php**: Handles import/export via openRTTUI.pl
- **get_metadata.php**: Uses `openRTTUI.pl --non-interactive list-agents`

### 6. Preserved Features
- **File Explorer** (`explore.php`): Browse mounted snapshots
- **Log Viewer** (`log_viewer.php`): View system logs
- **Download** (`download.php`): Download files from mounted snapshots
- **Automount Toggle**: Enable/disable automatic pool import

## Usage Guide

### Basic Workflow

1. **Check for Round Trip Drive**
   - Navigate to main page
   - Click "Check for Round Trip" button
   - System will scan for attached drives

2. **Import Pool**
   - Option A: Click "Import Pool" for automatic detection
   - Option B: Enter specific device path (e.g., /dev/sdb) for targeted import
   - System uses openRTTUI.pl to import ZFS pools

3. **Recovery/Mount Snapshots**
   - Click "Launch Recovery Wizard"
   - Select agent → Choose snapshot → Mount
   - Browse files via "Explore Files" or direct web access

4. **Diagnostics**
   - Click "Open Terminal" for web-based shell
   - Run commands like:
     - `openRTTUI.pl status`
     - `openRTTUI.pl list-agents`
     - `zpool status`
     - `df -h`

### Security Notes

- Web terminal restricts commands to safe operations
- Session tokens prevent unauthorized command execution
- All operations require appropriate system permissions
- openRTTUI.pl commands run with sudo automatically

## File Structure

```
/usr/local/openRT/web/
├── index.php           # Main control panel
├── wizard.php          # Recovery wizard (agent → snapshot → mount)
├── terminal.php        # Web-based diagnostic terminal
├── explore.php         # File browser for mounted snapshots
├── assets/
│   ├── xterm/         # Terminal emulator (offline)
│   ├── bootstrap/     # Bootstrap framework (offline)
│   ├── fontawesome/   # Icons (offline)
│   └── fonts/         # Custom fonts (offline)
└── [Various PHP backends using openRTTUI.pl]
```

## Troubleshooting

### Terminal Not Working
- Ensure xterm.js files are in `/usr/local/openRT/web/assets/xterm/`
- Check browser console for JavaScript errors
- Verify PHP session support is enabled

### Import/Export Issues
- Check openRTTUI.pl is executable: `chmod +x /usr/local/openRT/openRTApp/openRTTUI.pl`
- Verify sudo permissions for web user
- Review output in web terminal for detailed errors

### No Agents Showing
- Ensure pool is imported first
- Check `openRTTUI.pl --non-interactive status` output
- Verify metadata files exist in mounted pools

## Technical Details

### openRTTUI.pl Integration
All round trip operations are now centralized through the openRTTUI.pl script:
- Consistent error handling
- Unified command interface
- Proper cleanup and resource management
- Native ZFS pool handling

### Offline Operation
The interface no longer requires internet access:
- All JavaScript libraries stored locally
- CSS frameworks included in assets
- No CDN dependencies
- Self-contained web application

### Session Security
- PHP sessions for terminal authentication
- Token-based command execution
- Command whitelisting for safety
- Automatic timeout protection

---
*OpenRT Web Interface Overhaul - Designed for offline operation with complete openRTTUI.pl integration*
