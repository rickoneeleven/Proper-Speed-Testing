#!/usr/bin/php8.3
<?php
declare(ticks = 1);

// DNS Monitoring Daemon
// Runs continuously and performs DNS lookups every minute

// --- Configuration and State ---
$baseDir = __DIR__;
$pidFile = $baseDir . '/data/dns-daemon.pid';
$logFile = $baseDir . '/data/dns-daemon.log';
$configFile = $baseDir . '/data/dns_config.json';
$dataFile = $baseDir . '/data/dns_performance.json';
$slowQueryFile = $baseDir . '/data/slow_queries.json';
$tempFile = $baseDir . '/data/dns_test_current.tmp';
$heartbeatFile = $baseDir . '/data/dns-heartbeat.lock';

$lockHandle = null;
$digAvailable = null; // Cache the check for 'dig' command

// Global state
$running = true;
$config = null;
$forceTest = false;

// --- Process Lifecycle Management (Atomic Locking) ---
function acquireLock(string $pidFile): void {
    global $lockHandle;
    $lockHandle = fopen($pidFile, 'w+');
    if ($lockHandle === false) {
        error_log(sprintf("[%s] CRITICAL: Could not open PID file for writing: %s\n", date('Y-m-d H:i:s'), $pidFile));
        exit(1);
    }
    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        exit(0);
    }
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string)getmypid());
    fflush($lockHandle);
    register_shutdown_function('cleanup', $lockHandle, $pidFile);
}

function cleanup($lockHandle, string $pidFile): void {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
}

// --- Logging and Configuration ---
function logMessage(string $message): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    
    // Check if log file needs truncation (1MB limit)
    checkAndTruncateLog($logFile, 1024 * 1024, 900 * 1024);
    
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

function checkAndTruncateLog(string $file, int $maxSize, int $targetSize): void {
    if (!file_exists($file) || filesize($file) <= $maxSize) { return; }
    
    $lines = file($file);
    $totalLines = count($lines);
    
    // Calculate how many lines to keep based on target size
    $currentSize = filesize($file);
    $keepRatio = $targetSize / $currentSize;
    $linesToKeep = max(100, (int)($totalLines * $keepRatio)); // Keep at least 100 lines
    
    // Keep the most recent lines
    $newContent = implode('', array_slice($lines, -$linesToKeep));
    file_put_contents($file, $newContent, LOCK_EX);
}

function loadConfig(): array {
    global $configFile;
    if (!file_exists($configFile)) {
        return ['servers' => [], 'domains' => []];
    }
    $config = json_decode(file_get_contents($configFile), true);
    return $config ?: ['servers' => [], 'domains' => []];
}

// --- Signal Handling ---
function sigHandler(int $signo): void {
    global $running, $config, $forceTest;
    switch ($signo) {
        case SIGTERM: case SIGINT:
            logMessage("Received shutdown signal, stopping daemon...");
            $running = false;
            break;
        case SIGUSR1:
            logMessage("Received config reload signal.");
            $config = null;
            break;
        case SIGUSR2:
            logMessage("Received force test signal.");
            $forceTest = true;
            break;
    }
}
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'sigHandler'); pcntl_signal(SIGINT, 'sigHandler');
    pcntl_signal(SIGUSR1, 'sigHandler'); pcntl_signal(SIGUSR2, 'sigHandler');
}

// --- Core Logic: Measurement Functions ---

function measureDnsWithDig(string $server, string $domain): float {
    $randomSub = 'test-' . time() . '-' . rand(1000, 9999);
    $testDomain = "$randomSub.$domain";
    $cmd = "dig @$server $testDomain +tries=1 +time=5 +noall +stats";

    $start = microtime(true);
    $output = shell_exec($cmd);
    $end = microtime(true);

    if ($output && preg_match('/Query time: (\d+) msec/', $output, $matches)) {
        return (float)$matches[1];
    }

    // Fallback timing for when dig succeeds but doesn't return query time (e.g., NXDOMAIN)
    // This is less accurate but better than nothing.
    return round(($end - $start) * 1000, 3);
}


// --- Core Logic: Test Execution ---

function performDnsTests(array $servers, array $domains): void {
    global $dataFile, $tempFile, $heartbeatFile, $digAvailable;

    touch($heartbeatFile);
    $cycleId = date('c');
    $results = [];

    foreach ($servers as $server) {
        foreach ($domains as $domain) {
            $responseTime = measureDnsWithDig($server, $domain);

            // Log slow queries (>1000ms)
            if ($responseTime > 1000) {
                logSlowQuery([
                    'timestamp' => date('c'),
                    'server' => $server,
                    'domain' => $domain,
                    'response_time' => $responseTime
                ]);
            }

            $results[] = [
                'timestamp' => $cycleId,
                'server' => $server,
                'domain' => $domain,
                'response_time' => $responseTime,
                'status' => 'success',
                'cycle_id' => $cycleId
            ];
        }
    }

    if (empty($results)) { return; }

    $data = json_decode(file_get_contents($dataFile), true) ?: [];
    $data['metadata'] = [
        'current_cycle_id' => $cycleId,
        'last_updated' => date('c'),
        'schema_version' => '2.0'
    ];
    $data['tests'] = array_merge($data['tests'] ?? [], $results);

    file_put_contents($dataFile, json_encode($data), LOCK_EX);
    logMessage("Stored " . count($results) . " DNS test results for cycle $cycleId");
    checkAndTruncateJson();
}

