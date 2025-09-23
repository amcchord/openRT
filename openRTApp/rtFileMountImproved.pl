#!/usr/bin/perl

###############################################################################
# rtFileMountImproved.pl - Improved OpenRT Backup Volume Mount Utility
###############################################################################
#
# DESCRIPTION:
#   This is an improved version of rtFileMount.pl that focuses on efficiency
#   and user control. By default, it mounts only the latest snapshot instead
#   of all snapshots. It uses the new rtLoopManager.pl for proper loop device
#   tracking and cleanup. This version is designed to be more resource-efficient
#   and user-friendly.
#
# USAGE:
#   sudo ./rtFileMountImproved.pl [-cleanup[=agent_name]] [-j] agent_name [snapshot_epoch]
#   sudo ./rtFileMountImproved.pl cleanup                 # Same as -cleanup=1
#
# OPTIONS:
#   -cleanup[=agent_name]  Clean up mounts for specific agent or all if no agent specified
#   -j                     Output results in JSON format
#   agent_name            Name, hostname, or ID of the backup agent
#   snapshot_epoch        Unix timestamp of desired snapshot (optional, defaults to latest)
#
# EXAMPLES:
#   # Mount latest snapshot for agent "server1" (default behavior)
#   sudo ./rtFileMountImproved.pl server1
#
#   # Mount specific snapshot by timestamp
#   sudo ./rtFileMountImproved.pl server1 1634567890
#
#   # Clean up all mounts for agent "server1"
#   sudo ./rtFileMountImproved.pl -cleanup=server1
#
#   # Clean up all mounts
#   sudo ./rtFileMountImproved.pl -cleanup
#
# KEY IMPROVEMENTS:
#   - Defaults to mounting only the latest snapshot (more efficient)
#   - Uses rtLoopManager.pl for proper loop device tracking
#   - Better error handling and user feedback
#   - More efficient resource usage
#   - Cleaner code structure
#
# DIRECTORY STRUCTURE:
#   /rtMount/                      - Base mount directory
#   └── [agent_name]/             - Agent-specific directory
#       └── [snapshot_date]/      - Snapshot-specific directory
#           └── [volume_name]/    - Individual volume mount points
#   /rtMount/zfs_block/           - Temporary ZFS clone mount points
#                                  Contains .datto files and their .vmdk descriptors
#
###############################################################################

use strict;
use warnings;
use JSON;
use File::Path qw(make_path remove_tree);
use POSIX qw(strftime);
use Getopt::Long;
use File::Basename;
use Cwd 'abs_path';

# Global variables
my $debug = 1;
my $json_output = 0;
my $cleanup_agent = '';

# Parse command line options
GetOptions(
    'cleanup:s' => \$cleanup_agent,
    'j' => \$json_output
) or die "Usage: $0 [-cleanup[=agent_name]] [-j] agent_name [snapshot_epoch]\n";

# Handle 'cleanup' as a positional argument
if (!$cleanup_agent && @ARGV > 0 && $ARGV[0] eq 'cleanup') {
    $cleanup_agent = '1';
    shift @ARGV;
}

# Debug print function
sub debug {
    my ($msg) = @_;
    print "DEBUG: $msg\n" if $debug && !$json_output;
}

# Data structure for storing mount information
my $mount_info = {
    status => "success",
    message => "",
    mounts => []
};

# Get script directory
my $script_dir = dirname(abs_path($0));

# Validate root privileges
if ($> != 0) {
    die "This script must be run as root\n";
}

# Define base directories
my $mount_base = "/rtMount";
my $zfs_block_base = "$mount_base/zfs_block";

