# openRTApp - Improved RoundTrip Drive Management System

A completely redesigned and improved collection of tools for managing RoundTrip drives and accessing their data. This version focuses on efficiency, user-friendliness, and proper resource management.

## 🚀 What's New in the Improved Version

### Key Improvements
- **🎯 Efficient Resource Usage**: Mounts only the latest snapshot by default instead of all snapshots
- **🔧 Loop Device Tracking**: Proper tracking and cleanup of loop devices using `/dev/shm`
- **🖥️ User-Friendly TUI**: Interactive text interface for easy navigation and management
- **⚡ CLI Support**: Non-interactive command-line interface for automation
- **🧹 Better Cleanup**: Orderly removal of all resources with proper tracking
- **📊 Clear Status Display**: Real-time system status and resource usage information

### Problem Solved
The original system was resource-heavy and mounted ALL snapshots for each agent, which could consume significant disk space and system resources. The improved version is selective and efficient.

## 📋 Quick Start Guide

### 1. Check System Status
```bash
sudo ./openRTTUI.pl --non-interactive status
```

### 2. Launch Interactive Interface
```bash
sudo ./openRTTUI.pl
```

### 3. Mount Latest Snapshot for an Agent
```bash
sudo ./openRTTUI.pl --non-interactive mount LabPC
```

### 4. Clean Up All Mounts
```bash
sudo ./openRTTUI.pl --non-interactive cleanup
```

## 🛠️ Core Components

### Main TUI Application
- **`openRTTUI.pl`** - Main interactive interface and CLI controller
  - Interactive menu system for easy navigation
  - Non-interactive CLI mode for automation
  - Real-time status display
  - Agent and snapshot browsing

### Improved Core Scripts
- **`rtFileMountImproved.pl`** - Efficient snapshot mounting (latest only by default)
- **`rtLoopManager.pl`** - Loop device tracking and management system

### Original Scripts (Still Available)
- **`rtStatus.pl`** - System status checker
- **`rtMetadata.pl`** - Agent metadata extraction  
- **`rtImport.pl`** - Pool import/export operations
- **`rtFileMount.pl`** - Original mount script (deprecated, use improved version)

## 🎮 Interactive TUI Usage

Launch the interactive interface:
```bash
sudo ./openRTTUI.pl
```

### Main Menu Options:
1. **System Status** - View current system state, drives, and pools
2. **Import Pools** - Import available ZFS pools from connected drives
3. **List Agents** - Browse all available backup agents
4. **Mount Snapshot** - Select and mount specific agent snapshots
5. **Cleanup Mounts** - Clean up mounted snapshots and resources
6. **Exit** - Quit the application

### Navigation:
- Use number keys to select menu options
- Follow prompts for agent and snapshot selection
- Press any key to continue when prompted

## ⌨️ Command Line Interface

### System Management
```bash
# Check system status (JSON output)
sudo ./openRTTUI.pl --non-interactive status

# Import all available pools
sudo ./openRTTUI.pl --non-interactive import

# List all agents with details
sudo ./openRTTUI.pl --non-interactive list-agents
```

### Snapshot Operations
```bash
# Mount latest snapshot for an agent
sudo ./openRTTUI.pl --non-interactive mount agent_name

# Mount specific snapshot by timestamp
sudo ./openRTTUI.pl --non-interactive mount agent_name 1634567890

# Clean up mounts for specific agent
sudo ./openRTTUI.pl --non-interactive cleanup agent_name

# Clean up all mounts
sudo ./openRTTUI.pl --non-interactive cleanup
```

## 🔧 Loop Device Management

The improved system includes proper loop device tracking:

```bash
# List all tracked loop devices
sudo ./rtLoopManager.pl list -j

# Create a tracked loop device
sudo ./rtLoopManager.pl create /path/to/file.datto 1048576 agent_name

# Clean up loop devices for specific agent
sudo ./rtLoopManager.pl cleanup agent_name

# Clean up all tracked loop devices
sudo ./rtLoopManager.pl cleanup
```

