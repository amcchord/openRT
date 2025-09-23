#!/bin/bash

# Constants
STATUS_DIR="/usr/local/openRT/status"
SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
LAST_STATUS_FILE="$STATUS_DIR/last_status"
AUTOMOUNT_FLAG="$STATUS_DIR/automount"

# Create status directory if it doesn't exist
mkdir -p "$STATUS_DIR"

# Function to check if automount is enabled (always returns false)
check_automount_enabled() {
    [[ -f "$AUTOMOUNT_FLAG" ]] && [[ "$(cat "$AUTOMOUNT_FLAG")" == "1" ]]
}

# Function to get current pool status
get_pool_status() {
    # Use environment variables if they exist by passing them through
    if [[ -n "$RT_POOL_NAME" ]] || [[ -n "$RT_POOL_PATTERN" ]] || [[ -n "$RT_EXPORT_ALL" ]]; then
        # Preserve environment variables when calling rtStatus.pl
        env RT_POOL_NAME="$RT_POOL_NAME" RT_POOL_PATTERN="$RT_POOL_PATTERN" RT_EXPORT_ALL="$RT_EXPORT_ALL" \
            perl "$SCRIPT_DIR/rtStatus.pl" -j | jq -r '.status'
    else
        # Normal execution without environment variables
        perl "$SCRIPT_DIR/rtStatus.pl" -j | jq -r '.status'
    fi
}

# Function to handle status changes
handle_status_change() {
    local new_status="$1"
    local last_status="$2"
    
    echo "$new_status" > "$LAST_STATUS_FILE"
    
    # If automount is enabled and pool becomes Available
    if check_automount_enabled && [[ "$new_status" == "Available" ]]; then
        # Launch automount in the background
        # Automount Disabled
        #perl "$SCRIPT_DIR/rtAutoMount.pl" &
    fi
}

# Main loop
while true; do
    # Get current status
    current_status=$(get_pool_status)
    
    # Get last status
    last_status=""
    [[ -f "$LAST_STATUS_FILE" ]] && last_status=$(cat "$LAST_STATUS_FILE")
    
    # If status has changed, handle it
    if [[ "$current_status" != "$last_status" ]]; then
        handle_status_change "$current_status" "$last_status"
    fi
    
    # Sleep for 5 seconds
    sleep 5
done 