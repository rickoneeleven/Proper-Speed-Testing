# Proper Speed Testing

A reliable, automated internet speed testing system that runs daily speed tests and provides a web dashboard for monitoring connection performance over time.

## Features

- **Automated Daily Testing**: Runs once per day at a random time
- **Accurate Measurements**: Realistic download/upload speed detection
- **Web Dashboard**: Real-time results with statistics and history
- **Data Management**: Auto-truncating files prevent disk space issues
- **Multiple Modes**: Manual, automated, and continuous testing options
- **Lightweight**: Compatible with basic shell environments (firewalls, routers)

## Quick Setup

### 1. Clone and Install

```bash
git clone https://github.com/rickoneeleven/Proper-Speed-Testing.git
cd Proper-Speed-Testing
chmod +x speedtest.sh
```

### 2. Install Dependencies

Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install -y net-tools curl
```

CentOS/RHEL:
```bash
sudo yum install -y net-tools curl
```

### 3. Setup Automated Testing

Add the cron job for automated daily testing:

```bash
# Edit your crontab
crontab -e

# Add this line (replace /full/path/to with your actual path):
* * * * * /full/path/to/speedtest.sh --cron-check
```

**Example:**
```bash
* * * * * /home/user/Proper-Speed-Testing/speedtest.sh --cron-check
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

Open `index.html` in a web browser or set up a web server:

```bash
# Simple Python web server (if available)
python3 -m http.server 8080

# Then visit: http://localhost:8080
```

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

By default, tests run once daily at a random time. To modify:

```bash
# Force next test to run in 5 minutes
echo $(($(date +%s) + 300)) > .next_run_time

# Remove scheduling file to reset
rm .next_run_time
```

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
curl -I https://httpbin.org/get
curl -I https://postman-echo.com/get

# Check interface exists
ifconfig your_interface_name
```

### Debug Mode

```bash
# Run with debugging output
bash -x ./speedtest.sh eth0

# Check cron debug logs
tail -f cron-debug.log
```

## Data Management

The system automatically manages file sizes:

- **results.json**: Truncated at 10MB (keeps last 1000 results)
- **cron-debug.log**: Truncated at 1MB (keeps last 500 entries)
- **Temporary files**: Auto-cleaned after each test

## Advanced Usage

### Integration with Monitoring

```bash
# Export data for external tools
jq '.download_mbps' data/results.json

# Get average speeds
jq '[.download_mbps] | add/length' data/results.json
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

- **OS**: Linux with basic shell support
- **Dependencies**: `curl`, `ifconfig`, `awk`, `dd`
- **Network**: Internet access for speed tests
- **Storage**: ~10MB for long-term data collection
- **Cron**: For automated scheduling

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