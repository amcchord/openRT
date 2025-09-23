#!/usr/bin/perl

###############################################################################
# openRTTUI.pl - OpenRT Text User Interface
###############################################################################
#
# DESCRIPTION:
#   This is the main TUI for the OpenRT backup system. It provides an 
#   interactive interface for detecting drives, importing pools, browsing
#   agents and snapshots, and mounting specific volumes. The interface
#   is designed to be intuitive and shows the current state of the system
#   clearly.
#
# USAGE:
#   sudo ./openRTTUI.pl [--non-interactive] [--cd-and-exit] [command] [args...]
#
# INTERACTIVE MODE:
#   sudo ./openRTTUI.pl
#   
# NON-INTERACTIVE MODE:
#   sudo ./openRTTUI.pl --non-interactive status
#   sudo ./openRTTUI.pl --non-interactive import [device_path]
#   sudo ./openRTTUI.pl --non-interactive list-agents
#   sudo ./openRTTUI.pl --non-interactive list-snapshots agent_name
#   sudo ./openRTTUI.pl --non-interactive list-mounts [agent_name]
#   sudo ./openRTTUI.pl --non-interactive mount agent_name [snapshot_epoch]
#   sudo ./openRTTUI.pl --non-interactive cleanup [agent_name]
#   sudo ./openRTTUI.pl --non-interactive cleanup-clones
#
# FEATURES:
#   - Real-time system status display
#   - Drive detection and pool import
#   - Agent and snapshot browsing
#   - Selective snapshot mounting
#   - Easy navigation and cleanup
#   - Both interactive and CLI modes
#
# REQUIREMENTS:
#   - Root privileges
#   - Perl modules: JSON, Term::ReadKey (auto-installed if missing)
#   - All OpenRT scripts in the same directory
#
###############################################################################

use strict;
use warnings;
use JSON;
use POSIX qw(strftime);
use File::Basename;
use Cwd 'abs_path';
use Getopt::Long;

# Global variables
my $script_dir = dirname(abs_path($0));
my $non_interactive = 0;
my $debug = 0;
my $cd_and_exit = 0;

# Parse command line options
my $show_help = 0;
GetOptions(
    'non-interactive' => \$non_interactive,
    'debug' => \$debug,
    'help|h' => \$show_help,
    'cd-and-exit' => \$cd_and_exit
) or die "Usage: $0 [--non-interactive] [--debug] [--help|-h] [--cd-and-exit] [command] [args...]\n";

# Show help if requested
if ($show_help) {
    show_help();
    exit 0;
}

# Auto-install required modules
BEGIN {
    eval {
        require Term::ReadKey;
        Term::ReadKey->import();
    };
    if ($@) {
        print "Installing required modules...\n";
        system('apt-get update -qq && apt-get install -qq -y libterm-readkey-perl') == 0
            or die "Failed to install required modules: $?\n";
        eval {
            require Term::ReadKey;
            Term::ReadKey->import();
        };
        die "Failed to load required modules after installation: $@" if $@;
    }
}

# Check root privileges
if ($> != 0) {
    die "This script must be run as root\n";
}

# Color constants for terminal output
my %COLORS = (
    'reset'   => "\033[0m",
    'bold'    => "\033[1m",
    'red'     => "\033[31m",
    'green'   => "\033[32m",
    'yellow'  => "\033[33m",
    'blue'    => "\033[34m",
    'magenta' => "\033[35m",
    'cyan'    => "\033[36m",
    'white'   => "\033[37m"
);

# Utility functions
sub colored {
    my ($text, $color) = @_;
    return $COLORS{$color} . $text . $COLORS{reset};
}

sub clear_screen {
    system('clear') unless $non_interactive;
}

sub wait_for_key {
    return if $non_interactive;
    print "\nPress any key to continue...";
    Term::ReadKey::ReadMode('cbreak');
    Term::ReadKey::ReadKey(0);
    Term::ReadKey::ReadMode('normal');
    print "\n";
}

sub get_user_input {
    my ($prompt, $default) = @_;
    return $default if $non_interactive;
    
    print $prompt;
    print " [$default]" if defined $default;
    print ": ";
    
    my $input = <STDIN>;
    chomp($input);
    return $input || $default;
}

sub print_header {
    my ($title) = @_;
    return if $non_interactive;
    
    my $width = 80;
    my $border = "=" x $width;
    my $padding = int(($width - length($title) - 2) / 2);
    my $header_line = "=" x $padding . " $title " . "=" x ($width - $padding - length($title) - 2);
    
    print colored($border, 'cyan') . "\n";
    print colored($header_line, 'cyan') . "\n";
    print colored($border, 'cyan') . "\n\n";
}

sub print_status {
    my ($message, $status) = @_;
    my $color = $status eq 'success' ? 'green' : 
                $status eq 'warning' ? 'yellow' : 
                $status eq 'error' ? 'red' : 'white';
    
    my $prefix = $status eq 'success' ? '✓' :
                 $status eq 'warning' ? '⚠' :
                 $status eq 'error' ? '✗' : '•';
    
    print colored("$prefix $message", $color) . "\n";
}

