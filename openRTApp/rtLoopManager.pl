#!/usr/bin/perl

###############################################################################
# rtLoopManager.pl - OpenRT Loop Device Management Utility
###############################################################################
#
# DESCRIPTION:
#   This script manages loop devices for OpenRT backup operations. It maintains
#   a registry of created loop devices in /dev/shm for orderly creation and
#   cleanup. This ensures we can track all loop devices we create and clean
#   them up properly even if other processes crash.
#
# USAGE:
#   sudo ./rtLoopManager.pl create <file_path> [offset]
#   sudo ./rtLoopManager.pl destroy <loop_device>
#   sudo ./rtLoopManager.pl cleanup [agent_name]
#   sudo ./rtLoopManager.pl list
#   sudo ./rtLoopManager.pl register-mount <json_session_data>
#   sudo ./rtLoopManager.pl unregister-mount <session_id>
#   sudo ./rtLoopManager.pl list-mounts [agent_name]
#   sudo ./rtLoopManager.pl cleanup-mounts [agent_name]
#
# OPTIONS:
#   create          Create a new loop device for the specified file
#   destroy         Destroy a specific loop device
#   cleanup         Clean up all tracked loop devices or for specific agent
#   list            List all tracked loop devices
#   register-mount  Register a new mount session
#   unregister-mount Unregister a mount session
#   list-mounts     List all active mount sessions
#   cleanup-mounts  Clean up mount session registry
#   -j              Output results in JSON format
#
# EXAMPLES:
#   # Create loop device for a .datto file
#   sudo ./rtLoopManager.pl create /path/to/volume.datto 1048576
#
#   # Destroy specific loop device
#   sudo ./rtLoopManager.pl destroy /dev/loop15
#
#   # Clean up all loop devices for an agent
#   sudo ./rtLoopManager.pl cleanup agent_name
#
#   # List all tracked loop devices
#   sudo ./rtLoopManager.pl list -j
#
# REGISTRY FORMAT:
#   The registry is stored as JSON in /dev/shm/openrt_loop_devices.json
#   Each entry contains: loop_device, file_path, offset, pid, agent, timestamp
#
# REQUIREMENTS:
#   - Root privileges
#   - Perl JSON module
#   - losetup command
#
###############################################################################

use strict;
use warnings;
use JSON;
use POSIX qw(strftime);
use Getopt::Long;
use File::Basename;
use Fcntl qw(:flock LOCK_EX LOCK_UN);

# Constants
my $REGISTRY_FILE = "/dev/shm/openrt_loop_devices.json";
my $LOCK_FILE = "/dev/shm/openrt_loop_devices.lock";
my $MOUNT_REGISTRY_FILE = "/dev/shm/openrt_mount_sessions.json";
my $MOUNT_LOCK_FILE = "/dev/shm/openrt_mount_sessions.lock";

# Global variables
my $json_output = 0;
my $debug = 0;

# Parse command line options
GetOptions(
    'j|json' => \$json_output,
    'd|debug' => \$debug
) or die "Usage: $0 [create|destroy|cleanup|list] [options]\n";

# Debug output function
sub debug_msg {
    my ($msg) = @_;
    print STDERR "DEBUG: $msg\n" if $debug && !$json_output;
}

# Output function that handles both JSON and text
sub output_result {
    my ($success, $message, $data) = @_;
    $data //= {};
    
    if ($json_output) {
        my $result = {
            success => $success ? JSON::true : JSON::false,
            message => $message,
            %$data
        };
        print encode_json($result) . "\n";
    } else {
        print "$message\n";
        if ($data->{devices} && ref($data->{devices}) eq 'ARRAY') {
            foreach my $device (@{$data->{devices}}) {
                printf("  %-12s %s (offset: %d, agent: %s, pid: %d)\n",
                    $device->{loop_device},
                    $device->{file_path},
                    $device->{offset} // 0,
                    $device->{agent} // 'unknown',
                    $device->{pid} // 0
                );
            }
        }
    }
}

# Error handling function
sub handle_error {
    my ($message) = @_;
    output_result(0, $message);
    exit 1;
}

# Check root privileges
if ($> != 0) {
    handle_error("This script must be run as root");
}

# File locking functions
sub acquire_lock {
    my $lock_fh;
    if (!open($lock_fh, '>', $LOCK_FILE)) {
        handle_error("Cannot create lock file: $!");
    }
    if (!flock($lock_fh, LOCK_EX)) {
        handle_error("Cannot acquire lock: $!");
    }
    return $lock_fh;
}