## 📁 Directory Structure

```
/rtMount/                      # Base mount directory
├── [agent_name]/             # Agent-specific directories
│   └── [snapshot_date]/      # Snapshot-specific directories
│       └── [volume_name]/    # Individual volume mount points
└── zfs_block/               # Temporary ZFS clone mount points
    └── [agent_name]/        # Contains .datto files and .vmdk descriptors
        └── [snapshot_date]/
```

## 🔍 Resource Tracking

### Loop Device Registry
- Location: `/dev/shm/openrt_loop_devices.json`
- Tracks: loop device, file path, offset, process ID, agent, timestamp
- Automatic cleanup of stale entries

### Mount Tracking
- ZFS clones are properly tracked and cleaned up
- NTFS volumes are unmounted in correct order
- Directories are removed after unmounting

## 🚨 Migration from Original System

### Before Using Improved System:
1. Clean up any existing mounts from the original system:
   ```bash
   sudo ./rtFileMount.pl -cleanup
   ```

2. Verify no loop devices are left behind:
   ```bash
   losetup -a | grep .datto
   ```

### Key Differences:
- **Default Behavior**: Improved system mounts only the latest snapshot
- **Resource Usage**: Much lower memory and disk usage
- **Cleanup**: Automatic and thorough cleanup of all resources
- **User Interface**: Interactive TUI for easy management

## 🔐 Security and Requirements

### Prerequisites
- Root/sudo access required for all operations
- ZFS utilities installed and functional
- Perl 5.10 or higher with required modules

### Auto-Installation
The system automatically installs required Perl modules:
- `JSON` - For data interchange
- `Term::ReadKey` - For interactive input (TUI only)
- `PHP::Serialization` - For agent metadata parsing

## 🐛 Troubleshooting

### Common Issues

**"No pools available for import"**
- Ensure RT drive is properly connected
- Check drive detection: `sudo ./openRTTUI.pl --non-interactive status`

**"Agent not found"**
- Verify pool is imported: Check system status
- List available agents: `sudo ./openRTTUI.pl --non-interactive list-agents`

**Mount failures**
- Check available disk space
- Verify NTFS support is installed: `sudo apt-get install ntfs-3g`
- Review loop device availability: `sudo ./rtLoopManager.pl list`

**Resource cleanup**
- Always use the cleanup command: `sudo ./openRTTUI.pl --non-interactive cleanup`
- Check for remaining loop devices: `losetup -a`
- Manual cleanup if needed: `sudo ./rtLoopManager.pl cleanup`

## 📈 Performance Benefits

### Resource Usage Comparison
| Operation | Original System | Improved System |
|-----------|----------------|-----------------|
| Default mount | All snapshots | Latest snapshot only |
| Loop devices | Manual tracking | Automatic registry |
| Cleanup | Manual process | Automated cleanup |
| User interface | CLI only | TUI + CLI |
| Resource usage | High | Optimized |

### Typical Usage Scenario
- **Original**: Mounting agent with 10 snapshots = 10 ZFS clones + 40+ loop devices
- **Improved**: Mounting same agent = 1 ZFS clone + 4 loop devices

## 🔄 Environment Variables

The improved system respects all original environment variables:

```bash
# Use specific pool name
export RT_POOL_NAME="customPool"

# Match pools with custom pattern  
export RT_POOL_PATTERN="^backup.*"

# Specify custom agents path
export RT_AGENTS_PATH="data/agents"

# Export/import all pools, not just RT pools
export RT_EXPORT_ALL=1
```

## 🎯 Best Practices

1. **Always use cleanup** after mounting operations
2. **Use the TUI** for interactive exploration and learning
3. **Use CLI mode** for automation and scripting
4. **Monitor resources** with the status command
5. **Mount selectively** - only what you need to examine

---

## Version Information
#Version Information VER 2.0