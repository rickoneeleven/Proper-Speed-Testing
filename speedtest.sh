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
# Auto-detect interface if not provided
if [ -z "$1" ]; then
    # Auto-detect default interface
    external_interface=$(ip route | grep default | awk '{print $5}' | head -n1)
    if [ -z "$external_interface" ]; then
        echo "Error: Could not auto-detect default network interface"
        echo "Please specify interface manually:"
        echo
        echo "Usage: $0 [interface] [cron|--auto-run]"
        echo "       $0 --cron-check"
        echo
        echo "Examples:"
        echo "  Auto-detect:    $0"
        echo "  Manual run:     $0 eth0"
        echo "  24-hour mode:   $0 eth0 cron"
        echo "  Cron checker:   $0 --cron-check"
        echo
        echo "Find your interface: ip route | grep default"
        exit 1
    fi
    echo "Auto-detected interface: $external_interface"
else
    external_interface=$1
fi
auto_run_mode=0
json_output=0

# Check if another speedtest script is already running (exclude current process)
# Temporarily disabled for testing
# script_name=$(basename "$0")
# running_count=$(ps aux | grep "./$script_name" | grep -v grep | grep -v "cron-check" | wc -l)
# if [ $running_count -gt 1 ]; then
#     json_timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
#     mkdir -p data
#     echo "{\"timestamp\":\"$json_timestamp\",\"download_mbps\":0,\"upload_mbps\":0,\"duration\":0,\"run_type\":\"failed\",\"interface\":\"$external_interface\",\"download_status\":\"FAILED: Test already running\",\"upload_status\":\"FAILED: Test already running\",\"traceroute\":\"\",\"download_details\":\"Test|FAILED|Another speedtest is already in progress\",\"upload_details\":\"Test|FAILED|Another speedtest is already in progress\"}" >> data/results.json
#     echo "ERROR: Another speedtest is already running. Test aborted."
#     exit 1
# fi

# Check if we're in auto-run mode (called by cron-check)
if [ "$2" = "--auto-run" ]; then
    auto_run_mode=1
    json_output=1
