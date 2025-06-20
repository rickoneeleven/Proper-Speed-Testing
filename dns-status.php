<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$pidFile = __DIR__ . '/data/dns-daemon.pid';
$dataFile = __DIR__ . '/data/dns_performance.json';
$logFile = __DIR__ . '/data/dns-daemon.log';

function getDaemonStatus() {
    global $pidFile;
    
    if (!file_exists($pidFile)) {
        return [
            'running' => false,
            'pid' => null,
            'status' => 'stopped'
        ];
    }
    
    $pid = (int)trim(file_get_contents($pidFile));
    
    if ($pid <= 0) {
        return [
            'running' => false,
            'pid' => null,
            'status' => 'invalid_pid'
        ];
    }
    
    // Check if process is actually running
    if (posix_kill($pid, 0)) {
        return [
            'running' => true,
            'pid' => $pid,
            'status' => 'running'
        ];
    } else {
        // Process not running, clean up stale PID file
        unlink($pidFile);
        return [
            'running' => false,
            'pid' => null,
            'status' => 'stale_pid'
        ];
    }
}

function getLastUpdateTime() {
    global $dataFile;
    
    if (!file_exists($dataFile)) {
        return null;
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    if (!$data) {
        return null;
    }
    
    // Check metadata first for more accurate last updated time
    if (isset($data['metadata']['last_updated'])) {
        return $data['metadata']['last_updated'];
    }
    
    // Fallback to last test timestamp
    if (isset($data['tests']) && !empty($data['tests'])) {
        $lastTest = end($data['tests']);
        return $lastTest['timestamp'] ?? null;
    }
    
    return null;
}

function getDnsStats() {
    global $dataFile;
    
    $stats = [];
    
    if (!file_exists($dataFile)) {
        return $stats;
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    if (!$data || !isset($data['tests'])) {
        return $stats;
    }
    
    // Get current cycle ID from metadata
    $currentCycleId = null;
    if (isset($data['metadata']['current_cycle_id'])) {
        $currentCycleId = $data['metadata']['current_cycle_id'];
    }
    
    // Group all tests by server for historical averages
    $serverTests = [];
    // Group current cycle tests by server for "last run"
    $currentCycleTests = [];
    
    foreach ($data['tests'] as $test) {
        $server = $test['server'];
        
        // Historical averages (all tests)
        if (!isset($serverTests[$server])) {
            $serverTests[$server] = [];
        }
        if ($test['response_time'] !== -1) {
            $serverTests[$server][] = $test['response_time'];
        }
        
        // Current cycle tests for "last run"
        if ($currentCycleId && isset($test['cycle_id']) && $test['cycle_id'] === $currentCycleId) {
            if (!isset($currentCycleTests[$server])) {
                $currentCycleTests[$server] = [];
            }
            if ($test['response_time'] !== -1) {
                $currentCycleTests[$server][] = $test['response_time'];
            }
        }
    }
    
    // Calculate stats for each server
    foreach ($serverTests as $server => $times) {
        if (empty($times)) {
            $stats[$server] = [
                'average' => 'N/A',
                'count' => 0,
                'last_run' => 'N/A'
            ];
            continue;
        }
        
        // Historical average
        $average = array_sum($times) / count($times);
        
        // Last run from current cycle
        $lastRun = 'N/A';
        if (isset($currentCycleTests[$server]) && !empty($currentCycleTests[$server])) {
            $lastAvg = array_sum($currentCycleTests[$server]) / count($currentCycleTests[$server]);
            $lastRun = round($lastAvg, 2);
        }
        
        $stats[$server] = [
            'average' => round($average, 2),
            'count' => count($times),
            'last_run' => $lastRun
        ];
    }
    
    return $stats;
}

function getResetDate() {
    global $dataFile;
    
    if (!file_exists($dataFile)) {
        return null;
    }
    
    $data = json_decode(file_get_contents($dataFile), true);
    if (!$data || !isset($data['tests']) || empty($data['tests'])) {
        return null;
    }
    
    // Get the timestamp of the first test as reset date
    $firstTest = reset($data['tests']);
    return isset($firstTest['timestamp']) ? $firstTest['timestamp'] : null;
}

function getRecentLogEntries($limit = 10) {
    global $logFile;
    
    if (!file_exists($logFile)) {
        return [];
    }
    
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    
    // Get last N lines
    return array_slice($lines, -$limit);
}

try {
    $daemonStatus = getDaemonStatus();
    $lastUpdate = getLastUpdateTime();
    $dnsStats = getDnsStats();
    $recentLogs = getRecentLogEntries();
    $resetDate = getResetDate();
    
    $response = [
        'success' => true,
        'daemon' => $daemonStatus,
        'last_update' => $lastUpdate,
        'stats' => $dnsStats,
        'recent_logs' => $recentLogs,
        'reset_date' => $resetDate,
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>