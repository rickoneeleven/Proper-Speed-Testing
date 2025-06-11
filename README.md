# Proper Speed Testing

A reliable, automated internet speed testing system that runs daily speed tests and provides a web dashboard for monitoring connection performance over time.

## Features

- **Automated Testing**: Daily or hourly testing at random times
- **Accurate Measurements**: Realistic download/upload speed detection with warmup periods
- **Web Dashboard**: Real-time results with statistics, detailed diagnostics, and history
- **Endpoint Testing**: Tests 6 download sources and 2 FTP upload servers with speed measurement
- **Network Diagnostics**: Traceroute data and connection status for all endpoints
- **Data Management**: Auto-truncating files prevent disk space issues
- **Multiple Modes**: Manual, automated, continuous, and hourly testing options
- **Lightweight**: Compatible with basic shell environments (firewalls, routers)

## Quick Setup

### 1. Clone and Install

```bash
git clone https://github.com/rickoneeleven/Proper-Speed-Testing.git
cd Proper-Speed-Testing
chmod +x speedtest.sh
```

### 2. Install Dependencies

**Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install -y net-tools curl
```

**CentOS/RHEL:**
```bash
sudo yum install -y net-tools curl
```

**Alpine Linux (routers/firewalls):**
```bash
apk add net-tools curl
```

**Note:** `net-tools` provides the `ifconfig` command required for interface monitoring. Modern systems may use `ip` command, but `ifconfig` is needed for compatibility.

### 3. Setup Automated Testing

Add the cron job for automated testing:

```bash
# Edit your crontab
crontab -e

# For DAILY testing at random times (default):
* * * * * /full/path/to/speedtest.sh --cron-check

# For HOURLY testing at random times:
* * * * * /full/path/to/speedtest.sh --cron-check --hourly
```

**Example:**
```bash
# Daily testing
* * * * * /home/user/Proper-Speed-Testing/speedtest.sh --cron-check

# Hourly testing
* * * * * /home/user/Proper-Speed-Testing/speedtest.sh --cron-check --hourly
```

### 4. Verify Setup

Test that everything works:

```bash
# Run a manual test (replace 'eth0' with your interface)
./speedtest.sh eth0

# Check that cron scheduling works
./speedtest.sh --cron-check

# Verify cron created the scheduling file
ls -la .next_run_time
```

## Usage

### Manual Testing

```bash
# Single test
./speedtest.sh eth0

# 24-hour continuous testing
./speedtest.sh eth0 cron
```

### View Results

**Option 1: Direct File Access**
Open `index.html` directly in your browser (file:// protocol).

**Option 2: Local Web Server**
```bash
# Simple Python web server (if available)
python3 -m http.server 8080

# Then visit: http://localhost:8080
```

**Option 3: Production Web Server**
Place files in your web server directory (Apache, Nginx, etc.).

The dashboard shows:
- Real-time speed statistics and averages
- Detailed endpoint testing results with actual transfer speeds
- Network diagnostics including traceroute information
- Historical data with clickable test details
- Next scheduled test time

### Check System Status

```bash
# View recent cron activity
tail -20 cron-debug.log

# View speed test logs
tail -20 speedtest.log

# Check when next test is scheduled
cat .next_run_time | xargs -I {} date -d @{}
```

## File Structure

```
├── speedtest.sh           # Main speed test script
├── index.html            # Web dashboard
├── data/
│   └── results.json      # Speed test results (auto-managed)
├── .next_run_time        # Next scheduled test time
├── cron-debug.log        # Cron activity log (auto-managed)
├── speedtest.log         # General activity log
├── CLAUDE.md             # Technical documentation
└── README.md             # This file
```

## Configuration

### Network Interface Detection

The script auto-detects your default network interface for cron runs. For manual runs, specify your interface:

```bash
# Find your interface name
ip route | grep default
# or
ifconfig

# Common interface names:
# - eth0, eth1 (Ethernet)
# - wlan0, wlan1 (WiFi)  
# - ens18, enp0s3 (Modern Linux)
```

### Customizing Test Schedule

Tests can run either daily or hourly at random times:

```bash
# Switch to hourly testing
crontab -e
# Change: * * * * * /path/to/speedtest.sh --cron-check
# To:     * * * * * /path/to/speedtest.sh --cron-check --hourly

