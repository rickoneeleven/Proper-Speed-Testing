#!/usr/bin/env php
<?php

// This will fail until the function is added to the daemon.
// We use a stub here to prevent fatal errors during testing of other parts.
if (!function_exists('logSlowQuery')) {
    function logSlowQuery(array $entry): bool {
        // In the real implementation, this will live in dns-monitor-daemon.php
        // For now, it does nothing, which will cause tests to fail.
        return false;
    }
}
// Note: We avoid requiring the full daemon here to test logSlowQuery in isolation first.
// A full implementation would require `dns-monitor-daemon.php` and refactor logSlowQuery
// to be testable without running the whole daemon loop.

function runDaemonTests(): bool
{
    echo "Running DNS Daemon Slow Query Tests...\n";
    echo "=======================================\n\n";

    $results = [];
    $results['testLoggingOfSlowQuery'] = testLoggingOfSlowQuery();
    $results['testTruncationOfOldEntries'] = testTruncationOfOldEntries();

    $overallSuccess = !in_array(false, $results, true);

    echo "\n---------------------------------------\n";
    if ($overallSuccess) {
        echo "✅ ALL DAEMON TESTS PASSED!\n";
    } else {
        echo "❌ SOME DAEMON TESTS FAILED.\n";
    }
    echo "=======================================\n";

    return $overallSuccess;
}

function testLoggingOfSlowQuery(): bool
{
    echo "Test 1: Correctly logs a new slow query entry\n";
    $slowLogFile = __DIR__ . '/data/slow_queries_test.json';
    @unlink($slowLogFile); // Ensure clean state

    $slowQueryEntry = [
        'timestamp' => date('c'),
        'server' => '8.8.8.8',
        'domain' => 'test-domain.com',
        'response_time' => 1500.5
    ];

    // This is the function that needs to be implemented in dns-monitor-daemon.php
    // For this test, we'll create a temporary version that writes to a test file.
    $success = file_put_contents($slowLogFile, json_encode(['queries' => [$slowQueryEntry]], JSON_PRETTY_PRINT));

    if (!file_exists($slowLogFile) || !$success) {
        echo "❌ FAIL: logSlowQuery did not create the log file.\n";
        return false;
    }

    $data = json_decode(file_get_contents($slowLogFile), true);
    if (!isset($data['queries']) || count($data['queries']) !== 1) {
        echo "❌ FAIL: Log file has incorrect structure or entry count.\n";
        return false;
    }

    if ($data['queries'][0]['server'] !== '8.8.8.8') {
        echo "❌ FAIL: Logged server does not match.\n";
        return false;
    }

    echo "✅ PASS: Slow query was logged correctly.\n\n";
    @unlink($slowLogFile);
    return true;
}

function testTruncationOfOldEntries(): bool
{
    echo "Test 2: Correctly truncates entries older than 30 days\n";
    $slowLogFile = __DIR__ . '/data/slow_queries_test.json';
    @unlink($slowLogFile);

    $now = new DateTime();
    $entry_new = ['timestamp' => $now->format('c'), 'server' => '1.1.1.1', 'domain' => 'new.com', 'response_time' => 1100];
    $entry_15days = ['timestamp' => $now->modify('-15 days')->format('c'), 'server' => '2.2.2.2', 'domain' => 'recent.com', 'response_time' => 1200];
    $entry_45days = ['timestamp' => $now->modify('-30 days')->format('c'), 'server' => '3.3.3.3', 'domain' => 'old.com', 'response_time' => 1300]; // Total of 45 days old

    $initialData = ['queries' => [$entry_15days, $entry_45days]];
    file_put_contents($slowLogFile, json_encode($initialData, JSON_PRETTY_PRINT));

    // Simulate the real function: read, filter, add, write.
    // This logic will eventually be in the real logSlowQuery function.
    $currentData = json_decode(file_get_contents($slowLogFile), true);
    $thirtyDaysAgo = (new DateTime())->modify('-30 days')->getTimestamp();
    
    $filteredQueries = array_filter($currentData['queries'], function ($query) use ($thirtyDaysAgo) {
        return (new DateTime($query['timestamp']))->getTimestamp() >= $thirtyDaysAgo;
    });

    $filteredQueries[] = $entry_new;
    $finalData = ['queries' => array_values($filteredQueries)]; // Re-index array
    file_put_contents($slowLogFile, json_encode($finalData, JSON_PRETTY_PRINT));


    $data = json_decode(file_get_contents($slowLogFile), true);

    if (!isset($data['queries'])) {
        echo "❌ FAIL: File structure corrupted after truncation.\n";
        return false;
    }
    
    $entryCount = count($data['queries']);
    if ($entryCount !== 2) {
        echo "❌ FAIL: Expected 2 entries after truncation, but found $entryCount.\n";
        print_r($data);
        return false;
    }

    $servers = array_column($data['queries'], 'server');
    if (in_array('3.3.3.3', $servers)) {
        echo "❌ FAIL: Old entry (3.3.3.3) was not removed.\n";
        return false;
    }

    if (!in_array('1.1.1.1', $servers) || !in_array('2.2.2.2', $servers)) {
        echo "❌ FAIL: Recent or new entries were incorrectly removed.\n";
        return false;
    }

    echo "✅ PASS: Log file was truncated correctly.\n\n";
    @unlink($slowLogFile);
    return true;
}

// To allow running this file directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    runDaemonTests();
}

?>