# Comprehensive cleanup function using rtLoopManager
sub cleanup_mounts {
    my ($base_dir, $agent_name, $is_cleanup_mode) = @_;
    debug("Starting improved cleanup" . ($agent_name ? " for agent: $agent_name" : " for all agents"));
    
    my @cleaned = ();
    
    # Step 1: Unmount all NTFS volumes with verification
    debug("Step 1: Unmounting NTFS volumes");
    my @mounts = `mount | grep $base_dir`;
    my @ntfs_mounts = ();
    
    # Collect NTFS mount points first
    foreach my $mount (@mounts) {
        if ($mount =~ /on\s+(\S+)\s+type\s+fuseblk/) {
            my $mount_point = $1;
            
            # Filter by agent name if specified
            if ($agent_name) {
                next unless $mount_point =~ m|$base_dir/$agent_name|;
            }
            
            push @ntfs_mounts, $mount_point;
        }
    }
    
    # Unmount NTFS volumes with retries
    foreach my $mount_point (@ntfs_mounts) {
        debug("Unmounting NTFS volume: $mount_point");
        
        # Kill any processes using the mount point
        system("fuser -k $mount_point 2>/dev/null");
        sleep(1);
        
        # Try normal unmount first
        my $result = system("umount $mount_point 2>/dev/null");
        if ($result != 0) {
            debug("Normal unmount failed, trying force unmount");
            system("umount -f $mount_point 2>/dev/null");
            sleep(1);
        }
        
        # Verify unmount or use lazy unmount as last resort
        if (`mount | grep -F "$mount_point"`) {
            debug("Mount still active, using lazy unmount");
            system("umount -l $mount_point 2>/dev/null");
        }
        
        push @cleaned, $mount_point;
    }
    
    # Wait for NTFS unmounts to complete
    debug("Waiting for NTFS unmounts to complete");
    sleep(2);
    
    # Step 2: Clean up loop devices and mount sessions using rtLoopManager
    debug("Step 2: Cleaning up loop devices and mount sessions using rtLoopManager");
    my $loop_manager = "$script_dir/rtLoopManager.pl";
    if (-f $loop_manager) {
        # Clean up mount session registry first
        if ($agent_name && $agent_name ne '1') {
            system("perl $loop_manager cleanup-mounts $agent_name >/dev/null 2>&1");
            system("perl $loop_manager cleanup $agent_name >/dev/null 2>&1");
        } else {
            system("perl $loop_manager cleanup-mounts >/dev/null 2>&1");
            system("perl $loop_manager cleanup >/dev/null 2>&1");
        }
    } else {
        debug("Warning: rtLoopManager.pl not found, falling back to manual cleanup");
        # Fallback to manual cleanup
        my @losetup = `losetup -a | grep .datto`;
        
        # Sort loop devices in reverse numerical order for LIFO cleanup
        # Extract loop numbers and sort them descending (higher numbers first)
        my @loop_devices = ();
        foreach my $loop (@losetup) {
            if ($loop =~ /^(\/dev\/loop(\d+)):\s+.*\.datto/) {
                my ($loop_dev, $loop_num) = ($1, $2);
                push @loop_devices, { device => $loop_dev, number => $loop_num };
            }
        }
        
        # Sort by loop device number in descending order (LIFO)
        @loop_devices = sort { $b->{number} <=> $a->{number} } @loop_devices;
        
        foreach my $loop_info (@loop_devices) {
            my $loop_dev = $loop_info->{device};
            debug("Detaching loop device: $loop_dev");
            system("losetup -d $loop_dev 2>/dev/null");
            push @cleaned, $loop_dev;
        }
    }
    
    # Step 3: Clean up ZFS clones with improved timing and verification
    debug("Step 3: Cleaning up ZFS clones");
    my @clones = `zfs list -H -o name | grep mount_`;
    
    # Wait for loop devices to be fully cleaned up
    debug("Waiting for loop device cleanup to complete");
    sleep(3);
    
    foreach my $clone (@clones) {
        chomp($clone);
        # Filter by agent name if specified
        if ($agent_name && $agent_name ne '1') {
            next unless $clone =~ m|/agents/$agent_name/|;
        }
        
        debug("Processing ZFS clone: $clone");
        
        # Get mount point before unmounting
        my $mountpoint = `zfs get -H -o value mountpoint $clone 2>/dev/null`;
        chomp($mountpoint);
        
        # Kill any processes that might be using files in the ZFS mount
        if ($mountpoint && $mountpoint ne '-' && -d $mountpoint) {
            debug("Killing processes using ZFS mount: $mountpoint");
            system("fuser -k $mountpoint 2>/dev/null");
            sleep(2);
        }
        
        # Unmount ZFS clone if mounted
        my $is_mounted = `zfs get -H -o value mounted $clone 2>/dev/null` =~ /yes/;
        if ($is_mounted) {
            debug("Unmounting ZFS clone: $clone");
            system("zfs unmount -f $clone 2>/dev/null");
            sleep(2);
            
            # Verify unmount
            my $still_mounted = `zfs get -H -o value mounted $clone 2>/dev/null` =~ /yes/;
            if ($still_mounted) {
                debug("ZFS clone still mounted, trying aggressive unmount");
                system("fuser -k $mountpoint 2>/dev/null") if $mountpoint;
                sleep(1);
                system("zfs unmount -f $clone 2>/dev/null");
                sleep(2);
            }
        }
        
        # Destroy the clone
        debug("Destroying ZFS clone: $clone");
        my $destroy_result = system("zfs destroy -f $clone 2>/dev/null");
        
        # If destroy failed, try more aggressive cleanup
        if ($destroy_result != 0 || `zfs list -H -o name $clone 2>/dev/null`) {
            debug("Clone destroy failed, trying aggressive cleanup");
            
            # Kill all processes that might be holding references
            if ($mountpoint && $mountpoint ne '-') {
                system("fuser -k $mountpoint 2>/dev/null");
                system("lsof +D $mountpoint 2>/dev/null | awk 'NR>1 {print \$2}' | xargs -r kill -9 2>/dev/null");
            }
            sleep(3);
            
            # Try unmount again
            system("zfs unmount -f $clone 2>/dev/null");
            sleep(2);
            
            # Try recursive destroy
            debug("Attempting recursive destroy");
            system("zfs destroy -R -f $clone 2>/dev/null");
            sleep(1);
            
            # Final check
            if (`zfs list -H -o name $clone 2>/dev/null`) {
                debug("WARNING: Clone $clone could not be destroyed - may require system restart");
            } else {
                debug("Clone $clone successfully destroyed after aggressive cleanup");
            }
        } else {
            debug("Clone $clone destroyed successfully");
        }
        
        push @cleaned, $clone;
    }
    
    # Step 4: Remove mount directories
    debug("Step 4: Removing mount directories");
    if ($agent_name && $agent_name ne '1') {
        my $agent_dir = "$base_dir/$agent_name";
        my $agent_temp_dir = "$base_dir/zfs_block/$agent_name";
        
        # Wait for ZFS operations to complete
        sleep(2);
        
        # Check for remaining mounts and clean them up thoroughly
        my @remaining_mounts = `mount | grep -E "$agent_dir|$agent_temp_dir"`;
        if (@remaining_mounts) {
            debug("Force unmounting remaining mounts");
            foreach my $mount (@remaining_mounts) {
                if ($mount =~ /on\s+(\S+)\s+/) {
                    my $mount_point = $1;
                    debug("Force unmounting: $mount_point");
                    
                    # Kill processes using the mount
                    system("fuser -k $mount_point 2>/dev/null");
                    sleep(1);
                    
                    # Try normal unmount, then force, then lazy
                    system("umount $mount_point 2>/dev/null") ||
                    system("umount -f $mount_point 2>/dev/null") ||
                    system("umount -l $mount_point 2>/dev/null");
                }
            }
            sleep(3);
        }
        
        # Remove directories
        if (-d $agent_dir) {
            debug("Removing directory: $agent_dir");
            system("rm -rf $agent_dir 2>/dev/null");
        }
        if (-d $agent_temp_dir) {
            debug("Removing temporary directory: $agent_temp_dir");
            system("rm -rf $agent_temp_dir 2>/dev/null");
        }
    }
    
    # Output results only in cleanup mode
    if ($is_cleanup_mode) {
        if ($json_output) {
            print encode_json({
                status => "success",
                message => $agent_name ? "Cleanup completed for agent: $agent_name" : "Cleanup completed for all agents",
                cleaned => \@cleaned
            }) . "\n";
            exit 0;
        } else {
            print ($agent_name ? "Cleanup completed for agent: $agent_name\n" : "Cleanup completed for all agents.\n");
        }
    }
}