# System status functions
sub get_system_status {
    my $status_script = "$script_dir/rtStatus.pl";
    my $status_output = `perl "$status_script" -j 2>/dev/null`;
    
    my $status;
    eval {
        $status = decode_json($status_output);
    };
    
    return $status || {
        status => "Error",
        has_drives => JSON::false,
        has_imported_pool => JSON::false,
        has_available_pool => JSON::false,
        drives => [],
        imported_pools => [],
        available_pools => []
    };
}

sub get_agent_metadata {
    my $metadata_script = "$script_dir/rtMetadata.pl";
    my $metadata_output = `perl "$metadata_script" -j 2>/dev/null`;
    
    my $metadata;
    eval {
        $metadata = decode_json($metadata_output);
    };
    
    return $metadata;
}

sub get_agent_snapshots {
    my ($agent_id, $rt_pool) = @_;
    
    my $agents_path = $ENV{RT_AGENTS_PATH} || "home/agents";
    my $snapshot_path = "$rt_pool/$agents_path/$agent_id";
    
    my @snapshots = `zfs list -H -t snapshot -o name,creation $snapshot_path 2>/dev/null`;
    chomp(@snapshots);
    
    my @snapshot_list = ();
    foreach my $snap (@snapshots) {
        if ($snap =~ /^(\S+)\s+(.+)$/) {
            my ($name, $creation) = ($1, $2);
            if ($name =~ /\@(\d+)$/) {
                my $epoch = $1;
                push @snapshot_list, {
                    name => $name,
                    epoch => $epoch,
                    date => strftime("%Y-%m-%d %H:%M:%S", localtime($epoch)),
                    creation => $creation
                };
            }
        }
    }
    
    # Sort by epoch (newest first)
    @snapshot_list = sort { $b->{epoch} <=> $a->{epoch} } @snapshot_list;
    return \@snapshot_list;
}

# Command functions
sub cmd_status {
    my $status = get_system_status();
    
    if ($non_interactive) {
        print encode_json($status) . "\n";
        return;
    }
    
    print_header("System Status");
    
    print colored("Overall Status: ", 'bold');
    my $status_color = $status->{status} eq 'Imported' ? 'green' :
                      $status->{status} eq 'Available' ? 'yellow' : 'red';
    print colored($status->{status}, $status_color) . "\n\n";
    
    print colored("Connected Drives:", 'bold') . "\n";
    if (@{$status->{drives}}) {
        foreach my $drive (@{$status->{drives}}) {
            print "  • $drive->{name} ($drive->{size}, $drive->{type})\n";
        }
    } else {
        print "  No additional drives detected\n";
    }
    print "\n";
    
    print colored("Imported Pools:", 'bold') . "\n";
    if (@{$status->{imported_pools}}) {
        foreach my $pool (@{$status->{imported_pools}}) {
            my $rt_indicator = $pool->{is_rt_pool} ? " [RT Pool]" : "";
            print "  • $pool->{name} ($pool->{size}, allocated: $pool->{allocated})$rt_indicator\n";
        }
    } else {
        print "  No pools currently imported\n";
    }
    print "\n";
    
    print colored("Available Pools:", 'bold') . "\n";
    if (@{$status->{available_pools}}) {
        foreach my $pool (@{$status->{available_pools}}) {
            my $rt_indicator = $pool->{is_rt_pool} ? " [RT Pool]" : "";
            print "  • $pool->{name} ($pool->{state})$rt_indicator\n";
        }
    } else {
        print "  No pools available for import\n";
    }
    
    wait_for_key();
}

