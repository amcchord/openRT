#!/bin/bash

# Check if script is run with sudo privileges
if [ "$EUID" -ne 0 ]; then 
    echo "Please run this script with sudo privileges"
    exit 1
fi

# Parse command line arguments
ENABLE_AUTOMOUNT=1
while [[ $# -gt 0 ]]; do
    case $1 in
        --no-automount)
            ENABLE_AUTOMOUNT=0
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: $0 [--no-automount]"
            exit 1
            ;;
    esac
done

# Function to check if a command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check for git and install if not present
if ! command_exists git; then
    echo "Git not found. Installing git..."
    if command_exists apt-get; then
        apt-get update
        apt-get install -y git
    elif command_exists yum; then
        yum install -y git
    elif command_exists brew; then
        brew install git
    else
        echo "Could not install git. Please install git manually and run this script again."
        exit 1
    fi
fi

# Create directory if it doesn't exist
INSTALL_DIR="/usr/local/openRT"
if [ -d "$INSTALL_DIR" ]; then
    echo "Removing existing installation directory..."
    rm -rf "$INSTALL_DIR"
fi
mkdir -p "$INSTALL_DIR"

# Clone the repository
echo "Cloning openRT repository..."
git clone https://github.com/amcchord/openRT.git "$INSTALL_DIR"

# Check if clone was successful
if [ $? -ne 0 ]; then
    echo "Failed to clone repository"
    exit 1
fi

# Run setup script
SETUP_SCRIPT="$INSTALL_DIR/setup/setup.sh"
if [ -f "$SETUP_SCRIPT" ]; then
    echo "Running setup script..."
    chmod +x "$SETUP_SCRIPT"
    "$SETUP_SCRIPT" -y
else
    echo "Setup script not found at $SETUP_SCRIPT"
    exit 1
fi

# Set automount status
if [ $ENABLE_AUTOMOUNT -eq 1 ]; then
    echo "Enabling automount..."
    mkdir -p "$INSTALL_DIR/status"
    echo 1 > "$INSTALL_DIR/status/automount"
fi

# Set full permissions on installation directory
echo "Setting permissions..."
chmod -R 777 "$INSTALL_DIR"

echo "Installation completed successfully!"

# Reboot the system
echo "Rebooting system in 5 seconds..."
sleep 5
reboot
