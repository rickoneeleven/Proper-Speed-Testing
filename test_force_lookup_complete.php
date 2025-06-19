#!/usr/bin/env php
<?php

function testCompleteForceLookupFunctionality(): bool {
    $performanceFile = __DIR__ . '/data/dns_performance.json';
    $logFile = __DIR__ . '/data/dns-daemon.log';
    
    echo "Testing Complete Force Lookup Functionality\n";
    echo "===========================================\n\n";
    
    // Test 1: Verify DNS daemon is running
    echo "Test 1: Verify DNS daemon is running\n";
    
    $output = shell_exec('ps aux | grep dns-monitor-daemon | grep -v grep');
    if (empty($output)) {
        echo "❌ FAIL: DNS daemon process not found\n";
        return false;
    }
    
    $lines = explode("\n", trim($output));
    $parts = preg_split('/\s+/', trim($lines[0]));
    $pid = (int)$parts[1];
    
    echo "✅ PASS: DNS daemon running with PID: $pid\n\n";
    
    // Test 2: Capture baseline performance data
    echo "Test 2: Capture baseline DNS performance data\n";
    
    if (!file_exists($performanceFile)) {
        echo "❌ FAIL: Performance file missing\n";
        return false;
    }
    
    $initialData = json_decode(file_get_contents($performanceFile), true);
    $initialTestCount = count($initialData['tests']);
    $initialCycleId = $initialData['metadata']['current_cycle_id'] ?? null;
    
    echo "Initial test count: $initialTestCount\n";
    echo "Initial cycle ID: " . ($initialCycleId ?? 'None') . "\n";
    echo "✅ PASS: Baseline data captured\n\n";
    
    // Test 3: Test API endpoint for force lookup
    echo "Test 3: Test Force Lookup API endpoint\n";
    
    $url = 'http://speedtest.pinescore.rcp-net.com/dns-config.php';
    $postData = json_encode(['action' => 'force-lookup']);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $postData
        ]
    ]);
    
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        echo "❌ FAIL: Failed to call force lookup API\n";
        return false;
    }
    
    $apiResponse = json_decode($response, true);
    if (!$apiResponse || !$apiResponse['success']) {
        echo "❌ FAIL: Force lookup API returned error: " . ($apiResponse['error'] ?? 'Unknown error') . "\n";
        return false;
    }
    
    echo "✅ PASS: Force lookup API call successful\n";
    echo "API response: " . ($apiResponse['message'] ?? 'No message') . "\n\n";
    
    // Test 4: Wait for immediate execution
    echo "Test 4: Wait for immediate DNS lookup execution\n";
    echo "Waiting 8 seconds for daemon to process signal and complete tests...\n";
    sleep(8);
    
    // Test 5: Verify new performance data
    echo "Test 5: Verify new DNS tests were executed\n";
    
    clearstatcache();
    $newData = json_decode(file_get_contents($performanceFile), true);
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
    
    echo "✅ PASS: New DNS tests recorded (added " . ($newTestCount - $initialTestCount) . " tests)\n";
    echo "✅ PASS: New cycle started\n\n";
    
    // Test 6: Test DNS status API for visual feedback
    echo "Test 6: Test DNS status API for dashboard updates\n";
    
    $statusUrl = 'http://speedtest.pinescore.rcp-net.com/dns-status.php';
    $statusResponse = file_get_contents($statusUrl);
    
    if ($statusResponse === false) {
        echo "❌ FAIL: Failed to call DNS status API\n";
        return false;
    }
    
    $statusData = json_decode($statusResponse, true);
    if (!$statusData || !$statusData['success']) {
        echo "❌ FAIL: DNS status API returned error\n";
        return false;
    }
    
    if (!isset($statusData['stats']) || !isset($statusData['last_update'])) {
        echo "❌ FAIL: DNS status API missing required fields for visual feedback\n";
        return false;
    }
    
    echo "✅ PASS: DNS status API provides data for visual feedback\n";
    echo "Stats available for " . count($statusData['stats']) . " servers\n";
    echo "Last update: " . ($statusData['last_update'] ?? 'N/A') . "\n";
    echo "Daemon running: " . ($statusData['daemon']['running'] ? 'Yes' : 'No') . "\n\n";
    
    // Test 7: Verify Force Lookup button behavior would work
    echo "Test 7: Verify UI components are properly implemented\n";
    
    if (!file_exists(__DIR__ . '/index.html')) {
        echo "❌ FAIL: Main UI file missing\n";
        return false;
    }
    
    $htmlContent = file_get_contents(__DIR__ . '/index.html');
    
    // Check for Force Lookup button
    if (strpos($htmlContent, 'Force Lookup') === false) {
        echo "❌ FAIL: Force Lookup button not found in UI\n";
        return false;
    }
    
    // Check for forceDnsLookup function
    if (strpos($htmlContent, 'function forceDnsLookup()') === false) {
        echo "❌ FAIL: forceDnsLookup function not found in UI\n";
        return false;
    }
    
    // Check for visual feedback enhancements
    if (strpos($htmlContent, 'Triggering...') === false) {
        echo "❌ FAIL: Button visual feedback not implemented\n";
        return false;
    }
    
    echo "✅ PASS: Force Lookup button exists in UI\n";
    echo "✅ PASS: forceDnsLookup JavaScript function implemented\n";
    echo "✅ PASS: Visual feedback enhancements present\n\n";
    
    echo "🎉 ALL TESTS PASSED: Complete Force Lookup functionality working!\n";
    echo "✅ DNS daemon is running and responsive to signals\n";
    echo "✅ Force Lookup API endpoint works correctly\n";
    echo "✅ Immediate DNS tests are executed when triggered\n";
    echo "✅ Performance data is updated with new results\n";
    echo "✅ Visual feedback is provided to end users\n";
    echo "✅ Dashboard can refresh to show updated data\n\n";
    
    echo "🚀 The Force Lookup button now provides immediate DNS testing with real-time feedback!\n";
    
    return true;
}

// Run the test
$success = testCompleteForceLookupFunctionality();
exit($success ? 0 : 1);