sub cmd_import {
    my ($device_path) = @_;
    
    if ($non_interactive) {
        my $import_script = "$script_dir/rtImport.pl";
        if ($device_path) {
            system("perl $import_script import $device_path");
        } else {
            system("perl $import_script import");
        }
        return;
    }
    
    print_header("Import Pools");
    
    my $status = get_system_status();
    
    if (!@{$status->{available_pools}}) {
        print_status("No pools available for import", 'warning');
        wait_for_key();
        return;
    }
    
    if (@{$status->{available_pools}}) {
        print "Available pools for import:\n";
        foreach my $pool (@{$status->{available_pools}}) {
            my $rt_indicator = $pool->{is_rt_pool} ? " [RT Pool]" : "";
            print "  • $pool->{name} ($pool->{state})$rt_indicator\n";
        }
        print "\n";
    }
    
    if (@{$status->{drives}}) {
        print "Available drives:\n";
        foreach my $drive (@{$status->{drives}}) {
            print "  • $drive->{name} ($drive->{size}, $drive->{type})\n";
        }
        print "\n";
    }
    
    print "Import Options:\n";
    print "  1) Import all available pools\n";
    print "  2) Import from specific device\n";
    print "  3) Cancel\n\n";
    
    my $choice = get_user_input("Select option (1-3)", "1");
    
    if ($choice eq '1') {
        my $confirm = get_user_input("Import all available pools? (y/N)", "n");
        if ($confirm =~ /^[yY]/) {
            print "\nImporting all pools...\n";
            my $import_script = "$script_dir/rtImport.pl";
            system("perl $import_script import");
            print_status("Import operation completed", 'success');
        } else {
            print_status("Import cancelled", 'warning');
        }
    } elsif ($choice eq '2') {
        my $device_path = get_user_input("Enter device path (e.g., /dev/sdb)", "");
        if ($device_path) {
            my $confirm = get_user_input("Import pools from $device_path? (y/N)", "n");
            if ($confirm =~ /^[yY]/) {
                print "\nImporting pools from $device_path...\n";
                my $import_script = "$script_dir/rtImport.pl";
                system("perl $import_script import $device_path");
                print_status("Import operation completed", 'success');
            } else {
                print_status("Import cancelled", 'warning');
            }
        } else {
            print_status("No device specified, import cancelled", 'warning');
        }
    } else {
        print_status("Import cancelled", 'warning');
    }
    
    wait_for_key();
}

sub cmd_list_agents {
    my $metadata = get_agent_metadata();
    
    if (!$metadata || !$metadata->{agents}) {
        if ($non_interactive) {
            print encode_json({error => "No metadata available or no pools imported"}) . "\n";
        } else {
            print_status("No metadata available. Please ensure a pool is imported.", 'error');
            wait_for_key();
        }
        return;
    }
    
    if ($non_interactive) {
        my @agent_list = ();
        foreach my $agent_id (sort keys %{$metadata->{agents}}) {
            my $agent = $metadata->{agents}->{$agent_id};
            push @agent_list, {
                id => $agent_id,
                name => $agent->{name} || $agent->{hostName} || 'Unknown',
                hostname => $agent->{hostname} || $agent->{hostName} || 'Unknown',
                fqdn => $agent->{fqdn} || 'Unknown',
                os => $agent->{os} || $agent->{os_name} || 'Unknown',
                os_version => $agent->{os_version} || 'Unknown',
                arch => $agent->{arch} || 'Unknown',
                agent_version => $agent->{agentVersion} || 'Unknown',
                snapshot_count => $agent->{snapshot_count} || 0,
                last_backup => $agent->{lastBackup} || 0
            };
        }
        print encode_json({agents => \@agent_list}) . "\n";
        return;
    }
    
    print_header("Available Agents");
    
    printf("%-20s %-25s %-20s %-10s %-12s %s\n", "Agent ID", "Hostname", "Operating System", "Version", "Snapshots", "Last Backup");
    print "-" x 100 . "\n";
    
    foreach my $agent_id (sort keys %{$metadata->{agents}}) {
        my $agent = $metadata->{agents}->{$agent_id};
        my $hostname = $agent->{hostname} || $agent->{hostName} || 'Unknown';
        my $os = $agent->{os} || $agent->{os_name} || 'Unknown';
        my $os_version = $agent->{os_version} || 'Unknown';
        my $snapshot_count = $agent->{snapshot_count} || 0;
        my $last_backup = $agent->{lastBackup} ? 
            strftime("%Y-%m-%d", localtime($agent->{lastBackup})) : 'Never';
        
        printf("%-20s %-25s %-20s %-10s %-12d %s\n", 
            substr($agent_id, 0, 20),
            substr($hostname, 0, 25),
            substr($os, 0, 20),
            substr($os_version, 0, 10),
            $snapshot_count,
            $last_backup
        );
    }
    
    wait_for_key();
}

