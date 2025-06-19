#!/bin/bash

# DNS Daemon Process Checker
# Runs every minute via cron to ensure DNS monitoring daemon is running.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DAEMON_SCRIPT="$SCRIPT_DIR/dns-monitor-daemon.php"
LOG_FILE="$SCRIPT_DIR/data/dns-daemon.log"
DATA_DIR="$SCRIPT_DIR/data"

# --- Diagnostic Logging ---
# Get the parent process ID (PPID) and the command that ran it.
# This is the key to finding out WHAT is calling this script.
PARENT_PID=$(ps -o ppid= -p $$)
PARENT_COMMAND=$(ps -o command= -p "$PARENT_PID")
CURRENT_USER=$(whoami)

# Log the diagnostic information.
echo "[$(date '+%Y-%m-%d %H:%M:%S')] [DIAGNOSTIC] check-dns-daemon.sh triggered by user: '${CURRENT_USER}', parent PID: ${PARENT_PID}, parent command: '${PARENT_COMMAND}'" >> "$LOG_FILE"
# --- End Diagnostic Logging ---

# Ensure data directory and log file exist for the daemon.
mkdir -p "$DATA_DIR"
touch "$LOG_FILE"

# Check if the daemon script exists and is executable.
if [ ! -x "$DAEMON_SCRIPT" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: Daemon script not found or not executable: $DAEMON_SCRIPT" >> "$LOG_FILE"
    exit 1
fi

# Attempt to start the daemon in the background.
# The daemon script itself will check if another instance is running and exit if so.
nohup "$DAEMON_SCRIPT" >> "$LOG_FILE" 2>&1 &

exit 0