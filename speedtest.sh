#!/bin/sh

# Proper Speed Testing - Modified from ns_speedtest_v3.sh
# Supports multiple modes: manual, cron-check, auto-run

# Check for cron-check mode first
if [ "$1" = "--cron-check" ]; then
    # Change to script directory
    SCRIPT_DIR=$(dirname "$0")
    cd "$SCRIPT_DIR"
    
    # Check cron debug file size and truncate if over 1MB (1048576 bytes)
    if [ -f "cron-debug.log" ]; then
        debug_size=$(stat -c%s "cron-debug.log" 2>/dev/null || echo 0)
        if [ $debug_size -gt 1048576 ]; then
            tail -500 cron-debug.log > cron-debug.log.tmp
            mv cron-debug.log.tmp cron-debug.log
        fi
    fi
    
    # Debug logging
    echo "[$(date)] Cron-check started from directory: $(pwd)" >> cron-debug.log
    
    # Read next run time from file
    if [ -f ".next_run_time" ] && [ -s ".next_run_time" ]; then
        next_run=$(cat .next_run_time)
        current_time=$(date +%s)
        echo "[$(date)] Found next_run_time: $next_run ($(date -d @$next_run)), current: $current_time" >> cron-debug.log
        
        if [ "$next_run" ] && [ $current_time -ge $next_run ]; then
            # Time to run! Calculate next run time (random time tomorrow)
            # Generate random seconds (0-86399 for 00:00:00 to 23:59:59)
            # Using awk for better portability than $RANDOM
            random_seconds=$(awk 'BEGIN{srand(); print int(rand()*86400)}')
            # Calculate tomorrow's timestamp and add random seconds
            tomorrow_midnight=$(date -d "tomorrow 00:00:00" +%s)
            next_run_time=$((tomorrow_midnight + random_seconds))
            echo $next_run_time > .next_run_time
            
            # Get the default interface
            default_interface=$(ip route | grep default | awk '{print $5}' | head -n1)
            if [ -z "$default_interface" ]; then
                echo "Error: Could not determine default interface" >> speedtest.log
                exit 1
            fi
            
            # Run the actual speed test in auto mode
            echo "[$(date)] Running speed test with interface: $default_interface" >> cron-debug.log
            exec $0 $default_interface --auto-run
        else
            echo "[$(date)] Not time yet. Next run at $next_run, current is $current_time" >> cron-debug.log
        fi
    else
        # First run - set random time for tomorrow
        random_seconds=$(awk 'BEGIN{srand(); print int(rand()*86400)}')
        tomorrow_midnight=$(date -d "tomorrow 00:00:00" +%s)
        next_run_time=$((tomorrow_midnight + random_seconds))
        echo $next_run_time > .next_run_time
        echo "First run scheduled for: $(date -d @$next_run_time)" >> speedtest.log
        echo "[$(date)] Created first run time: $next_run_time ($(date -d @$next_run_time))" >> cron-debug.log
    fi
    exit 0
fi

# Regular speed test logic starts here
if [ -z "$1" ]; then
    echo
    echo "Usage: $0 <interface> [cron|--auto-run]"
    echo "       $0 --cron-check"
    echo
    echo "Examples:"
    echo "  Manual run:     $0 eth0"
    echo "  24-hour mode:   $0 eth0 cron"
    echo "  Cron checker:   $0 --cron-check"
    echo
    exit 1
fi

external_interface=$1
auto_run_mode=0
json_output=0

# Check if we're in auto-run mode (called by cron-check)
if [ "$2" = "--auto-run" ]; then
    auto_run_mode=1
    json_output=1
fi

# Only show tail output if not in auto-run mode
if [ $auto_run_mode -eq 0 ]; then
    touch speedtest.log
    tail speedtest.log -f -n0 &
fi

i="1"
sleepseconds="2"
if [ "$2" = "cron" ]; then
    if [ $auto_run_mode -eq 0 ]; then
        echo "+++++++++++++++++   Running every hour for 24 hours"
    fi
    i="24"
    sleepseconds="3600"
elif [ $auto_run_mode -eq 0 ] && [ -z "$2" ]; then
    echo "+++++++++++++++++   To run every hour for 24 hours, pass 'cron' after interface"
fi

# Initialize JSON variables
json_timestamp=""
json_download_mbps=""
json_upload_mbps=""
json_start_time=""