sub release_lock {
    my ($lock_fh) = @_;
    flock($lock_fh, LOCK_UN) if $lock_fh;
    close($lock_fh) if $lock_fh;
    unlink($LOCK_FILE);
}

# Registry management functions
sub load_registry {
    my $registry = {};
    
    if (-f $REGISTRY_FILE) {
        if (open(my $fh, '<', $REGISTRY_FILE)) {
            my $content = do { local $/; <$fh> };
            close($fh);
            
            eval {
                $registry = decode_json($content);
            };
            if ($@) {
                debug_msg("Warning: Failed to parse registry, starting fresh: $@");
                $registry = {};
            }
        }
    }
    
    # Clean up stale entries (processes that no longer exist)
    foreach my $loop_dev (keys %$registry) {
        my $entry = $registry->{$loop_dev};
        if ($entry->{pid} && !kill(0, $entry->{pid})) {
            debug_msg("Removing stale entry for $loop_dev (pid $entry->{pid} no longer exists)");
            delete $registry->{$loop_dev};
        }
    }
    
    return $registry;
}

sub save_registry {
    my ($registry) = @_;
    
    if (open(my $fh, '>', $REGISTRY_FILE)) {
        print $fh encode_json($registry);
        close($fh);
        return 1;
    }
    return 0;
}

# Find next available loop device
sub find_available_loop {
    for my $i (0..255) {
        my $loop_dev = "/dev/loop$i";
        my $status = `losetup $loop_dev 2>/dev/null`;
        if ($? != 0) {  # Device is available
            return $loop_dev;
        }
    }
    return undef;
}

# Create loop device
sub create_loop_device {
    my ($file_path, $offset, $agent) = @_;
    $offset //= 0;
    $agent //= 'unknown';
    
    # Verify file exists
    if (!-f $file_path) {
        handle_error("File does not exist: $file_path");
    }
    
    my $lock_fh = acquire_lock();
    my $registry = load_registry();
    
    # Check if this file is already mounted
    foreach my $entry (values %$registry) {
        if ($entry->{file_path} eq $file_path && $entry->{offset} == $offset) {
            release_lock($lock_fh);
            output_result(1, "Loop device already exists for this file", {
                loop_device => $entry->{loop_device}
            });
            return;
        }
    }
    
    # Find available loop device
    my $loop_dev = find_available_loop();
    if (!$loop_dev) {
        release_lock($lock_fh);
        handle_error("No available loop devices");
    }
    
    # Create the loop device
    my $losetup_cmd = $offset > 0 ? 
        "losetup -o $offset $loop_dev '$file_path'" :
        "losetup $loop_dev '$file_path'";
    
    debug_msg("Executing: $losetup_cmd");
    system($losetup_cmd);
    
    if ($? != 0) {
        release_lock($lock_fh);
        handle_error("Failed to create loop device: $!");
    }
    
    # Add to registry
    $registry->{$loop_dev} = {
        loop_device => $loop_dev,
        file_path => $file_path,
        offset => $offset,
        pid => $$,
        agent => $agent,
        timestamp => time()
    };
    
    if (!save_registry($registry)) {
        # Try to clean up the loop device we just created
        system("losetup -d $loop_dev 2>/dev/null");
        release_lock($lock_fh);
        handle_error("Failed to update registry");
    }
    
    release_lock($lock_fh);
    output_result(1, "Loop device created successfully", {
        loop_device => $loop_dev,
        file_path => $file_path,
        offset => $offset
    });
}

# Destroy loop device
sub destroy_loop_device {
    my ($loop_dev) = @_;
    
    my $lock_fh = acquire_lock();
    my $registry = load_registry();
    
    # Check if device is in our registry
    if (!exists $registry->{$loop_dev}) {
        release_lock($lock_fh);
        handle_error("Loop device $loop_dev not found in registry");
    }
    
    # Detach the loop device
    system("losetup -d $loop_dev 2>/dev/null");
    
    # Remove from registry regardless of losetup result
    delete $registry->{$loop_dev};
    
    if (!save_registry($registry)) {
        release_lock($lock_fh);
        handle_error("Failed to update registry");
    }
    
    release_lock($lock_fh);
    output_result(1, "Loop device destroyed successfully", {
        loop_device => $loop_dev
    });
}