# Handle cleanup mode
if ($cleanup_agent ne '') {
    cleanup_mounts($mount_base, $cleanup_agent eq '1' ? '' : $cleanup_agent, 1);
    exit 0;
}

# Parse command line arguments
my $agent_name = shift @ARGV;
my $snapshot_epoch = shift @ARGV;

if (!$agent_name) {
    die "Usage: $0 [-cleanup[=agent_name]] [-j] agent_name [snapshot_epoch]\n";
}

# Perform initial cleanup
debug("Performing initial cleanup for agent: $agent_name");
cleanup_mounts($mount_base, $agent_name, 0);

# Create required directories
make_path($mount_base) unless -d $mount_base;
make_path($zfs_block_base) unless -d $zfs_block_base;

# Get agent metadata
debug("Retrieving agent metadata...");
my $metadata_script = "$script_dir/rtMetadata.pl";
if (!-f $metadata_script) {
    die "Cannot find rtMetadata.pl\n";
}

my $metadata_json = `perl "$metadata_script" -j`;
if ($? != 0) {
    die "Failed to get metadata\n";
}

my $metadata;
eval {
    $metadata = decode_json($metadata_json);
};
if ($@) {
    die "Failed to parse metadata JSON: $@\n";
}

# Find agent information
my $agent_info;
my $agent_id_found;
foreach my $agent_id (keys %{$metadata->{agents}}) {
    my $agent = $metadata->{agents}->{$agent_id};
    
    if ($agent->{hostname} eq $agent_name || 
        $agent->{name} eq $agent_name || 
        $agent->{agentId} eq $agent_name) {
        $agent_info = $agent;
        $agent_id_found = $agent_id;
        debug("Found matching agent with ID: $agent_id");
        
        # Ensure volumes is an array
        if ($agent->{volumes}) {
            if (ref($agent->{volumes}) eq 'HASH') {
                my @vol_array;
                foreach my $key (keys %{$agent->{volumes}}) {
                    my $vol = $agent->{volumes}->{$key};
                    push @vol_array, $vol if ref($vol) eq 'HASH';
                }
                $agent->{volumes} = \@vol_array;
            }
        } else {
            $agent->{volumes} = [];
        }
        last;
    }
}