while [ $i -gt 0 ]; do
    touch /tmp/111.tmp
    temp_file=/tmp/111.tmp
    
    # Record start time for duration calculation
    json_start_time=$(date +%s)
    json_timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")

    #CTRL-C protection
    trap trapint 2
    trapint() {
        killall -q -s 9 wget
        killall -q -s 9 curl
        killall -q -s 9 tail
        rm -f ${temp_file}
        rm -f iPad_Pro_HFR* > /dev/null 2>&1
        rm -f ns_1GB.zip* > /dev/null 2>&1
        rm -f 1GB.zip* > /dev/null 2>&1
        rm -f VTL-ST_1GB.zip > /dev/null 2>&1
        rm -f upload_test.bin > /dev/null 2>&1
        exit 0
    }

    get_ispeed() {
        if [ "$1" = "upload" ]; then
            field=f3
            direction=TX
        else
            field=f2
            direction=RX
        fi

        bytes=$(/sbin/ifconfig $external_interface | grep bytes | grep $direction | cut -d ':' -$field | cut -d ' ' -f1);
        if [ -z "$bytes" ]; then
            bytes=$(/sbin/ifconfig $external_interface | grep bytes | grep $direction | cut -d ':' -$field | cut -d ' ' -f14);
        fi
        echo $bytes;
    }

    write_output() {
        secs=16
        endTime=$(( $(date +%s) + secs ))
        
        if [ "$1" = "upload" ]; then
            interface="get_ispeed upload"
            killprocess=curl
            wgetrunning="yes"
        else
            interface=get_ispeed
            killprocess=curl
            wgetrunning="yes"
        fi
        
        sleep 1
        
        # Variables to track max speed for JSON
        max_mbps=0
        
        while [ $(date +%s) -lt $endTime ] && [ -f ${temp_file} ] && [ ! -z "$wgetrunning" ]; do
            s1=`$interface`;
            sleep 1s;
            s2=`$interface`;
            d=$(($s2-$s1));
            d2=$(($d*8));
            mbps=$(($d2 / 1048576))
            
            # Track max speed
            if [ $mbps -gt $max_mbps ]; then
                max_mbps=$mbps
            fi
            
            # Only output to console if not in auto-run mode
            if [ $auto_run_mode -eq 0 ]; then
                curl_count=$(pgrep -c curl || echo 0)
                echo "         $1 $(($d / 1048576)) MB/s (${mbps}Mb/s) [${curl_count} curl procs]   |  $(date)";
            fi
            
            wgetrunning="$(pgrep $killprocess)"
        done
        
        # Store max speed to temp files for JSON output
        if [ "$1" = "download" ]; then
            echo $max_mbps > /tmp/download_speed.tmp
        else
            echo $max_mbps > /tmp/upload_speed.tmp
        fi
        
        sleep 1
        killall -q -s 9 $killprocess
        sleep 1
    }

    # URL validity checks (only show if not in auto-run mode)
    if [ $auto_run_mode -eq 0 ]; then
        echo
        echo
        echo "Checking download URLs validity..."
        echo "================================="
    fi
    
    # Define URLs
    url1="http://updates-http.cdn-apple.com/2018FallFCS/fullrestores/091-62921/11701D1E-AC8E-11E8-A4EB-C9640A3A3D87/iPad_Pro_HFR_12.0_16A366_Restore.ipsw"
    url2="https://pinescore.com/111/ns_1GB.zip"
    url3="https://virtualmin-london.s3.eu-west-2.amazonaws.com/ns_1GB.zipAWS"
    url4="http://ipv4.download.thinkbroadband.com/1GB.zip"
    url5="http://84.21.152.158/ns_1GB.zipCloudLinux"
    url6="http://virtueazure.pinescore.com/VTL-ST_1GB.zip"
    
    if [ $auto_run_mode -eq 0 ]; then
        # Check each URL
        echo -n "Apple CDN: "
        if curl -I -s --connect-timeout 3 "$url1" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo -n "Pinescore: "
        if curl -I -s --connect-timeout 3 "$url2" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo -n "AWS S3: "
        if curl -I -s --connect-timeout 3 "$url3" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo -n "ThinkBroadband: "
        if curl -I -s --connect-timeout 3 "$url4" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo -n "CloudLinux: "
        if curl -I -s --connect-timeout 3 "$url5" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo -n "VirtueAzure: "
        if curl -I -s --connect-timeout 3 "$url6" | grep -q "200\|302"; then echo "VALID"; else echo "INVALID/UNREACHABLE"; fi
        
        echo "================================="
        echo
        echo "4x simultaneous downloads from multiple sources"
    fi
    
    write_output download >> speedtest.log &
    curl -s -o ns_1GB.zip -O "$url1" \
    -O "$url2" \
    -O "$url3" \
    -O "$url4" \
    -O "$url5" \
    -O "$url6"

    sleep 2
    
    if [ $auto_run_mode -eq 0 ]; then
        echo
        echo
        echo "Checking upload destinations validity..."
        echo "======================================="
        
        echo -n "VirtueAzure FTP: "
        if curl --connect-timeout 3 -s ftp://virtueazure.pinescore.com --user ftp_speedtest:ftp_speedtest 2>&1 | grep -q "530\|Permission denied" || [ $? -eq 0 ]; then
            echo "REACHABLE (auth may fail)"
        else
            echo "UNREACHABLE"
        fi
        
        echo -n "Pinescore FTP: "
        if curl --connect-timeout 3 -s ftp://pinescore.com --user ftp_speedtest.pinescore:ftp_speedtest.pinescore 2>&1 | grep -q "530\|Permission denied" || [ $? -eq 0 ]; then
            echo "REACHABLE (auth may fail)"
        else
            echo "UNREACHABLE"
        fi
        
        echo "======================================="
        echo
        echo "Aggregated upload test"
    fi
    
    # Wait for any residual download traffic to finish
    sleep 3
    
    # Create a larger test file for sustained upload (20MB)
    dd if=/dev/urandom of=upload_test.bin bs=1M count=20 2>/dev/null
    
    # Start measurement first, then begin uploads
    write_output upload >> speedtest.log &
    sleep 1
    
    # Multiple sustained uploads to maximize throughput  
    curl -X POST -F "file=@upload_test.bin" https://httpbin.org/post 2>/dev/null &
    curl -X POST -F "file=@upload_test.bin" https://postman-echo.com/post 2>/dev/null &
    curl -T upload_test.bin https://transfer.sh/upload_test.bin 2>/dev/null &
    curl -X POST -F "file=@upload_test.bin" https://httpbingo.org/post 2>/dev/null &
    
    # Wait for upload measurement to complete (16+ seconds)
    sleep 20
    
    # Clean up download and upload files
    rm -f iPad_Pro_HFR* > /dev/null 2>&1
    rm -f ns_1GB.zip* > /dev/null 2>&1
    rm -f 1GB.zip* > /dev/null 2>&1
    rm -f VTL-ST_1GB.zip > /dev/null 2>&1
    rm -f upload_test.bin > /dev/null 2>&1
    
    rm -f ${temp_file} 2> /dev/null
    
    # Calculate duration
    json_end_time=$(date +%s)
    json_duration=$((json_end_time - json_start_time))
    
    # Write JSON output if in auto-run mode
    if [ $json_output -eq 1 ]; then
        run_type="auto"
        if [ "$2" = "cron" ]; then
            run_type="cron"
        fi
        
        # Check file size and truncate if over 10MB (10485760 bytes)
        if [ -f "data/results.json" ]; then
            file_size=$(stat -c%s "data/results.json" 2>/dev/null || echo 0)
            if [ $file_size -gt 10485760 ]; then
                # Keep last 1000 lines and truncate
                tail -1000 data/results.json > data/results.json.tmp
                mv data/results.json.tmp data/results.json
                echo "JSON file truncated at $(date)" >> speedtest.log
            fi
        fi
        
        # Read speeds from temp files
        if [ -f "/tmp/download_speed.tmp" ]; then
            json_download_mbps=$(cat /tmp/download_speed.tmp)
            rm -f /tmp/download_speed.tmp
        else
            json_download_mbps=0
        fi
        
        if [ -f "/tmp/upload_speed.tmp" ]; then
            json_upload_mbps=$(cat /tmp/upload_speed.tmp)
            rm -f /tmp/upload_speed.tmp
        else
            json_upload_mbps=0
        fi
        
        # Create JSON object and append to file
        json_line="{\"timestamp\":\"$json_timestamp\",\"download_mbps\":$json_download_mbps,\"upload_mbps\":$json_upload_mbps,\"duration\":$json_duration,\"run_type\":\"$run_type\",\"interface\":\"$external_interface\"}"
        echo "$json_line" >> data/results.json
        
        # Also log to regular log
        echo "Speed test completed at $json_timestamp - Download: ${json_download_mbps}Mbps, Upload: ${json_upload_mbps}Mbps" >> speedtest.log
    fi
    
    sleep $sleepseconds
    i=$((i-1))
done

# Kill tail only if we started it
if [ $auto_run_mode -eq 0 ]; then
    killall -q -s 9 tail
fi

if [ $auto_run_mode -eq 0 ]; then
    echo
    echo "Speed test completed successfully!"
fi