sub cmd_list_snapshots {
    my ($agent_name) = @_;
    
    if (!$agent_name) {
        if ($non_interactive) {
            print encode_json({error => "Agent name is required for list-snapshots command"}) . "\n";
        } else {
            print_status("Agent name is required", 'error');
            wait_for_key();
        }
        return;
    }
    
    my $metadata = get_agent_metadata();
    if (!$metadata || !$metadata->{agents}) {
        if ($non_interactive) {
            print encode_json({error => "No metadata available or no pools imported"}) . "\n";
        } else {
            print_status("No metadata available. Please ensure a pool is imported.", 'error');
            wait_for_key();
        }
        return;
    }
    
    # Find agent info by name, hostname, or ID
    my $agent_info;
    my $agent_id_found;
    foreach my $agent_id (keys %{$metadata->{agents}}) {
        my $agent = $metadata->{agents}->{$agent_id};
        if ($agent_id eq $agent_name || 
            $agent->{hostname} eq $agent_name || 
            $agent->{name} eq $agent_name ||
            $agent->{hostName} eq $agent_name) {
            $agent_info = $agent;
            $agent_id_found = $agent_id;
            last;
        }
    }
    
    if (!$agent_info) {
        if ($non_interactive) {
            print encode_json({error => "Agent '$agent_name' not found"}) . "\n";
        } else {
            print_status("Agent '$agent_name' not found", 'error');
            wait_for_key();
        }
        return;
    }
    
    # Get snapshots for this agent
    my $snapshots = get_agent_snapshots($agent_id_found, $metadata->{pool_name});
    
    if ($non_interactive) {
        # Format snapshots for JSON output
        my @snapshot_list = ();
        foreach my $snap (@$snapshots) {
            push @snapshot_list, {
                name => $snap->{name},
                epoch => $snap->{epoch},
                date => $snap->{date},
                creation => $snap->{creation}
            };
        }
        
        print encode_json({
            agent_id => $agent_id_found,
            agent_name => $agent_info->{hostname} || $agent_info->{hostName} || $agent_name,
            snapshot_count => scalar(@snapshot_list),
            snapshots => \@snapshot_list
        }) . "\n";
        return;
    }
    
    # Interactive mode display
    print_header("Snapshots for " . ($agent_info->{hostname} || $agent_info->{hostName} || $agent_name));
    
    if (!@$snapshots) {
        print_status("No snapshots found for this agent", 'warning');
        wait_for_key();
        return;
    }
    
    printf("%-4s %-20s %-12s %s\n", "#", "Date/Time", "Epoch", "ZFS Creation Time");
    print "-" x 70 . "\n";
    
    for my $i (0..$#$snapshots) {
        my $snap = $snapshots->[$i];
        printf("%-4d %-20s %-12s %s\n", 
            $i + 1,
            $snap->{date},
            $snap->{epoch},
            $snap->{creation}
        );
    }
    
    print "\n";
    print colored("Total snapshots: ", 'bold') . scalar(@$snapshots) . "\n";
    print colored("Agent ID: ", 'bold') . $agent_id_found . "\n";
    print colored("Latest snapshot: ", 'bold') . $snapshots->[0]->{date} . "\n" if @$snapshots;
    
    wait_for_key();
}

sub cmd_list_mounts {
    my ($agent_name) = @_;
    
    my $loop_manager = "$script_dir/rtLoopManager.pl";
    if (!-f $loop_manager) {
        if ($non_interactive) {
            print encode_json({error => "rtLoopManager.pl not found"}) . "\n";
        } else {
            print_status("rtLoopManager.pl not found", 'error');
            wait_for_key();
        }
        return;
    }
    
    # Get mount sessions from rtLoopManager
    my $cmd = "perl $loop_manager list-mounts -j";
    $cmd .= " $agent_name" if $agent_name;
    my $output = `$cmd 2>/dev/null`;
    
    my $result;
    eval {
        $result = decode_json($output);
    };
    
    if ($@ || !$result || !$result->{success}) {
        if ($non_interactive) {
            print encode_json({error => "Failed to get mount sessions"}) . "\n";
        } else {
            print_status("Failed to get mount sessions", 'error');
            wait_for_key();
        }
        return;
    }
    
    my $sessions = $result->{sessions} || [];
    
    if ($non_interactive) {
        print encode_json({
            status => "success",
            mount_count => scalar(@$sessions),
            mounts => $sessions
        }) . "\n";
        return;
    }
    
    # Interactive mode display
    my $title = $agent_name ? "Active Mounts for $agent_name" : "All Active Mounts";
    print_header($title);
    
    if (!@$sessions) {
        print_status("No active mount sessions found", 'warning');
        if (!$agent_name) {
            print "\nActive mount sessions are tracked when snapshots are mounted\n";
            print "through the TUI. Use the mount command to create tracked sessions.\n";
        }
        wait_for_key();
        return;
    }
    
    printf("%-20s %-25s %-20s %-12s %-20s %s\n", 
        "Agent ID", "Agent Name", "Snapshot Date", "PID", "Mount Path", "Started");
    print "-" x 110 . "\n";
    
    foreach my $session (@$sessions) {
        my $agent_id = substr($session->{agent_id} || 'unknown', 0, 20);
        my $agent_name = substr($session->{agent_name} || 'unknown', 0, 25);
        my $snapshot_date = substr($session->{snapshot_date} || 'unknown', 0, 20);
        my $pid = $session->{pid} || 0;
        my $mount_path = substr($session->{final_mount} || 'unknown', 0, 20);
        my $started = $session->{timestamp} ? 
            strftime("%Y-%m-%d %H:%M:%S", localtime($session->{timestamp})) : 'unknown';
        
        printf("%-20s %-25s %-20s %-12d %-20s %s\n",
            $agent_id, $agent_name, $snapshot_date, $pid, $mount_path, $started);
    }
    
    print "\n";
    print colored("Total active mounts: ", 'bold') . scalar(@$sessions) . "\n";
    
    if (@$sessions) {
        print "\nTo clean up these mounts, use:\n";
        print "  • " . colored("Menu option 6", 'cyan') . " - Cleanup Mounts\n";
        print "  • " . colored("CLI command", 'cyan') . " - sudo ./openRTTUI.pl --non-interactive cleanup\n";
    }
    
    wait_for_key();
}