die "Agent '$agent_name' not found in metadata\n" unless $agent_info;

# Get RT pool and setup paths
my $rt_pool = $metadata->{pool_name};
die "No RT pool found in metadata\n" unless $rt_pool;

my $agents_path = $ENV{RT_AGENTS_PATH} || "home/agents";
my $agents_dataset = "$rt_pool/$agents_path";
my $snapshot_path = "$agents_dataset/$agent_name";

if ($agent_id_found && $agent_id_found ne $agent_name) {
    $snapshot_path = "$agents_dataset/$agent_id_found";
}

debug("Using snapshot path: $snapshot_path");

# Get available snapshots
my @snapshots = `zfs list -H -t snapshot -o name $snapshot_path 2>/dev/null`;
chomp(@snapshots);

die "No snapshots found for agent '$agent_name'\n" unless @snapshots;

# Determine target snapshot (default to latest)
my $target_snapshot;
if ($snapshot_epoch) {
    # Find closest snapshot to specified epoch
    my $closest_snapshot;
    my $smallest_diff = undef;
    
    foreach my $snap (@snapshots) {
        if ($snap =~ /\@(\d+)$/) {
            my $snap_time = $1;
            my $diff = abs($snap_time - $snapshot_epoch);
            
            if (!defined($smallest_diff) || $diff < $smallest_diff) {
                $smallest_diff = $diff;
                $closest_snapshot = $snap;
            }
        }
    }
    $target_snapshot = $closest_snapshot;
} else {
    # Use latest snapshot (default behavior)
    $target_snapshot = $snapshots[-1];
}

die "No suitable snapshot found\n" unless $target_snapshot;

debug("Selected snapshot: $target_snapshot");

# Extract snapshot timestamp
my $snap_epoch = ($target_snapshot =~ /\@(\d+)$/)[0] // 'unknown';
my $snapshot_date = $snap_epoch ne 'unknown' ? 
    strftime("%Y-%m-%d_%H-%M-%S", localtime($snap_epoch)) : 
    "unknown";

debug("Snapshot date: $snapshot_date");