# Cleanup loop devices
sub cleanup_loop_devices {
    my ($agent_filter) = @_;
    
    my $lock_fh = acquire_lock();
    my $registry = load_registry();
    
    my @cleaned = ();
    my @failed = ();
    
    # Sort devices by timestamp in REVERSE order (newest first, LIFO cleanup)
    # This ensures we clean up loop devices in reverse order of creation
    my @devices_to_cleanup = sort { 
        ($b->{timestamp} // 0) <=> ($a->{timestamp} // 0) 
    } values %$registry;
    
    foreach my $entry (@devices_to_cleanup) {
        my $loop_dev = $entry->{loop_device};
        
        # Skip if agent filter is specified and doesn't match
        if ($agent_filter && $entry->{agent} ne $agent_filter) {
            next;
        }
        
        debug_msg("Cleaning up loop device: $loop_dev (created at " . 
                 ($entry->{timestamp} ? strftime("%Y-%m-%d %H:%M:%S", localtime($entry->{timestamp})) : "unknown") . ")");
        
        # Try to detach the loop device
        system("losetup -d $loop_dev 2>/dev/null");
        if ($? == 0) {
            push @cleaned, $entry;
            delete $registry->{$loop_dev};
        } else {
            push @failed, $entry;
        }
    }
    
    if (!save_registry($registry)) {
        release_lock($lock_fh);
        handle_error("Failed to update registry");
    }
    
    release_lock($lock_fh);
    
    my $message = sprintf("Cleanup completed: %d devices cleaned, %d failed",
        scalar(@cleaned), scalar(@failed));
    
    output_result(1, $message, {
        cleaned => \@cleaned,
        failed => \@failed
    });
}

# List loop devices
sub list_loop_devices {
    my $registry = load_registry();
    
    my @devices = values %$registry;
    @devices = sort { $a->{loop_device} cmp $b->{loop_device} } @devices;
    
    my $message = sprintf("Found %d tracked loop devices", scalar(@devices));
    output_result(1, $message, { devices => \@devices });
}

# Mount session management functions
sub load_mount_registry {
    my $registry = {};
    
    if (-f $MOUNT_REGISTRY_FILE) {
        if (open(my $fh, '<', $MOUNT_REGISTRY_FILE)) {
            my $content = do { local $/; <$fh> };
            close($fh);
            
            eval {
                $registry = decode_json($content);
            };
            if ($@) {
                debug_msg("Warning: Failed to parse mount registry, starting fresh: $@");
                $registry = {};
            }
        }
    }
    
    # Clean up stale entries (where mount point no longer exists)
    # Note: We check the actual mount point, not the PID, since mounts persist
    # beyond the process that created them
    foreach my $session_id (keys %$registry) {
        my $entry = $registry->{$session_id};
        if ($entry->{final_mount} && ! -d $entry->{final_mount}) {
            debug_msg("Removing stale mount session for $session_id (mount point $entry->{final_mount} no longer exists)");
            delete $registry->{$session_id};
        }
    }
    
    return $registry;
}

sub save_mount_registry {
    my ($registry) = @_;
    
    if (open(my $fh, '>', $MOUNT_REGISTRY_FILE)) {
        print $fh encode_json($registry);
        close($fh);
        return 1;
    }
    return 0;
}

sub acquire_mount_lock {
    my $lock_fh;
    if (!open($lock_fh, '>', $MOUNT_LOCK_FILE)) {
        handle_error("Cannot create mount lock file: $!");
    }
    if (!flock($lock_fh, LOCK_EX)) {
        handle_error("Cannot acquire mount lock: $!");
    }
    return $lock_fh;
}

sub release_mount_lock {
    my ($lock_fh) = @_;
    flock($lock_fh, LOCK_UN) if $lock_fh;
    close($lock_fh) if $lock_fh;
    unlink($MOUNT_LOCK_FILE);
}

sub register_mount_session {
    my ($session_data) = @_;
    
    my $lock_fh = acquire_mount_lock();
    my $registry = load_mount_registry();
    
    # Generate unique session ID
    my $session_id = $session_data->{agent_id} . "_" . $session_data->{snapshot_epoch} . "_" . $$;
    
    # Add session to registry
    $registry->{$session_id} = {
        %$session_data,
        session_id => $session_id,
        pid => $$,
        timestamp => time()
    };
    
    if (!save_mount_registry($registry)) {
        release_mount_lock($lock_fh);
        handle_error("Failed to update mount registry");
    }
    
    release_mount_lock($lock_fh);
    output_result(1, "Mount session registered successfully", {
        session_id => $session_id
    });
}

sub unregister_mount_session {
    my ($session_id) = @_;
    
    my $lock_fh = acquire_mount_lock();
    my $registry = load_mount_registry();
    
    if (exists $registry->{$session_id}) {
        delete $registry->{$session_id};
        
        if (!save_mount_registry($registry)) {
            release_mount_lock($lock_fh);
            handle_error("Failed to update mount registry");
        }
        
        release_mount_lock($lock_fh);
        output_result(1, "Mount session unregistered successfully", {
            session_id => $session_id
        });
    } else {
        release_mount_lock($lock_fh);
        output_result(0, "Mount session not found: $session_id");
    }
}

sub list_mount_sessions {
    my ($agent_filter) = @_;
    
    my $registry = load_mount_registry();
    
    my @sessions = values %$registry;
    
    # Filter by agent if specified
    if ($agent_filter) {
        @sessions = grep { $_->{agent_name} eq $agent_filter || $_->{agent_id} eq $agent_filter } @sessions;
    }
    
    # Sort by timestamp (newest first)
    @sessions = sort { $b->{timestamp} <=> $a->{timestamp} } @sessions;
    
    my $message = sprintf("Found %d active mount sessions", scalar(@sessions));
    output_result(1, $message, { sessions => \@sessions });
}

sub cleanup_mount_sessions {
    my ($agent_filter) = @_;
    
    my $lock_fh = acquire_mount_lock();
    my $registry = load_mount_registry();
    
    my @cleaned = ();
    
    foreach my $session_id (keys %$registry) {
        my $entry = $registry->{$session_id};
        
        # Skip if agent filter is specified and doesn't match
        if ($agent_filter && $entry->{agent_name} ne $agent_filter && $entry->{agent_id} ne $agent_filter) {
            next;
        }
        
        push @cleaned, $entry;
        delete $registry->{$session_id};
    }
    
    if (!save_mount_registry($registry)) {
        release_mount_lock($lock_fh);
        handle_error("Failed to update mount registry");
    }
    
    release_mount_lock($lock_fh);
    
    my $message = sprintf("Cleaned up %d mount sessions", scalar(@cleaned));
    output_result(1, $message, {
        cleaned => \@cleaned
    });
}

# Main command processing
my $command = shift @ARGV || '';

if ($command eq 'create') {
    my $file_path = shift @ARGV;
    my $offset = shift @ARGV || 0;
    my $agent = shift @ARGV || 'unknown';
    
    if (!$file_path) {
        handle_error("Usage: $0 create <file_path> [offset] [agent]");
    }
    
    create_loop_device($file_path, $offset, $agent);
}
elsif ($command eq 'destroy') {
    my $loop_dev = shift @ARGV;
    
    if (!$loop_dev) {
        handle_error("Usage: $0 destroy <loop_device>");
    }
    
    destroy_loop_device($loop_dev);
}
elsif ($command eq 'cleanup') {
    my $agent_filter = shift @ARGV;
    cleanup_loop_devices($agent_filter);
}
elsif ($command eq 'list') {
    list_loop_devices();
}
elsif ($command eq 'register-mount') {
    # Expect JSON data for session registration
    my $json_data = shift @ARGV;
    if (!$json_data) {
        handle_error("Usage: $0 register-mount <json_session_data>");
    }
    
    my $session_data;
    eval {
        $session_data = decode_json($json_data);
    };
    if ($@) {
        handle_error("Invalid JSON data: $@");
    }
    
    register_mount_session($session_data);
}
elsif ($command eq 'unregister-mount') {
    my $session_id = shift @ARGV;
    if (!$session_id) {
        handle_error("Usage: $0 unregister-mount <session_id>");
    }
    
    unregister_mount_session($session_id);
}
elsif ($command eq 'list-mounts') {
    my $agent_filter = shift @ARGV;
    list_mount_sessions($agent_filter);
}
elsif ($command eq 'cleanup-mounts') {
    my $agent_filter = shift @ARGV;
    cleanup_mount_sessions($agent_filter);
}
else {
    handle_error("Usage: $0 [create|destroy|cleanup|list|register-mount|unregister-mount|list-mounts|cleanup-mounts] [options]");
}

exit 0;

