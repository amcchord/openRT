#!/bin/bash

# Target version for updates
TARGET_VERSION="2.1"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

# If script is called with 'remote' argument, just show the message
if [ "$1" = "remote" ]; then
    #echo "No Updates Right now"
    # Check current version from README.md
    if [ -f "/usr/local/openRT/openRTApp/README.md" ]; then
        CURRENT_VER=$(grep "^VER" "/usr/local/openRT/openRTApp/README.md" | awk '{print $2}')
        if [ "$CURRENT_VER" != "$TARGET_VERSION" ]; then
            echo "Updating openRTApp and web directory from GitHub..."
            
            # Backup current web directory if it exists
            if [ -d "/usr/local/openRT/web" ]; then
                echo "Backing up current web directory..."
                cp -r /usr/local/openRT/web /usr/local/openRT/web.backup.$(date +%Y%m%d_%H%M%S)
            fi
            
            # Create temporary directory in /tmp/
            TEMP_DIR="/tmp/openRT_update_$$"
            mkdir -p "$TEMP_DIR"
            
            # Clone to temporary directory
            echo "Cloning repository to temporary directory..."
            cd "$TEMP_DIR"
            git clone https://github.com/amcchord/openRT.git openRT_repo
            
            if [ $? -eq 0 ]; then
                # Use rsync to copy files, which handles existing directories better
                echo "Syncing openRTApp directory..."
                rsync -av --delete "$TEMP_DIR/openRT_repo/openRTApp/" "/usr/local/openRT/openRTApp/"
                
                echo "Syncing web directory..."
                rsync -av --delete "$TEMP_DIR/openRT_repo/web/" "/usr/local/openRT/web/"
                
                echo "openRTApp and web directory updated to version $TARGET_VERSION"
            else
                echo "Failed to clone repository"
                exit 1
            fi
            
            # Clean up temporary directory
            rm -rf "$TEMP_DIR"

            # Make all Perl scripts executable in openRTApp directory
            echo "Making Perl scripts executable..."
            chmod a+rx /usr/local/openRT/openRTApp/*.pl

            #allow the www-data users to sudo without a password
            echo "Allowing www-data users to sudo without a password..."
            echo "www-data ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers

        else
            echo "openRTApp is already at version $TARGET_VERSION"
        fi
    else
        echo "README.md not found, performing fresh install..."
        
        # Create temporary directory in /tmp/
        TEMP_DIR="/tmp/openRT_install_$$"
        mkdir -p "$TEMP_DIR"
        
        # Clone to temporary directory
        echo "Cloning repository to temporary directory..."
        cd "$TEMP_DIR"
        git clone https://github.com/amcchord/openRT.git openRT_repo
        
        if [ $? -eq 0 ]; then
            # Use rsync to copy files
            echo "Installing openRTApp directory..."
            rsync -av "$TEMP_DIR/openRT_repo/openRTApp/" "/usr/local/openRT/openRTApp/"
            
            echo "Installing web directory..."
            rsync -av "$TEMP_DIR/openRT_repo/web/" "/usr/local/openRT/web/"
            
            echo "openRTApp and web directory installed at version $TARGET_VERSION"
        else
            echo "Failed to clone repository"
            exit 1
        fi
        
        # Clean up temporary directory
        rm -rf "$TEMP_DIR"

        # Make all Perl scripts executable in openRTApp directory
        echo "Making Perl scripts executable..."
        chmod a+rx /usr/local/openRT/openRTApp/*.pl

        #allow the www-data users to sudo without a password
        echo "Allowing www-data users to sudo without a password..."
        echo "www-data ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
        
    fi

    exit 0
fi

# Download and execute the remote script
echo "Downloading and executing remote script"
curl -s https://raw.githubusercontent.com/amcchord/openRT/refs/heads/main/setup/githubUpdates.sh | sudo bash -s remote