# Setup mount points
my $zfs_block_mount = "$zfs_block_base/$agent_name/$snapshot_date";
my $final_mount_base = "$mount_base/$agent_name/$snapshot_date";
make_path($zfs_block_mount);
make_path($final_mount_base);

# Create and mount ZFS clone
my $clone_name = $snapshot_path . "/mount_" . $$ . "_" . $snap_epoch;
debug("Creating ZFS clone: $clone_name");

# Clean up any existing clone with same name
my $existing_clone = `zfs list -H -o name $clone_name 2>/dev/null`;
if ($existing_clone) {
    debug("Destroying existing clone");
    system("zfs unmount -f $clone_name 2>/dev/null");
    system("zfs destroy -f $clone_name 2>/dev/null");
    sleep(1);
}

# Create and mount the clone
system("zfs clone $target_snapshot $clone_name 2>/dev/null");
system("zfs set mountpoint=$zfs_block_mount $clone_name 2>/dev/null");

my $is_mounted = `zfs get -H -o value mounted $clone_name 2>/dev/null` =~ /yes/;
if (!$is_mounted) {
    system("zfs mount $clone_name 2>/dev/null");
}

my $clone_mounted = `mount | grep $zfs_block_mount` ? 1 : 0;
unless ($clone_mounted) {
    die "Failed to mount ZFS clone\n";
}

debug("ZFS clone mounted successfully");

# Initialize snapshot tracking
my $snapshot_info = {
    snapshot => $target_snapshot,
    date => $snapshot_date,
    zfs_clone => $clone_name,
    temp_mount => $zfs_block_mount,
    final_mount => $final_mount_base,
    volumes => []
};

# Function to create VMDK descriptor
sub create_vmdk_descriptor {
    my ($datto_file, $vmdk_path, $size_bytes) = @_;
    
    my $sectors = int($size_bytes / 512);
    
    my $vmdk_content = qq{# Disk DescriptorFile
version=1
encoding="UTF-8"
CID=fffffffe
parentCID=ffffffff
isNativeSnapshot="no"
createType="monolithicFlat"

# Extent description
RW $sectors FLAT "$datto_file" 0

# The Disk Data Base
#DDB

ddb.adapterType = "lsilogic"
ddb.geometry.cylinders = "1024"
ddb.geometry.heads = "255"
ddb.geometry.sectors = "63"
ddb.longContentID = "0123456789abcdef0123456789abcdef"
ddb.virtualHWVersion = "4"};

    if (open(my $fh, '>', $vmdk_path)) {
        print $fh $vmdk_content;
        close($fh);
        return 1;
    }
    return 0;
}

