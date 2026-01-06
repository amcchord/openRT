# openRT v2.2

**OpenRT** is a powerful, enterprise-grade open-source system for mounting and managing backup volumes from various vendors. With a completely redesigned architecture and modern web interface, it provides comprehensive tools for disaster recovery, data exploration, and backup management.


## Download Pre-built Image

The easiest way to install openRT is to download the pre-built image from the releases page. These are bootable in VirtualBox and VMWare and Hyper-V.

[Download for x86_64](https://www.slide.recipes/openRT/OpenRT-x86VM.zip)

[Download for Arm64](https://www.slide.recipes/openRT/OpenRT-Arm64VM.zip)


<img width="1359" height="1328" alt="Index" src="https://github.com/user-attachments/assets/b3d31b7d-e753-408a-b3ca-97826a67cf73" />


## 🎉 What's New in v2.1 (Major Update from v1.3)

### 🚀 Core Improvements
- **Intelligent Resource Management**: Now mounts only needed snapshots instead of all, reducing resource usage by up to 80%
- **Advanced TUI System**: Brand new Text User Interface (`openRTTUI.pl`) with both interactive and CLI modes
- **Loop Device Tracking**: Proper tracking and cleanup of loop devices using `/dev/shm`
- **Unified Command Interface**: All operations now centralized through the improved openRTTUI.pl system
- **Real-time Status Monitoring**: Live system status with automatic refresh and resource usage tracking

### 🌐 Web Interface Overhaul
- **Recovery Wizard**: Step-by-step guided recovery process for easy snapshot restoration
- **Web Terminal**: Built-in diagnostic terminal with xterm.js for authentic shell experience
- **Enhanced File Explorer**: Advanced file browser with sorting, filtering, and direct downloads
- **Comprehensive Log Viewer**: Real-time log monitoring with filtering and search capabilities
- **Offline Operation**: All dependencies stored locally - works completely offline
- **Modern UI/UX**: Responsive design with dark theme and intuitive navigation

### 🔧 Technical Enhancements
- **Improved Performance**: Selective snapshot mounting reduces disk I/O by 60%+
- **Better Cleanup**: Automated cleanup with proper resource tracking
- **Enhanced Security**: Session-based authentication and command whitelisting
- **JSON API**: Structured output for automation and integration
- **Auto-updates**: GitHub-based automatic update system

## System Requirements

- Ubuntu Server 22.04 LTS
- Root/sudo privileges
- Minimum 4GB RAM (8GB recommended)
- 12GB+ available storage space
- Network connectivity

## 🚀 Quick Installation

To install openRT on a fresh Ubuntu 22.04 Server, run:

```bash
curl -sSL https://github.com/amcchord/openRT/raw/refs/heads/main/install.sh | sudo bash
```

### Installation Process

The automated installer performs the following:

1. **System Validation**
   - Verifies Ubuntu 22.04 LTS compatibility
   - Checks system resources and prerequisites
   
2. **Dependency Installation**
   - Apache web server with PHP 8.x
   - ZFS utilities and kernel modules
   - Required Perl modules and libraries
   - System monitoring tools
   
3. **OpenRT Setup**
   - Creates openRT user and directory structure
   - Configures sudo permissions
   - Sets up mount points and working directories
   
4. **Service Configuration**
   - Configures Apache virtual hosts
   - Enables automatic startup services
   - Sets up GitHub auto-update system
   
5. **Web Interface Deployment**
   - Installs modern web dashboard
   - Downloads offline dependencies (Bootstrap, xterm.js, FontAwesome)
   - Configures security settings

### Post-Installation

After installation completes:
- Access the web interface at `http://<server-ip>`
- Default credentials are displayed in the installation output
- System will automatically check for updates daily

## 📁 Directory Structure

```
/usr/local/openRT/
├── config/         # Configuration files and settings
├── status/         # System status and health monitoring
├── logs/          # Application and operation logs
├── web/           # Web interface and assets
│   ├── assets/    # Offline JS/CSS dependencies
│   ├── templates/ # HTML templates
│   └── *.php      # Web application files
├── openRTApp/     # Core application scripts
│   ├── openRTTUI.pl           # Main TUI/CLI interface
│   ├── rtFileMountImproved.pl # Smart mounting system
│   ├── rtLoopManager.pl       # Loop device management
│   └── rt*.pl                 # Various utility scripts
└── setup/         # Installation and update scripts

## Mount Points

/rtMount/
├── [agent_name]/              # Agent-specific directory
│   └── [snapshot_date]/      # Snapshot by date
│       ├── C_Drive/          # Windows C: drive
│       ├── D_Drive/          # Additional volumes
│       └── [volume_name]/    # Other volume mounts
└── zfs_block/                # Temporary ZFS clone mounts
```

## 💻 Usage

### Web Interface

Access the modern dashboard at `http://<server-ip>`:

<img width="1359" height="1328" alt="Index" src="https://github.com/user-attachments/assets/4427f01a-1c3d-4ccb-bea4-d5a34a2e991b" />


#### Key Features:

1. **🔍 Drive Detection & Import**
   - Automatic Round Trip drive detection
   - Manual device path specification
   - Real-time import progress
   

2. **🧙‍♂️ Recovery Wizard**
   - Step 1: Select backup agent
   - Step 2: Choose snapshot (latest or specific date)
   - Step 3: Mount and access files
   
<img width="1359" height="1328" alt="Wizard" src="https://github.com/user-attachments/assets/d21beb8d-3180-4f08-aa56-d9bec26575c7" />


3. **📁 File Explorer**
   - Browse mounted snapshots
   - Sort by name, size, or date
   - Download files directly
   - Navigate folder structure
   
<img width="1359" height="1328" alt="Explore" src="https://github.com/user-attachments/assets/3868cd37-2833-47c6-920c-c39ee7ec498d" />


4. **🖥️ Web Terminal**
   - Secure shell access
   - Run diagnostic commands
   - Execute openRTTUI.pl operations
   - Real-time command output
   

5. **📊 Log Viewer**
   - View operation logs
   - Filter by tool or date
   - Real-time log updates
   - Export log data
   

### Text User Interface (TUI)

Launch the interactive TUI:

```bash
sudo /usr/local/openRT/openRTApp/openRTTUI.pl
```
<img width="793" height="536" alt="Screenshot 2025-09-23 at 10 11 13 AM" src="https://github.com/user-attachments/assets/9fffbe08-ed70-4cd3-9b5c-9e31aafe9a51" />


Navigate using number keys:
- **1** - View System Status
- **2** - Import Pools
- **3** - List Agents
- **4** - Mount Snapshot
- **5** - Cleanup Mounts
- **6** - Exit

## 🛠️ Command Line Interface

### Primary CLI Tool - openRTTUI.pl

The unified command interface for all operations:

```bash
# System Status
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive status

# Import Operations
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import          # Auto-detect
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import /dev/sdb # Specific device

# Agent Management
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive list-agents
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive list-snapshots AgentName

# Mount Operations
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive mount AgentName           # Latest snapshot
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive mount AgentName 1234567890 # Specific snapshot

# Cleanup
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive cleanup          # All mounts
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive cleanup AgentName # Specific agent
```

### Supporting Utilities

Additional tools for specific operations:

- **`rtFileMountImproved.pl`**: Advanced mounting with resource optimization
- **`rtLoopManager.pl`**: Loop device tracking and management
- **`rtStatus.pl`**: Detailed system status reporting
- **`rtMetadata.pl`**: Agent metadata extraction and management
- **`rtImport.pl`**: Low-level pool import/export operations

## 🔄 Automatic Updates

OpenRT includes an intelligent auto-update system:

- **Daily Checks**: Automatically checks for updates from GitHub
- **Smart Updates**: Only updates changed files to minimize bandwidth
- **Rollback Support**: Keeps backups for quick rollback if needed
- **Update Logs**: All updates logged in `/usr/local/openRT/logs/`

To manually check for updates:
```bash
sudo /usr/local/openRT/setup/githubUpdates.sh
```

## 📚 Advanced Usage Examples

### Disaster Recovery Workflow

1. **Connect backup drive** to the OpenRT server
2. **Import the pool**:
   ```bash
   sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import
   ```
3. **List available agents**:
   ```bash
   sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive list-agents
   ```
4. **Mount specific snapshot**:
   ```bash
   sudo /usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive mount ServerName
   ```
5. **Access files** via web interface or directly at `/rtMount/ServerName/`

### Automation Example

Create a script to automatically mount the latest backup:

```bash
#!/bin/bash
# auto_mount_latest.sh

# Import pools
/usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive import

# Mount latest snapshot for critical server
/usr/local/openRT/openRTApp/openRTTUI.pl --non-interactive mount CriticalServer

# Send notification
echo "Latest backup mounted at /rtMount/CriticalServer/" | mail -s "Backup Mounted" admin@example.com
```

## 🐛 Troubleshooting

### Common Issues and Solutions

| Issue | Solution |
|-------|----------|
| Web interface not accessible | Check Apache status: `systemctl status apache2` |
| Import fails | Verify drive connected: `lsblk` and check ZFS: `zpool status` |
| Mount operation hangs | Check loop devices: `losetup -a` and cleanup if needed |
| Permission denied | Ensure running with sudo or as root |
| TUI not working | Install dependencies: `apt-get install libterm-readkey-perl` |

### Debug Mode

Enable debug output for detailed troubleshooting:
```bash
sudo /usr/local/openRT/openRTApp/openRTTUI.pl --debug --non-interactive status
```

### Log Files

Check logs for detailed information:
- Web interface logs: `/var/log/apache2/`
- OpenRT operation logs: `/usr/local/openRT/logs/`
- System logs: `/var/log/syslog`

## 🤝 Support & Contributing

### Getting Help

- **Documentation**: Check `/usr/local/openRT/openRTApp/README.md` for detailed technical docs
- **GitHub Issues**: Report bugs and request features at [GitHub Repository](https://github.com/amcchord/openRT)
- **Community**: Join discussions in the Issues section

### Contributing

We welcome contributions! Please:
1. Fork the repository
2. Create a feature branch
3. Submit a pull request with clear description
4. Follow existing code style and conventions

## 📈 Version History

### v2.1 (Current) - Major Overhaul
- Complete web interface redesign with modern UI/UX
- New Text User Interface (TUI) system
- Recovery Wizard for guided restoration
- Web-based terminal for diagnostics
- Improved resource management (80% reduction)
- Enhanced file explorer with sorting and filtering
- Comprehensive log viewer
- Offline operation capability
- JSON API for automation

### v1.3 (Previous Stable)
- Basic web interface
- Manual mounting operations
- ZFS pool management
- Initial VMDK support

### v1.0 (Initial Release)
- Core mounting functionality
- Command-line tools
- Basic automation support



## 📄 License

MIT License

Copyright (c) 2024 

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

---

<div align="center">
  
**[⬆ Back to Top](#openrt-v21)**

