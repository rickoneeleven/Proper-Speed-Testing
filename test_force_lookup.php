#!/usr/bin/env php
<?php

function testForceLookupFunctionality(): bool {
    $performanceFile = __DIR__ . '/data/dns_performance.json';
    $logFile = __DIR__ . '/data/dns-daemon.log';
    
    echo "Testing Force Lookup Button Functionality\n";
    echo "=========================================\n\n";
    
    // Test 1: Capture initial state
    echo "Test 1: Capture baseline DNS performance data\n";
    
    if (!file_exists($performanceFile)) {
        echo "❌ FAIL: Performance file missing - daemon not running or no data yet\n";
        return false;
    }
    
    $initialData = json_decode(file_get_contents($performanceFile), true);
    if (!$initialData || !isset($initialData['tests'])) {
        echo "❌ FAIL: Invalid initial performance data structure\n";
        return false;
    }
    
    $initialTestCount = count($initialData['tests']);
    $initialCycleId = $initialData['metadata']['current_cycle_id'] ?? null;
    
    echo "Initial test count: $initialTestCount\n";
    echo "Initial cycle ID: " . ($initialCycleId ?? 'None') . "\n";
    echo "✅ PASS: Baseline data captured\n\n";
    
    // Test 2: Check log file size before force lookup
    echo "Test 2: Capture baseline log data\n";
    
    if (!file_exists($logFile)) {
        echo "❌ FAIL: Log file missing\n";
        return false;
    }
    
    $initialLogSize = filesize($logFile);
    echo "Initial log size: $initialLogSize bytes\n";
    echo "✅ PASS: Baseline log data captured\n\n";
    
    // Test 3: Find daemon process and send signal for Force Lookup
    echo "Test 3: Send SIGUSR2 signal to daemon for Force Lookup\n";
    
    // Find DNS daemon process
    $output = shell_exec('ps aux | grep dns-monitor-daemon | grep -v grep');
    if (empty($output)) {
        echo "❌ FAIL: DNS daemon process not found\n";
        return false;
    }
    
    // Extract PID from ps output
    $lines = explode("\n", trim($output));
    $parts = preg_split('/\s+/', trim($lines[0]));
    $pid = (int)$parts[1];
    
    if ($pid <= 0) {
        echo "❌ FAIL: Could not extract PID from process list\n";
        return false;
    }
    
    echo "Found daemon process with PID: $pid\n";
    
    // Send SIGUSR2 signal to trigger immediate lookup
    if (!posix_kill($pid, 12)) { // SIGUSR2 = 12
        echo "❌ FAIL: Failed to send SIGUSR2 signal to daemon\n";
        return false;
    }
    
    echo "✅ PASS: SIGUSR2 signal sent successfully to daemon (PID: $pid)\n\n";
    
    // Test 4: Wait and check for new data
    echo "Test 4: Wait for immediate DNS lookup execution\n";
    echo "Waiting 5 seconds for daemon to process signal...\n";
    sleep(5);
    
    // Check log file for new activity
    clearstatcache();
    $newLogSize = filesize($logFile);
    
    if ($newLogSize <= $initialLogSize) {
        echo "❌ FAIL: No new log activity detected (log size: $initialLogSize -> $newLogSize)\n";
        
        // Read last few lines of log to check for any activity
        $logLines = array_slice(file($logFile), -10);
        echo "Recent log entries:\n";
        foreach ($logLines as $line) {
            echo "  " . trim($line) . "\n";
        }
        return false;
    }
    
    echo "✅ PASS: New log activity detected (log size: $initialLogSize -> $newLogSize)\n\n";
    
    // Test 5: Check for new performance data
    echo "Test 5: Verify new DNS performance data\n";
    
    clearstatcache();
    $newData = json_decode(file_get_contents($performanceFile), true);
    if (!$newData || !isset($newData['tests'])) {
        echo "❌ FAIL: Invalid new performance data structure\n";
        return false;
    }
    
    $newTestCount = count($newData['tests']);
    $newCycleId = $newData['metadata']['current_cycle_id'] ?? null;
    
    echo "New test count: $newTestCount\n";
    echo "New cycle ID: " . ($newCycleId ?? 'None') . "\n";
    
    if ($newTestCount <= $initialTestCount) {
        echo "❌ FAIL: No new DNS tests recorded (count: $initialTestCount -> $newTestCount)\n";
        return false;
    }
    
    if ($newCycleId === $initialCycleId) {
        echo "❌ FAIL: Cycle ID unchanged - no new cycle started\n";
        return false;
    }
    
    echo "✅ PASS: New DNS tests recorded\n";
    echo "✅ PASS: New cycle started\n\n";
    
    echo "🎉 ALL TESTS PASSED: Force Lookup functionality working correctly\n";
    echo "✅ Signal sent to daemon successfully\n";
    echo "✅ Daemon processes signal immediately\n";
    echo "✅ New DNS tests are executed\n";
    echo "✅ Performance data is updated\n";
    
    return true;
}

// Run the test
$success = testForceLookupFunctionality();
exit($success ? 0 : 1);