else
    # Enable JSON output for manual runs too
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
        rm -f ns_1GB*.zip > /dev/null 2>&1
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
            # Add 6-second warmup delay for uploads
            warmup_delay=6
        else
            interface=get_ispeed
            killprocess=curl
            wgetrunning="yes"
            # Add 3-second warmup delay for downloads too
            warmup_delay=3
        fi
        
        sleep 1
        
        # Variables to track speeds for averaging
        max_mbps=0
        total_mbps=0
        count=0
        measurements_to_skip=$warmup_delay  # Skip initial measurements during warmup
        
        while [ $(date +%s) -lt $endTime ] && [ -f ${temp_file} ] && [ ! -z "$wgetrunning" ]; do
            s1=`$interface`;
            sleep 1s;
            s2=`$interface`;
            d=$(($s2-$s1));
            d2=$(($d*8));
            mbps=$(($d2 / 1048576))
            
            # Skip measurements during warmup period
            if [ $measurements_to_skip -gt 0 ]; then
                measurements_to_skip=$((measurements_to_skip - 1))
                # Only output to console if not in auto-run mode
                if [ $auto_run_mode -eq 0 ]; then
                    curl_count=$(pgrep -c curl || echo 0)
                    echo "         $1 $(($d / 1048576)) MB/s (${mbps}Mb/s) [${curl_count} curl procs] WARMUP   |  $(date)";
                fi
            else
                # After warmup, track speeds for averaging
                total_mbps=$((total_mbps + mbps))
                count=$((count + 1))
                
                # Still track max for reference
                if [ $mbps -gt $max_mbps ]; then
                    max_mbps=$mbps
                fi
                
                # Only output to console if not in auto-run mode
                if [ $auto_run_mode -eq 0 ]; then
                    curl_count=$(pgrep -c curl || echo 0)
                    echo "         $1 $(($d / 1048576)) MB/s (${mbps}Mb/s) [${curl_count} curl procs]   |  $(date)";
                fi
            fi
            
            wgetrunning="$(pgrep $killprocess)"
        done
        
        # Use maximum speed instead of average for better representation
        final_speed=$max_mbps
        
        # Store maximum speed to temp files for JSON output
        if [ "$1" = "download" ]; then
            echo $final_speed > /tmp/download_speed.tmp
        else
            echo $final_speed > /tmp/upload_speed.tmp
        fi
        
        sleep 1
        killall -q -s 9 $killprocess
        sleep 1
    }

    # Define URLs and test endpoints
    url1="http://updates-http.cdn-apple.com/2018FallFCS/fullrestores/091-62921/11701D1E-AC8E-11E8-A4EB-C9640A3A3D87/iPad_Pro_HFR_12.0_16A366_Restore.ipsw"
    url2="https://pinescore.com/111/ns_1GB.zip"
    url3="https://virtualmin-london.s3.eu-west-2.amazonaws.com/ns_1GB.zipAWS"
    url4="http://ipv4.download.thinkbroadband.com/1GB.zip"
    url5="http://84.21.152.158/ns_1GB.zipCloudLinux"
    url6="http://virtueazure.pinescore.com/VTL-ST_1GB.zip"
    
    # Extract hostnames for diagnostics
    host1="updates-http.cdn-apple.com"
    host2="pinescore.com"
    host3="virtualmin-london.s3.eu-west-2.amazonaws.com"
    host4="ipv4.download.thinkbroadband.com"
    host5="84.21.152.158"
    host6="virtueazure.pinescore.com"
    
    # Initialize diagnostic data
    download_status=""
    upload_status=""
    traceroute_data=""
    download_details=""
    upload_details=""
    
    # Test download endpoints and collect diagnostics
    if [ $auto_run_mode -eq 0 ]; then
        echo
        echo
        echo "Checking download URLs validity..."
        echo "================================="
    fi
    
    # Test each endpoint and collect status with detailed output
    test_endpoint() {
        local url="$1"
        local name="$2"
        local host="$3"
        
        # First do a quick header check
        curl_header=$(curl -I -s --connect-timeout 3 "$url" 2>&1 | head -1)
        
        if echo "$curl_header" | grep -q "200\|302"; then
            status="VALID"
            download_status="${download_status}${name}:OK;"
            
            # For valid endpoints, capture actual download progress (3 seconds)
            if [ $auto_run_mode -eq 0 ]; then
                echo -n "$name: $status - Testing speed... "
            fi
            
            # Capture download progress output (3 second test)
            progress_output=$(curl -o /dev/null --max-time 3 "$url" 2>&1 | tail -3)
            
            # Extract meaningful info from progress output
            # Look for lines with download speed info (support both k and M formats)
            speed_line=$(echo "$progress_output" | grep -E "[0-9.]+[kM]" | tail -1)
            
            if [ -n "$speed_line" ]; then
                # Extract the download speed (supports both "5839k" and "68.1M" formats)
                dl_speed_k=$(echo "$speed_line" | grep -oE "[0-9.]+k" | tail -1 | sed 's/k//')
                dl_speed_m=$(echo "$speed_line" | grep -oE "[0-9.]+M" | tail -1 | sed 's/M//')
                # Extract file size if visible (like "1024M")
                file_size_m=$(echo "$progress_output" | grep -oE "[0-9]+M" | head -1 | sed 's/M//')
                
                if [ -n "$dl_speed_k" ]; then
                    # Convert k/s to Mbit/s (multiply by 8, divide by 1000)
                    dl_speed_mbit=$(echo "$dl_speed_k" | awk '{printf "%.1f", $1 * 8 / 1000}')
                elif [ -n "$dl_speed_m" ]; then
                    # Convert M/s to Mbit/s (multiply by 8)
                    dl_speed_mbit=$(echo "$dl_speed_m" | awk '{printf "%.1f", $1 * 8}')
                else
                    dl_speed_mbit=""
                fi
                
                if [ -n "$dl_speed_mbit" ]; then
                    summary="${curl_header} | Speed: ${dl_speed_mbit} Mbit/s"
                    if [ -n "$file_size_m" ]; then
                        summary="${summary} | File Size: ${file_size_m} MB"
                    fi
                else
                    summary="${curl_header} | Testing completed"
                fi
            else
                summary="${curl_header}"
            fi
            
            if [ $auto_run_mode -eq 0 ]; then
                echo "done"
            fi
        else
            status="INVALID"
            download_status="${download_status}${name}:FAIL;"
            summary="${curl_header}"
            
            if [ $auto_run_mode -eq 0 ]; then
                echo "$name: $status"
            fi
        fi
        
        # Store summary for web interface (first 400 chars now, safe for JSON)
        summary=$(echo "$summary" | cut -c1-400 | tr '"' "'" | tr '\n\r' ' ')
        download_details="${download_details}${name}|${status}|${summary};"
        
        # Run traceroute in background (limit to 10 hops for speed)
        if [ "$status" = "VALID" ]; then
            (traceroute -m 10 -w 1 "$host" 2>/dev/null | tail -n +2 | head -5 > "/tmp/trace_${name}.tmp") &
        fi
    }
    
    test_endpoint "$url1" "Apple CDN" "$host1"
    test_endpoint "$url2" "Pinescore" "$host2"
    test_endpoint "$url3" "AWS S3" "$host3"
    test_endpoint "$url4" "ThinkBroadband" "$host4"
    test_endpoint "$url5" "CloudLinux" "$host5"
    test_endpoint "$url6" "VirtueAzure" "$host6"
    
    if [ $auto_run_mode -eq 0 ]; then
        echo "================================="
        echo
        echo "6x simultaneous downloads from multiple sources"
    fi
    
    write_output download >> speedtest.log &
    
    # Start 6 simultaneous downloads in background
    curl -s -o ns_1GB_1.zip "$url1" &
    curl -s -o ns_1GB_2.zip "$url2" &
    curl -s -o ns_1GB_3.zip "$url3" &
    curl -s -o ns_1GB_4.zip "$url4" &
    curl -s -o ns_1GB_5.zip "$url5" &
    curl -s -o ns_1GB_6.zip "$url6" &
    
    # Wait for write_output to finish (16 seconds) and kill downloads
    wait $!

    sleep 2
    
    # Test upload endpoints
    if [ $auto_run_mode -eq 0 ]; then
        echo
        echo
        echo "Checking upload destinations validity..."
        echo "======================================="
    fi
    
    # Test upload endpoints and collect status
    test_upload_endpoint() {
        local url="$1"
        local name="$2"
        local host="$3"
        
        if curl -I -s --connect-timeout 3 "$url" | grep -q "200\|404\|405"; then
            status="REACHABLE"
            upload_status="${upload_status}${name}:OK;"
        else
            status="UNREACHABLE"
            upload_status="${upload_status}${name}:FAIL;"
        fi
        
        if [ $auto_run_mode -eq 0 ]; then
            echo "$name: $status"
        fi
        
        # Run traceroute for upload endpoints too
        if [ "$status" = "REACHABLE" ]; then
            (traceroute -m 10 -w 1 "$host" 2>/dev/null | tail -n +2 | head -5 > "/tmp/trace_upload_${name}.tmp") &
        fi
    }
    
    # Test FTP endpoints with error reporting and detailed output capture
    test_ftp_endpoint() {
        local url="$1"
        local name="$2"
        local host="$3"
        
        # Test FTP connectivity using curl and capture error
        error_output=$(curl -s --connect-timeout 3 "$url" 2>&1)
        exit_code=$?
        
        if [ $exit_code -eq 0 ]; then
            status="REACHABLE"
            upload_status="${upload_status}${name}:OK;"
            
            # For reachable endpoints, test upload speed with 1GB file for realistic measurement
            if [ $auto_run_mode -eq 0 ]; then
                echo -n "$name: $status - Testing upload speed... "
            fi
            
            # Create 1GB upload test file if it doesn't exist (reuse the one from main test)
            if [ ! -f upload_test.bin ] || [ $(stat -c%s upload_test.bin 2>/dev/null || echo 0) -ne 1073741824 ]; then
                if [ $auto_run_mode -eq 0 ]; then
                    echo -n "creating 1GB file... "
                fi
                dd if=/dev/urandom of=upload_test.bin bs=1M count=1024 2>/dev/null
            fi
            
            # Test upload speed (5 second timeout for more realistic measurement)
            upload_output=$(curl -T upload_test.bin "$url" --max-time 5 2>&1 | tail -5)
            
            # Extract upload speed (support both k and M formats)
            speed_line=$(echo "$upload_output" | grep -E "[0-9.]+[kM]" | tail -1)
            if [ -n "$speed_line" ]; then
                ul_speed_k=$(echo "$speed_line" | grep -oE "[0-9.]+k" | tail -1 | sed 's/k//')
                ul_speed_m=$(echo "$speed_line" | grep -oE "[0-9.]+M" | tail -1 | sed 's/M//')
                
                if [ -n "$ul_speed_k" ]; then
                    # Convert k/s to Mbit/s
                    ul_speed_mbit=$(echo "$ul_speed_k" | awk '{printf "%.1f", $1 * 8 / 1000}')
                elif [ -n "$ul_speed_m" ]; then
                    # Convert M/s to Mbit/s (multiply by 8)
                    ul_speed_mbit=$(echo "$ul_speed_m" | awk '{printf "%.1f", $1 * 8}')
                else
                    ul_speed_mbit=""
                fi
                
                if [ -n "$ul_speed_mbit" ]; then
                    # Don't include the directory listing, just show status and speed
                    summary="FTP Connected | Upload Speed: ${ul_speed_mbit} Mbit/s"
                else
                    summary="FTP Connected | Upload test completed"
                fi
            else
                summary="FTP Connected"
            fi
            
            if [ $auto_run_mode -eq 0 ]; then
                echo "done"
            fi
        else
            status="UNREACHABLE"
            # Extract meaningful error message
            if echo "$error_output" | grep -q "Connection refused"; then
                error_msg="Connection refused"
            elif echo "$error_output" | grep -q "timeout"; then
                error_msg="Timeout"
            elif echo "$error_output" | grep -q "not resolve"; then
                error_msg="DNS resolution failed"
            elif echo "$error_output" | grep -q "Access denied"; then
                error_msg="Access denied"
            else
                error_msg="Connection failed"
            fi
            upload_status="${upload_status}${name}:FAIL(${error_msg});"
            summary="${error_output}"
            
            if [ $auto_run_mode -eq 0 ]; then
                echo "$name: $status - $error_msg"
            fi
        fi
        
        # Store summary for web interface (first 400 chars, safe for JSON)
        summary=$(echo "$summary" | head -1 | cut -c1-400 | tr '"' "'" | tr '\n\r' ' ')
        upload_details="${upload_details}${name}|${status}|${summary};"
        
        # Run traceroute for FTP endpoints too
        if [ "$status" = "REACHABLE" ]; then
            (traceroute -m 10 -w 1 "$host" 2>/dev/null | tail -n +2 | head -5 > "/tmp/trace_upload_${name}.tmp") &
        fi
    }
    
    # Test FTP endpoints only (HTTP endpoints won't support 1GB uploads)
    test_ftp_endpoint "ftp://ftp_speedtest.pinescore:ftp_speedtest.pinescore@pinescore.com/" "PinescoreFTP" "pinescore.com"
    test_ftp_endpoint "ftp://ftp_speedtest:ftp_speedtest@virtueazure.pinescore.com/" "VirtueAzureFTP" "virtueazure.pinescore.com"
    
    if [ $auto_run_mode -eq 0 ]; then
        echo "======================================="
        echo
        echo "Aggregated upload test"
    fi
    
    # Wait for any residual download traffic to finish
    sleep 3
    
    # Create 1GB upload test file for realistic speed measurement (like original script)
    # Only create if it doesn't exist or is wrong size to avoid recreating unnecessarily
    if [ ! -f upload_test.bin ] || [ $(stat -c%s upload_test.bin 2>/dev/null || echo 0) -ne 1073741824 ]; then
        if [ $auto_run_mode -eq 0 ]; then
            echo "Creating 1GB upload test file..."
        fi
        dd if=/dev/urandom of=upload_test.bin bs=1M count=1024 2>/dev/null
    else
        if [ $auto_run_mode -eq 0 ]; then
            echo "Using existing 1GB upload test file"
        fi
    fi
    
    # Start measurement first, then begin uploads (like original script)
    write_output upload >> speedtest.log &
    sleep 1
    
    # FTP uploads using credentials from original script (16 second timeout like original)
    curl -T upload_test.bin ftp://ftp_speedtest.pinescore:ftp_speedtest.pinescore@pinescore.com/ --max-time 16 2>/dev/null &
    curl -T upload_test.bin ftp://ftp_speedtest:ftp_speedtest@virtueazure.pinescore.com/ --max-time 16 2>/dev/null &
    
    # Wait for upload measurement to complete (16+ seconds like original)
    sleep 18
    
    # Clean up download and upload files
    rm -f iPad_Pro_HFR* > /dev/null 2>&1
    rm -f ns_1GB*.zip > /dev/null 2>&1
    rm -f 1GB.zip* > /dev/null 2>&1
    rm -f VTL-ST_1GB.zip > /dev/null 2>&1
    rm -f upload_test.bin > /dev/null 2>&1
    
    rm -f ${temp_file} 2> /dev/null
    
    # Calculate duration
    json_end_time=$(date +%s)
    json_duration=$((json_end_time - json_start_time))
    
    # Write JSON output for all runs
    if [ $json_output -eq 1 ]; then
        if [ $auto_run_mode -eq 1 ]; then
            run_type="auto"
        else
            run_type="manual"
        fi
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
        
        # Wait for traceroute data to complete
        sleep 3
        
        # Collect traceroute data
        traceroute_data=""
        for name in "Apple_CDN" "Pinescore" "AWS_S3" "ThinkBroadband" "CloudLinux" "VirtueAzure" "upload_PinescoreFTP" "upload_VirtueAzureFTP"; do
            if [ -f "/tmp/trace_${name}.tmp" ]; then
                trace_content=$(cat "/tmp/trace_${name}.tmp" | tr '\n' '|' | sed 's/|$//')
                traceroute_data="${traceroute_data}${name}:${trace_content};"
                rm -f "/tmp/trace_${name}.tmp"
            fi
        done
        
        # Create data directory if it doesn't exist
        mkdir -p data
        
        # Create JSON object with diagnostic data
        json_line="{\"timestamp\":\"$json_timestamp\",\"download_mbps\":$json_download_mbps,\"upload_mbps\":$json_upload_mbps,\"duration\":$json_duration,\"run_type\":\"$run_type\",\"interface\":\"$external_interface\",\"download_status\":\"$download_status\",\"upload_status\":\"$upload_status\",\"traceroute\":\"$traceroute_data\",\"download_details\":\"$download_details\",\"upload_details\":\"$upload_details\"}"
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