# Force next test to run in 5 minutes
echo $(($(date +%s) + 300)) > .next_run_time

# Remove scheduling file to reset
rm .next_run_time
```

**Testing Modes:**
- **Daily (default)**: Random time each day (0:00-23:59)
- **Hourly**: Random minute each hour (0-59)

## Troubleshooting

### Cron Not Running

```bash
# Check if cron service is running
sudo systemctl status cron   # Ubuntu/Debian
sudo systemctl status crond  # CentOS/RHEL

# Verify crontab entry
crontab -l | grep speedtest

# Check cron logs
tail -f /var/log/cron
```

### Permission Issues

```bash
# Make script executable
chmod +x speedtest.sh

# Check file permissions
ls -la speedtest.sh

# Fix if needed
chmod 755 speedtest.sh
```

### Network Issues

```bash
# Test connectivity to speed test servers
curl -I https://pinescore.com/111/ns_1GB.zip
curl -I http://ipv4.download.thinkbroadband.com/1GB.zip

# Check interface exists and has traffic
ifconfig your_interface_name

# Test FTP connectivity
curl -I ftp://ftp_speedtest.pinescore:ftp_speedtest.pinescore@pinescore.com/
```

### Debug Mode

```bash
# Run with debugging output
bash -x ./speedtest.sh

# Check cron debug logs
tail -f cron-debug.log
```

### Emergency Stop Commands

```bash
# Kill all downloads
sudo pkill -f "curl.*ns_1GB"

# Kill all uploads
sudo pkill -f "curl.*-T"

# Kill all speed test processes
sudo pkill -f speedtest.sh; sudo pkill curl
```

## Data Management

The system automatically manages file sizes:

- **results.json**: Truncated at 10MB (keeps last 1000 results)
- **cron-debug.log**: Truncated at 1MB (keeps last 500 entries)
- **Temporary files**: Auto-cleaned after each test

## Project Status

**Current Version: Production Ready ✅**

Recent improvements:
- **Hourly testing mode**: New --hourly option for more frequent monitoring
- **Fixed upload speed measurement**: Now uses average speeds with warmup periods instead of burst speeds
- **Enhanced endpoint testing**: Real-time speed capture for all download and upload endpoints
- **Improved web dashboard**: Shows actual transfer speeds, file sizes, and detailed diagnostics
- **Better cache handling**: Frontend now properly refreshes scheduled test times
- **Accurate speed reporting**: Realistic speeds matching connection capabilities

The system is fully functional and ready for long-term deployment.

## Advanced Usage

### Integration with Monitoring

```bash
# Export data for external tools
jq '.download_mbps' data/results.json

# Get average speeds
jq '[.download_mbps] | add/length' data/results.json

# View latest test with all details
tail -1 data/results.json | jq '.'
```

### Web Server Setup

For permanent web access, configure a web server:

**Apache:**
```apache
<Directory "/path/to/Proper-Speed-Testing">
    AllowOverride None
    Require all granted
</Directory>
```

**Nginx:**
```nginx
location /speedtest {
    alias /path/to/Proper-Speed-Testing;
    index index.html;
}
```

## Requirements

- **OS**: Linux with basic shell support (bash/sh)
- **Dependencies**: 
  - `curl` (for HTTP/FTP transfers and speed testing)
  - `net-tools` package (provides `ifconfig` for interface monitoring)
  - `awk` (for random number generation and text processing)
  - `dd` (for creating test files)
  - `traceroute` (optional, for network diagnostics)
- **Network**: Internet access for speed tests to external servers
- **Storage**: ~10MB for long-term data collection (auto-managed)
- **Cron**: For automated scheduling (standard on all Linux systems)

## Compatibility

Tested on:
- Ubuntu 18.04+
- Debian 9+
- CentOS 7+
- Most routers/firewalls with busybox

## License

MIT License - see repository for details

## Support

For issues, feature requests, or questions:
- GitHub Issues: https://github.com/rickoneeleven/Proper-Speed-Testing/issues
- Check `CLAUDE.md` for technical details