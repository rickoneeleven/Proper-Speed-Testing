#!/usr/bin/env php
<?php
declare(ticks = 1);

// DNS Monitoring Daemon
// Runs continuously and performs DNS lookups every minute

$baseDir = __DIR__;
$configFile = $baseDir . '/data/dns_config.json';
$dataFile = $baseDir . '/data/dns_performance.json';
$pidFile = $baseDir . '/data/dns-daemon.pid';
$logFile = $baseDir . '/data/dns-daemon.log';
$tempFile = $baseDir . '/data/dns_test_current.tmp';

// Global variables
$running = true;
$config = null;
$forceTest = false;

// Logging function
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    echo $logEntry;
}

// Signal handlers
function sigHandler($signo) {
    global $running, $config, $forceTest;
    
    switch ($signo) {
        case SIGTERM:
        case SIGINT:
            logMessage("Received shutdown signal, stopping daemon...");
            $running = false;
            break;
        case SIGUSR1:
            logMessage("Received config reload signal");
            $config = null; // Force config reload on next iteration
            break;
        case SIGUSR2:
            logMessage("Received force test signal");
            $forceTest = true;
            break;
    }
}

// Install signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'sigHandler');
    pcntl_signal(SIGINT, 'sigHandler');
    pcntl_signal(SIGUSR1, 'sigHandler');
    pcntl_signal(SIGUSR2, 'sigHandler');
}

