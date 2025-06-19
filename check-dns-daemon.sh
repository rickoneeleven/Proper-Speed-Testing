#!/bin/bash

# DNS Daemon Process Checker
# Runs every minute via cron to ensure DNS monitoring daemon is running

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DAEMON_SCRIPT="$SCRIPT_DIR/dns-monitor-daemon.php"
PID_FILE="$SCRIPT_DIR/data/dns-daemon.pid"
LOG_FILE="$SCRIPT_DIR/data/dns-daemon.log"
DATA_DIR="$SCRIPT_DIR/data"

# Ensure data directory exists
mkdir -p "$DATA_DIR"

# Function to log messages
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Function to check if daemon is running
is_daemon_running() {
    local heartbeat_file="$DATA_DIR/dns-heartbeat.lock"
    
    # Check if heartbeat file exists
    if [ -f "$heartbeat_file" ]; then
        # Check if file was modified in last 5 minutes (300 seconds)
        local file_age=$(( $(date +%s) - $(stat -c %Y "$heartbeat_file" 2>/dev/null || echo 0) ))
        if [ "$file_age" -lt 300 ]; then
            log_message "DNS daemon is active (heartbeat file age: ${file_age}s)"
            return 0  # Running
        else
            log_message "Heartbeat file is stale (age: ${file_age}s), daemon may be dead"
            rm -f "$heartbeat_file"
            return 1  # Not running
        fi
    fi
    
    # Fallback: check PID file method
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE" 2>/dev/null)
        if [ -n "$pid" ] && [ "$pid" -gt 0 ]; then
            if kill -0 "$pid" 2>/dev/null; then
                return 0  # Running
            else
                rm -f "$PID_FILE"
                return 1  # Not running
            fi
        fi
    fi
    return 1  # Not running
}

# Function to start the daemon
start_daemon() {
    # Double-check daemon isn't running before starting
    if is_daemon_running; then
        log_message "DNS daemon already running, skipping start attempt"
        return 0
    fi
    
    log_message "Starting DNS monitoring daemon..."
    
    # Start daemon in background
    nohup php "$DAEMON_SCRIPT" >> "$LOG_FILE" 2>&1 &
    local daemon_pid=$!
    
    # Wait a moment for startup
    sleep 3
    
    # Verify it started successfully by checking if PID file was created
    if [ -f "$PID_FILE" ]; then
        local actual_pid=$(cat "$PID_FILE" 2>/dev/null)
        if [ -n "$actual_pid" ] && kill -0 "$actual_pid" 2>/dev/null; then
            log_message "DNS daemon started successfully with PID $actual_pid"
            return 0
        fi
    fi
    
    log_message "Failed to start DNS daemon"
    return 1
}

# Function to get daemon uptime
get_daemon_uptime() {
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE" 2>/dev/null)
        if [ -n "$pid" ] && [ "$pid" -gt 0 ]; then
            # Get process start time (Linux)
            if [ -f "/proc/$pid/stat" ]; then
                local start_time=$(awk '{print $22}' "/proc/$pid/stat" 2>/dev/null)
                if [ -n "$start_time" ]; then
                    local boot_time=$(awk '/btime/ {print $2}' /proc/stat 2>/dev/null)
                    local clock_ticks=$(getconf CLK_TCK 2>/dev/null || echo 100)
                    local current_time=$(date +%s)
                    local process_start=$((boot_time + start_time / clock_ticks))
                    local uptime=$((current_time - process_start))
                    echo "$uptime"
                    return 0
                fi
            fi
        fi
    fi
    echo "0"
}

# Main logic
main() {
    # Use lock file to prevent multiple cron instances
    local lock_file="$DATA_DIR/dns-cron.lock"
    
    # Check if another cron instance is running
    if [ -f "$lock_file" ]; then
        local lock_pid=$(cat "$lock_file" 2>/dev/null)
        if [ -n "$lock_pid" ] && kill -0 "$lock_pid" 2>/dev/null; then
            # Another instance is running, exit quietly
            exit 0
        else
            # Stale lock file, remove it
            rm -f "$lock_file"
        fi
    fi
    
    # Create lock file
    echo $$ > "$lock_file"
    
    # Ensure lock file is removed on exit
    trap "rm -f '$lock_file'" EXIT
    
    # Check if daemon script exists
    if [ ! -f "$DAEMON_SCRIPT" ]; then
        log_message "ERROR: Daemon script not found: $DAEMON_SCRIPT"
        exit 1
    fi
    
    # Check if daemon is running
    if is_daemon_running; then
        # Daemon is running - log uptime occasionally (every 10 minutes)
        local minute=$(date '+%M')
        if [ $((minute % 10)) -eq 0 ]; then
            local uptime=$(get_daemon_uptime)
            if [ "$uptime" -gt 0 ]; then
                local hours=$((uptime / 3600))
                local minutes=$(((uptime % 3600) / 60))
                log_message "DNS daemon is running (uptime: ${hours}h ${minutes}m)"
            else
                log_message "DNS daemon is running"
            fi
        fi
    else
        # Daemon is not running - start it
        log_message "DNS daemon is not running, attempting to start..."
        
        if start_daemon; then
            log_message "DNS daemon started successfully"
        else
            log_message "ERROR: Failed to start DNS daemon"
            exit 1
        fi
    fi
}

# Run main function
main "$@"