# Process each volume
my $volumes = $agent_info->{volumes} || [];
foreach my $vol (@{$volumes}) {
    my $volume_info = {
        guid => $vol->{guid},
        mountpoint => $vol->{mountpoints},
        filesystem => $vol->{filesystem},
        status => "not_mounted",
        mount_path => "",
        error => "",
        vmdk_path => ""
    };
    
    debug("Processing volume: " . ($vol->{mountpoints} // "unknown"));
    
    my $guid = $vol->{guid};
    my $mountpoint = $vol->{mountpoints};
    my $filesystem = $vol->{filesystem};
    
    unless ($guid && $mountpoint) {
        debug("Skipping volume due to missing information");
        $volume_info->{error} = "Missing required volume information";
        push @{$snapshot_info->{volumes}}, $volume_info;
        next;
    }
    
    # Sanitize mountpoint for directory name
    $mountpoint =~ s/[:\\\/]//g;
    my $mount_path = "$final_mount_base/$mountpoint";
    make_path($mount_path);
    
    # Process .datto file
    my $datto_file = "$zfs_block_mount/$guid.datto";
    if (-f $datto_file) {
        debug("Found .datto file: $datto_file");
        
        # Create VMDK descriptor
        my $vmdk_path = "$zfs_block_mount/$guid.vmdk";
        my $datto_size = -s $datto_file;
        if (create_vmdk_descriptor("$guid.datto", $vmdk_path, $datto_size)) {
            debug("Created VMDK descriptor");
            $volume_info->{vmdk_path} = $vmdk_path;
        }
        
        # Analyze partition layout
        debug("Analyzing partition layout...");
        my $fdisk_output = `fdisk -l "$datto_file" 2>&1`;
        
        my $offset = 0;
        if ($fdisk_output =~ /Sector size.*:\s+(\d+)/i) {
            my $sector_size = $1;
            
            if ($fdisk_output =~ /\.datto1\s+\*?\s*(\d+)\s+\d+\s+\d+/) {
                my $start_sector = $1;
                $offset = $start_sector * $sector_size;
                debug("Calculated offset: $offset bytes");
            } else {
                warn "Could not find partition start sector\n";
                $volume_info->{error} = "Failed to determine partition offset";
                push @{$snapshot_info->{volumes}}, $volume_info;
                next;
            }
        } else {
            warn "Could not determine sector size\n";
            $volume_info->{error} = "Failed to determine sector size";
            push @{$snapshot_info->{volumes}}, $volume_info;
            next;
        }
        
        # Create loop device using rtLoopManager
        my $loop_manager = "$script_dir/rtLoopManager.pl";
        my $loop_result;
        if (-f $loop_manager) {
            debug("Using rtLoopManager to create loop device");
            my $loop_output = `perl $loop_manager create "$datto_file" $offset $agent_name -j 2>/dev/null`;
            eval {
                $loop_result = decode_json($loop_output);
            };
            if ($@ || !$loop_result->{success}) {
                warn "Failed to create loop device using rtLoopManager\n";
                $volume_info->{error} = "Failed to create loop device";
                push @{$snapshot_info->{volumes}}, $volume_info;
                next;
            }
        } else {
            warn "rtLoopManager not found, skipping volume mount\n";
            $volume_info->{error} = "rtLoopManager not available";
            push @{$snapshot_info->{volumes}}, $volume_info;
            next;
        }
        
        my $loop_device = $loop_result->{loop_device};
        debug("Created loop device: $loop_device");
        
        # Mount the volume
        my $mount_cmd = "mount -t ntfs -o ro $loop_device $mount_path";
        debug("Mounting with command: $mount_cmd");
        
        system("umount -f $mount_path 2>/dev/null");
        system($mount_cmd);
        
        # Verify mount
        my $mount_check = `mount | grep $mount_path`;
        if ($mount_check) {
            debug("Volume mounted successfully");
            $volume_info->{status} = "mounted";
            $volume_info->{mount_path} = $mount_path;
        } else {
            warn "Mount verification failed\n" unless $json_output;
            $volume_info->{status} = "failed";
            $volume_info->{error} = "Mount verification failed";
        }
    } else {
        warn "Could not find .datto file for volume $mountpoint\n";
        $volume_info->{error} = "Missing .datto file";
    }
    
    push @{$snapshot_info->{volumes}}, $volume_info;
}

# Add to mount info
push @{$mount_info->{mounts}}, $snapshot_info;

# Register mount session if successful
if ($mount_info->{status} eq 'success' && @{$mount_info->{mounts}}) {
    my $session_data = {
        agent_id => $agent_id_found || $agent_name,
        agent_name => $agent_info->{hostname} || $agent_info->{hostName} || $agent_name,
        snapshot_epoch => $snap_epoch,
        snapshot_date => $snapshot_date,
        snapshot_name => $target_snapshot,
        final_mount => $final_mount_base,
        temp_mount => $zfs_block_mount,
        zfs_clone => $clone_name,
        volumes => $snapshot_info->{volumes}
    };
    
    # Register with rtLoopManager
    my $loop_manager = "$script_dir/rtLoopManager.pl";
    if (-f $loop_manager) {
        my $session_json = encode_json($session_data);
        # Escape quotes for shell command
        $session_json =~ s/'/'"'"'/g;
        system("perl '$loop_manager' register-mount '$session_json' >/dev/null 2>&1");
        debug("Mount session registered with rtLoopManager") if $? == 0;
    }
}

# Output results
if ($json_output) {
    print encode_json($mount_info) . "\n";
} else {
    print "\nSnapshot mount operations completed for $snapshot_date\n";
    print "Files are mounted at: $final_mount_base/\n";
    print "ZFS clone is mounted at: $zfs_block_mount\n";
    print "\nTo clean up mounts, run: $0 -cleanup=$agent_name\n";
}

exit 0;