// Load configuration
function loadConfig() {
    global $configFile;
    
    if (!file_exists($configFile)) {
        return ['servers' => [], 'domains' => []];
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    return $config ?: ['servers' => [], 'domains' => []];
}

// Initialize data file
function initializeDataFile() {
    global $dataFile;
    
    if (!file_exists($dataFile)) {
        $emptyData = [
            'metadata' => [
                'current_cycle_id' => null,
                'last_updated' => null,
                'schema_version' => '2.0'
            ],
            'tests' => []
        ];
        file_put_contents($dataFile, json_encode($emptyData), LOCK_EX);
    }
}

// Check and truncate JSON file if it exceeds 10MB
function checkAndTruncateJson() {
    global $dataFile;
    
    if (!file_exists($dataFile)) {
        return;
    }
    
    $fileSize = filesize($dataFile);
    $maxSize = 10 * 1024 * 1024; // 10MB
    
    if ($fileSize > $maxSize) {
        logMessage("JSON file exceeded 10MB ($fileSize bytes), truncating...");
        
        $data = json_decode(file_get_contents($dataFile), true);
        if ($data && isset($data['tests']) && is_array($data['tests'])) {
            // Keep only the last 1000 entries
            $data['tests'] = array_slice($data['tests'], -1000);
            // Update metadata
            if (!isset($data['metadata'])) {
                $data['metadata'] = ['schema_version' => '2.0'];
            }
            $data['metadata']['last_updated'] = date('c');
            file_put_contents($dataFile, json_encode($data), LOCK_EX);
            logMessage("File truncated to keep last 1000 entries.");
        }
    }
}

// Generate random subdomain for cache busting
function generateRandomSubdomain() {
    return 'test-' . time() . '-' . rand(1000, 9999);
}

// Measure DNS lookup time
function measureDnsLookup($server, $domain, $cacheBust = true) {
    if ($cacheBust) {
        $randomSub = generateRandomSubdomain();
        $domain = "$randomSub.$domain";
    }
    
    $start = microtime(true);
    
    // Use PHP's dns_get_record for cross-platform compatibility
    // We measure response time regardless of whether domain exists (for cache busting)
    $result = @dns_get_record($domain, DNS_A, $authns, $addtl, false, $server);
    
    $end = microtime(true);
    
    // Calculate duration in milliseconds - we measure the response time even for NXDOMAIN
    return round(($end - $start) * 1000, 3);
}

// Fallback DNS lookup using dig if available
function measureDnsLookupDig($server, $domain, $cacheBust = true) {
    if ($cacheBust) {
        $randomSub = generateRandomSubdomain();
        $domain = "$randomSub.$domain";
    }
    
    $start = microtime(true);
    
    // Use dig command - check exit code instead of output for timing
    $cmd = "dig @$server $domain +tries=1 +time=5 +noall +answer";
    $output = null;
    $exitCode = null;
    exec($cmd, $output, $exitCode);
    
    $end = microtime(true);
    
    // If dig executed successfully (even with NXDOMAIN), we got a response time
    if ($exitCode !== null && $exitCode <= 1) { // 0 = success, 1 = NXDOMAIN but still valid response
        return round(($end - $start) * 1000, 3);
    } else {
        return -1; // Network error or timeout
    }
}

// Test DNS servers
function performDnsTests($servers, $domains) {
    global $tempFile, $dataFile, $baseDir;
    
    // HEARTBEAT: Touch lock file to show daemon is active
    $heartbeatFile = $baseDir . '/data/dns-heartbeat.lock';
    touch($heartbeatFile);
    
    // Clear temp file
    file_put_contents($tempFile, '');
    
    $cycleId = date('c');
    $timestamp = $cycleId;
    $results = [];
    
    foreach ($servers as $server) {
        foreach ($domains as $domain) {
            logMessage("Testing $server -> $domain...");
            
            // Try dig first, fall back to PHP dns_get_record
            $responseTime = -1;
            if (shell_exec('which dig') !== null) {
                $responseTime = measureDnsLookupDig($server, $domain, true);
            }
            
            // Fallback to PHP method if dig failed or not available
            if ($responseTime === -1) {
                $responseTime = measureDnsLookup($server, $domain, true);
            }
            
            $status = $responseTime !== -1 ? 'success' : 'failed';
            
            if ($responseTime !== -1) {
                logMessage("$server -> $domain: {$responseTime}ms");
            } else {
                logMessage("$server -> $domain: FAILED");
            }
            
            // Store result in temp file
            $tempResult = [
                'server' => $server,
                'domain' => $domain,
                'response_time' => $responseTime
            ];
            file_put_contents($tempFile, json_encode($tempResult) . "\n", FILE_APPEND | LOCK_EX);
            
            // Prepare result for main data file
            $result = [
                'timestamp' => $timestamp,
                'server' => $server,
                'domain' => $domain,
                'response_time' => $responseTime,
                'status' => $status,
                'cycle_id' => $cycleId
            ];
            
            $results[] = $result;
        }
    }
    
    // Append results to main data file
    if (!empty($results)) {
        $data = json_decode(file_get_contents($dataFile), true);
        if (!$data) {
            $data = [
                'metadata' => [
                    'current_cycle_id' => null,
                    'last_updated' => null,
                    'schema_version' => '2.0'
                ],
                'tests' => []
            ];
        }
        
        // Ensure metadata exists
        if (!isset($data['metadata'])) {
            $data['metadata'] = ['schema_version' => '2.0'];
        }
        
        // Update metadata
        $data['metadata']['current_cycle_id'] = $cycleId;
        $data['metadata']['last_updated'] = date('c');
        
        // Add new test results
        $data['tests'] = array_merge($data['tests'], $results);
        
        // Write updated data
        file_put_contents($dataFile, json_encode($data), LOCK_EX);
        
        logMessage("Stored " . count($results) . " DNS test results for cycle $cycleId");
    }
    
    // Check file size periodically
    checkAndTruncateJson();
}


// Main daemon function
function runDaemon() {
    global $running, $config, $forceTest, $pidFile;
    
    // PID file already written by acquireLock function
    logMessage("DNS monitoring daemon started (PID: " . getmypid() . ")");
    
    // Initialize data file
    initializeDataFile();
    
    $lastTestTime = 0;
    $testInterval = 60; // 60 seconds
    
    while ($running) {
        $currentTime = time();
        
        // Reload config if needed
        if ($config === null) {
            $config = loadConfig();
            logMessage("Configuration loaded: " . count($config['servers']) . " servers, " . count($config['domains']) . " domains");
        }
        
        // Check if it's time for a test (every minute) or forced
        if ($forceTest || ($currentTime - $lastTestTime) >= $testInterval) {
            if (!empty($config['servers']) && !empty($config['domains'])) {
                logMessage("Starting DNS tests...");
                performDnsTests($config['servers'], $config['domains']);
                $lastTestTime = $currentTime;
                logMessage("DNS tests completed");
            } else {
                logMessage("No DNS servers or domains configured, skipping tests");
            }
            
            $forceTest = false;
        }
        
        // Process signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        // Sleep for 1 second
        sleep(1);
    }
    
    // Cleanup
    cleanup();
    
    logMessage("DNS monitoring daemon stopped");
}

// Cleanup function
function cleanup() {
    global $pidFile;
    
    // Clean up PID file
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
}

// Register cleanup function
register_shutdown_function('cleanup');


// Create PID file at startup
$myPid = getmypid();
if (file_put_contents($pidFile, $myPid) === false) {
    logMessage("ERROR: Failed to create PID file");
    exit(1);
}

// Ensure data directory exists
$dataDir = dirname($dataFile);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Start the daemon
runDaemon();
?>