function initializeDataFile(): void {
    global $dataFile;
    $dataDir = dirname($dataFile);
    if (!is_dir($dataDir)) { mkdir($dataDir, 0755, true); }
    if (!file_exists($dataFile)) {
        $emptyData = ['metadata' => ['schema_version' => '2.0'], 'tests' => []];
        file_put_contents($dataFile, json_encode($emptyData), LOCK_EX);
    }
}

function logSlowQuery(array $entry): void {
    global $slowQueryFile;
    
    $dataDir = dirname($slowQueryFile);
    if (!is_dir($dataDir)) { mkdir($dataDir, 0755, true); }
    
    // Read existing data or create empty structure
    $data = ['queries' => []];
    if (file_exists($slowQueryFile)) {
        $existing = json_decode(file_get_contents($slowQueryFile), true);
        if ($existing && isset($existing['queries'])) {
            $data = $existing;
        }
    }
    
    // Remove entries older than 30 days
    $thirtyDaysAgo = date('c', strtotime('-30 days'));
    $data['queries'] = array_filter($data['queries'], function($query) use ($thirtyDaysAgo) {
        return isset($query['timestamp']) && $query['timestamp'] >= $thirtyDaysAgo;
    });
    
    // Add new entry
    $data['queries'][] = $entry;
    
    // Write atomically using temp file
    $tempFile = $slowQueryFile . '.tmp';
    if (file_put_contents($tempFile, json_encode($data), LOCK_EX) !== false) {
        rename($tempFile, $slowQueryFile);
    }
}

function checkAndTruncateJson(): void {
    global $dataFile;
    $maxSize = 10 * 1024 * 1024; // 10MB
    $targetSize = 9 * 1024 * 1024; // 9MB
    
    if (!file_exists($dataFile) || filesize($dataFile) <= $maxSize) { return; }
    
    logMessage("JSON file exceeded 10MB, truncating to 9MB...");
    $data = json_decode(file_get_contents($dataFile), true);
    if ($data && isset($data['tests'])) {
        // Calculate how many entries to keep to reach target size
        $currentSize = filesize($dataFile);
        $reductionRatio = $targetSize / $currentSize;
        $entriesToKeep = max(1000, (int)(count($data['tests']) * $reductionRatio));
        
        $data['tests'] = array_slice($data['tests'], -$entriesToKeep);
        $data['metadata']['last_updated'] = date('c');
        file_put_contents($dataFile, json_encode($data), LOCK_EX);
        logMessage("File truncated to " . number_format(filesize($dataFile)) . " bytes, keeping " . $entriesToKeep . " entries.");
    }
}

// --- Main Daemon Execution ---

function runDaemon(): void {
    global $running, $config, $forceTest, $digAvailable;

    logMessage("DNS monitoring daemon started (PID: " . getmypid() . ")");
    // Check for 'dig' command once at startup - exit if not available
    $digAvailable = (shell_exec('which dig') !== null);
    if ($digAvailable) {
        logMessage("Using 'dig' for DNS measurements.");
    } else {
        logMessage("CRITICAL: 'dig' command not found. Cannot perform DNS monitoring without dig.");
        exit(1);
    }

    initializeDataFile();
    $lastTestTime = 0;
    $testInterval = 60;

    while ($running) {
        if ($config === null) {
            $config = loadConfig();
            logMessage("Configuration loaded: " . count($config['servers']) . " servers, " . count($config['domains']) . " domains");
        }

        if ($forceTest || (time() - $lastTestTime) >= $testInterval) {
            if (!empty($config['servers']) && !empty($config['domains'])) {
                logMessage("Starting DNS tests...");
                performDnsTests($config['servers'], $config['domains']);
                logMessage("DNS tests completed.");
            } else {
                logMessage("No servers or domains configured, skipping tests.");
            }
            $lastTestTime = time();
            $forceTest = false;
        }

        if (function_exists('pcntl_signal_dispatch')) { pcntl_signal_dispatch(); }
        sleep(1);
    }
    logMessage("DNS monitoring daemon stopped (PID: " . getmypid() . ")");
}

// --- Script Entry Point ---

acquireLock($pidFile);
runDaemon();