sub cmd_mount {
    my ($agent_name, $snapshot_epoch) = @_;
    
    if ($non_interactive) {
        my $mount_script = "$script_dir/rtFileMountImproved.pl";
        my $mount_output;
        if ($snapshot_epoch) {
            $mount_output = `perl $mount_script -j $agent_name $snapshot_epoch 2>&1`;
        } else {
            $mount_output = `perl $mount_script -j $agent_name 2>&1`;
        }
        
        # If cd-and-exit is requested, parse the mount result and change directory
        if ($cd_and_exit) {
            my $mount_result;
            eval {
                $mount_result = decode_json($mount_output);
            };
            
            if (!$@ && $mount_result && $mount_result->{status} eq 'success' && 
                $mount_result->{mounts} && @{$mount_result->{mounts}}) {
                my $mount_info = $mount_result->{mounts}->[0];
                my $mount_path = $mount_info->{final_mount};
                
                if (-d $mount_path) {
                    print "Mount successful. Changing directory to: $mount_path\n";
                    chdir($mount_path) or die "Cannot change directory to $mount_path: $!\n";
                    exec($ENV{SHELL} || '/bin/bash') or die "Cannot exec shell: $!\n";
                } else {
                    print "Mount successful but directory not accessible: $mount_path\n";
                    exit 1;
                }
            } else {
                print "Mount failed or no mount information available\n";
                print $mount_output if $mount_output;
                exit 1;
            }
        } else {
            # Just print the output and exit normally
            print $mount_output if $mount_output;
        }
        return;
    }
    
    print_header("Mount Agent Snapshots");
    
    my $metadata = get_agent_metadata();
    if (!$metadata || !$metadata->{agents}) {
        print_status("No metadata available. Please ensure a pool is imported.", 'error');
        wait_for_key();
        return;
    }
    
    # If no agent specified, let user choose
    if (!$agent_name) {
        print "Available agents:\n";
        my @agents = sort keys %{$metadata->{agents}};
        for my $i (0..$#agents) {
            my $agent_id = $agents[$i];
            my $agent = $metadata->{agents}->{$agent_id};
            my $hostname = $agent->{hostname} || $agent->{hostName} || 'Unknown';
            my $os = $agent->{os} || $agent->{os_name} || '';
            my $os_display = $os ? " - $os" : '';
            printf("  %d) %s (%s)%s\n", $i + 1, $hostname, substr($agent_id, 0, 8) . "...", $os_display);
        }
        print "\n";
        
        my $choice = get_user_input("Select agent (1-" . scalar(@agents) . ")", "1");
        if ($choice =~ /^\d+$/ && $choice >= 1 && $choice <= @agents) {
            $agent_name = $agents[$choice - 1];
        } else {
            print_status("Invalid selection", 'error');
            wait_for_key();
            return;
        }
    }
    
    # Find agent info
    my $agent_info;
    foreach my $agent_id (keys %{$metadata->{agents}}) {
        my $agent = $metadata->{agents}->{$agent_id};
        if ($agent_id eq $agent_name || 
            $agent->{hostname} eq $agent_name || 
            $agent->{name} eq $agent_name) {
            $agent_info = $agent;
            $agent_name = $agent_id;  # Use the actual agent ID
            last;
        }
    }
    
    if (!$agent_info) {
        print_status("Agent not found: $agent_name", 'error');
        wait_for_key();
        return;
    }
    
    print colored("Selected Agent: ", 'bold') . ($agent_info->{hostname} || $agent_name) . "\n\n";
    
    # If no snapshot specified, let user choose
    if (!$snapshot_epoch) {
        my $snapshots = get_agent_snapshots($agent_name, $metadata->{pool_name});
        
        if (!@$snapshots) {
            print_status("No snapshots found for this agent", 'error');
            wait_for_key();
            return;
        }
        
        print "Available snapshots:\n";
        for my $i (0..min(9, $#$snapshots)) {  # Show up to 10 snapshots
            my $snap = $snapshots->[$i];
            printf("  %d) %s\n", $i + 1, $snap->{date});
        }
        
        if (@$snapshots > 10) {
            printf("  ... and %d more snapshots\n", @$snapshots - 10);
        }
        print "\n";
        
        my $choice = get_user_input("Select snapshot (1-" . min(10, scalar(@$snapshots)) . ", or 'latest')", "latest");
        
        if ($choice eq 'latest' || $choice eq '') {
            $snapshot_epoch = $snapshots->[0]->{epoch};
        } elsif ($choice =~ /^\d+$/ && $choice >= 1 && $choice <= min(10, @$snapshots)) {
            $snapshot_epoch = $snapshots->[$choice - 1]->{epoch};
        } else {
            print_status("Invalid selection", 'error');
            wait_for_key();
            return;
        }
    }
    
    # Perform the mount
    print "\nMounting snapshot...\n";
    my $mount_script = "$script_dir/rtFileMountImproved.pl";
    my $mount_output = `perl $mount_script -j $agent_name $snapshot_epoch 2>&1`;
    
    my $mount_result;
    eval {
        $mount_result = decode_json($mount_output);
    };
    
    if ($@ || !$mount_result || $mount_result->{status} ne 'success') {
        print_status("Mount failed: " . ($@ || $mount_output), 'error');
    } else {
        print_status("Mount completed successfully", 'success');
        if ($mount_result->{mounts} && @{$mount_result->{mounts}}) {
            my $mount_info = $mount_result->{mounts}->[0];
            print "\nMount details:\n";
            print "  Snapshot: $mount_info->{date}\n";
            print "  Location: $mount_info->{final_mount}\n";
            
            if ($mount_info->{volumes}) {
                print "  Volumes:\n";
                foreach my $vol (@{$mount_info->{volumes}}) {
                    my $status_text = $vol->{status} eq 'mounted' ? 
                        colored("✓ Mounted", 'green') : 
                        colored("✗ Failed", 'red');
                    print "    • $vol->{mountpoint}: $status_text\n";
                    if ($vol->{status} eq 'mounted') {
                        print "      Path: $vol->{mount_path}\n";
                    }
                }
            }
            
            # Offer option to cd to mount point and exit
            print "\n";
            my $cd_choice = get_user_input("Change directory to mount point and exit? (y/N)", "n");
            if ($cd_choice =~ /^[yY]/) {
                my $mount_path = $mount_info->{final_mount};
                if (-d $mount_path) {
                    print "\nChanging directory to: $mount_path\n";
                    print "Exiting TUI - you are now in the mount directory.\n\n";
                    
                    # Change directory and exec a new shell
                    # This replaces the current process with a shell in the mount directory
                    chdir($mount_path) or die "Cannot change directory to $mount_path: $!\n";
                    exec($ENV{SHELL} || '/bin/bash') or die "Cannot exec shell: $!\n";
                } else {
                    print_status("Mount directory does not exist or is not accessible", 'error');
                }
            }
        }
    }
    
    wait_for_key();
}

sub cmd_cleanup {
    my ($agent_name) = @_;
    
    if ($non_interactive) {
        my $mount_script = "$script_dir/rtFileMountImproved.pl";
        if ($agent_name) {
            system("perl $mount_script cleanup $agent_name");
        } else {
            system("perl $mount_script cleanup");
        }
        return;
    }
    
    print_header("Cleanup Mounts");
    
    if ($agent_name) {
        print "Cleaning up mounts for agent: $agent_name\n\n";
    } else {
        print "This will clean up ALL mounted snapshots and loop devices.\n\n";
    }
    
    my $confirm = get_user_input("Proceed with cleanup? (y/N)", "n");
    if ($confirm =~ /^[yY]/) {
        print "\nPerforming cleanup...\n";
        my $mount_script = "$script_dir/rtFileMountImproved.pl";
        if ($agent_name) {
            system("perl $mount_script cleanup $agent_name");
        } else {
            system("perl $mount_script cleanup");
        }
        print_status("Cleanup completed", 'success');
    } else {
        print_status("Cleanup cancelled", 'warning');
    }
    
    wait_for_key();
}

sub cmd_cleanup_clones {
    if ($non_interactive) {
        # Get list of orphaned mount clones
        my @clones = `zfs list -H -o name | grep 'mount_'`;
        chomp(@clones);
        
        if (@clones) {
            my @results = ();
            foreach my $clone (@clones) {
                my $result = system("zfs destroy $clone 2>/dev/null");
                push @results, {
                    clone => $clone,
                    status => $result == 0 ? "destroyed" : "failed"
                };
            }
            
            print encode_json({
                status => "completed",
                message => "Cleanup of orphaned ZFS clones completed",
                clones_found => scalar(@clones),
                results => \@results
            }) . "\n";
        } else {
            print encode_json({
                status => "completed", 
                message => "No orphaned ZFS clones found",
                clones_found => 0,
                results => []
            }) . "\n";
        }
        return;
    }
    
    print_header("Cleanup Orphaned ZFS Clones");
    
    # Get list of orphaned mount clones
    print "Scanning for orphaned ZFS mount clones...\n";
    my @clones = `zfs list -H -o name | grep 'mount_'`;
    chomp(@clones);
    
    if (!@clones) {
        print_status("No orphaned ZFS clones found", 'success');
        print "\nThese are ZFS clones created by the mount process that typically\n";
        print "get cleaned up automatically, but may persist after system reboots\n";
        print "or unexpected shutdowns.\n";
        wait_for_key();
        return;
    }
    
    print colored("Found " . scalar(@clones) . " orphaned ZFS clones:", 'yellow') . "\n\n";
    foreach my $clone (@clones) {
        print "  • $clone\n";
    }
    
    print "\n";
    print colored("WARNING:", 'red') . " This will permanently destroy these ZFS clones.\n";
    print "Make sure no active mounts are using these clones before proceeding.\n";
    print "These clones are typically safe to remove if no snapshots are currently mounted.\n\n";
    
    my $confirm = get_user_input("Proceed with cleanup of " . scalar(@clones) . " clones? (y/N)", "n");
    
    if ($confirm =~ /^[yY]/) {
        print "\nCleaning up orphaned ZFS clones...\n";
        my $success_count = 0;
        my $failed_count = 0;
        
        foreach my $clone (@clones) {
            print "Destroying: $clone ... ";
            my $result = system("zfs destroy $clone 2>/dev/null");
            if ($result == 0) {
                print colored("✓ Success", 'green') . "\n";
                $success_count++;
            } else {
                print colored("✗ Failed", 'red') . "\n";
                $failed_count++;
            }
        }
        
        print "\n";
        if ($failed_count == 0) {
            print_status("All $success_count clones cleaned up successfully", 'success');
        } else {
            print_status("Cleanup completed: $success_count successful, $failed_count failed", 'warning');
            print "\nNote: Failed clones may be in use or have dependencies.\n";
            print "Try running the regular cleanup command first, or check for active mounts.\n";
        }
    } else {
        print_status("Cleanup cancelled", 'warning');
    }
    
    wait_for_key();
}

sub min {
    my ($a, $b) = @_;
    return $a < $b ? $a : $b;
}

sub show_help {
    print <<'EOF';
openRTTUI.pl - OpenRT Text User Interface

DESCRIPTION:
    Interactive and command-line interface for the OpenRT backup system.
    Provides drive detection, pool import, agent browsing, and snapshot mounting.

USAGE:
    Interactive Mode:
        sudo ./openRTTUI.pl
    
    Non-Interactive Mode:
        sudo ./openRTTUI.pl --non-interactive <command> [arguments]

OPTIONS:
    -h, --help          Show this help message and exit
    --non-interactive   Run in non-interactive CLI mode
    --debug             Enable debug output
    --cd-and-exit       After successful mount, change directory to mount point and exit
                        (only works with mount command in non-interactive mode)

NON-INTERACTIVE COMMANDS:

    status
        Display current system status including drives, pools, and agents.
        Shows JSON output with drive information, imported/available pools.
        
        Example:
            sudo ./openRTTUI.pl --non-interactive status

    import [device_path]
        Import ZFS pools. If no device_path is specified, imports all available
        pools found on connected drives. If device_path is provided, imports only
        pools from that specific device.
        
        Examples:
            sudo ./openRTTUI.pl --non-interactive import
            sudo ./openRTTUI.pl --non-interactive import /dev/sdb

    list-agents
        List all available backup agents with their metadata.
        Shows agent IDs, hostnames, OS info, snapshot counts, and last backup dates.
        
        Example:
            sudo ./openRTTUI.pl --non-interactive list-agents

    list-snapshots <agent_name>
        List all available snapshots for a specific agent.
        Shows snapshot dates, epochs, and ZFS creation times.
        Agent can be specified by agent ID, hostname, or agent name.
        
        Examples:
            sudo ./openRTTUI.pl --non-interactive list-snapshots LabPC
            sudo ./openRTTUI.pl --non-interactive list-snapshots bdc3c2be10664c019a6f4ca3b64023fe

    list-mounts [agent_name]
        List all currently active mounted snapshots.
        Shows agent information, mount paths, PIDs, and start times.
        If agent_name is provided, shows only mounts for that agent.
        
        Examples:
            sudo ./openRTTUI.pl --non-interactive list-mounts
            sudo ./openRTTUI.pl --non-interactive list-mounts LabPC

    mount <agent_name> [snapshot_epoch]
        Mount snapshots for a specific agent. Agent can be specified by:
        - Agent ID (full or partial)
        - Hostname
        - Agent name
        
        If snapshot_epoch is not provided, mounts the latest snapshot.
        If snapshot_epoch is provided, mounts that specific snapshot.
        
        Examples:
            sudo ./openRTTUI.pl --non-interactive mount LabPC
            sudo ./openRTTUI.pl --non-interactive mount bdc3c2be10664c019a6f4ca3b64023fe
            sudo ./openRTTUI.pl --non-interactive mount LabPC 1671766798

    cleanup [agent_name]
        Clean up mounted snapshots and associated resources.
        
        If agent_name is provided, cleans up only that agent's mounts.
        If no agent_name is provided, cleans up ALL mounts and loop devices.
        
        Examples:
            sudo ./openRTTUI.pl --non-interactive cleanup
            sudo ./openRTTUI.pl --non-interactive cleanup LabPC

    cleanup-clones
        Clean up orphaned ZFS mount clones that persist after reboots.
        
        These are ZFS clones created during the mount process that normally
        get cleaned up automatically, but may persist after system reboots
        or unexpected shutdowns. This command safely removes all clones
        with 'mount_' in their name.
        
        Example:
            sudo ./openRTTUI.pl --non-interactive cleanup-clones

REQUIREMENTS:
    - Root privileges (must run with sudo)
    - Perl modules: JSON, Term::ReadKey (auto-installed if missing)
    - ZFS filesystem support
    - OpenRT backup system components

INTERACTIVE FEATURES:
    When run without --non-interactive, provides a menu-driven interface with:
    - Real-time system status display
    - Drive detection and pool import
    - Agent and snapshot browsing
    - Selective snapshot mounting
    - Easy navigation and cleanup
    - Color-coded status indicators

EXAMPLES:
    # Check system status
    sudo ./openRTTUI.pl --non-interactive status

    # Import all available pools
    sudo ./openRTTUI.pl --non-interactive import
    
    # Import pools from specific device
    sudo ./openRTTUI.pl --non-interactive import /dev/sdb

    # List all agents
    sudo ./openRTTUI.pl --non-interactive list-agents
    
    # List snapshots for specific agent
    sudo ./openRTTUI.pl --non-interactive list-snapshots MyPC
    
    # List all active mounts
    sudo ./openRTTUI.pl --non-interactive list-mounts

    # Mount latest snapshot for MyPC
    sudo ./openRTTUI.pl --non-interactive mount MyPC

    # Mount specific snapshot
    sudo ./openRTTUI.pl --non-interactive mount MyPC 1234567890
    
    # Mount and change directory to mount point
    sudo ./openRTTUI.pl --non-interactive --cd-and-exit mount MyPC

    # Clean up all mounts
    sudo ./openRTTUI.pl --non-interactive cleanup

    # Clean up specific agent mounts
    sudo ./openRTTUI.pl --non-interactive cleanup MyPC
    
    # Clean up orphaned ZFS clones
    sudo ./openRTTUI.pl --non-interactive cleanup-clones

    # Launch interactive interface
    sudo ./openRTTUI.pl

For more information, see README_IMPROVED.md in the same directory.
EOF
}

# Interactive menu system
sub show_main_menu {
    clear_screen();
    print_header("OpenRT Backup Management System");
    
    my $status = get_system_status();
    
    # Show current status
    print colored("Current Status: ", 'bold');
    my $status_color = $status->{status} eq 'Imported' ? 'green' :
                      $status->{status} eq 'Available' ? 'yellow' : 'red';
    print colored($status->{status}, $status_color) . "\n\n";
    
    print colored("Main Menu:", 'bold') . "\n";
    print "  1) System Status\n";
    print "  2) Import Pools\n";
    print "  3) List Agents\n";
    print "  4) List Snapshots\n";
    print "  5) List Active Mounts\n";
    print "  6) Mount Snapshot\n";
    print "  7) Cleanup Mounts\n";
    print "  8) Cleanup Orphaned Clones\n";
    print "  9) Exit\n\n";
    
    my $choice = get_user_input("Select option (1-9)", "1");
    
    if ($choice eq '1') {
        cmd_status();
    } elsif ($choice eq '2') {
        cmd_import();
    } elsif ($choice eq '3') {
        cmd_list_agents();
    } elsif ($choice eq '4') {
        cmd_list_snapshots();
    } elsif ($choice eq '5') {
        cmd_list_mounts();
    } elsif ($choice eq '6') {
        cmd_mount();
    } elsif ($choice eq '7') {
        cmd_cleanup();
    } elsif ($choice eq '8') {
        cmd_cleanup_clones();
    } elsif ($choice eq '9') {
        print "\nGoodbye!\n";
        exit 0;
    } else {
        print_status("Invalid option selected", 'error');
        wait_for_key();
    }
}

# Main execution
if ($non_interactive) {
    my $command = shift @ARGV || 'status';
    
    if ($command eq 'status') {
        cmd_status();
    } elsif ($command eq 'import') {
        my $device_path = shift @ARGV;
        cmd_import($device_path);
    } elsif ($command eq 'list-agents') {
        cmd_list_agents();
    } elsif ($command eq 'list-snapshots') {
        my $agent_name = shift @ARGV;
        cmd_list_snapshots($agent_name);
    } elsif ($command eq 'list-mounts') {
        my $agent_name = shift @ARGV;
        cmd_list_mounts($agent_name);
    } elsif ($command eq 'mount') {
        my $agent_name = shift @ARGV;
        my $snapshot_epoch = shift @ARGV;
        cmd_mount($agent_name, $snapshot_epoch);
    } elsif ($command eq 'cleanup') {
        my $agent_name = shift @ARGV;
        cmd_cleanup($agent_name);
    } elsif ($command eq 'cleanup-clones') {
        cmd_cleanup_clones();
    } else {
        die "Unknown command: $command\n";
    }
} else {
    # Interactive mode
    while (1) {
        show_main_menu();
    }
}

exit 0;
