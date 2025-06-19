<?php
// Start output buffering to ensure clean JSON output
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$configFile = __DIR__ . '/data/dns_config.json';
$dataFile = __DIR__ . '/data/dns_performance.json';
$logFile = __DIR__ . '/data/dns-daemon.log';

// Default domains for DNS testing
$defaultDomains = [
    'bbc.co.uk',
    'wrc.com',
    'google.co.uk',
    'channel4.com',
    'amazon.co.uk',
    'microsoft.com',
    'cloudflare.com',
    'github.com',
    'stackoverflow.com',
    'wikipedia.org'
];

function loadConfig() {
    global $configFile, $defaultDomains;
    
    if (!file_exists($configFile)) {
        $defaultConfig = [
            'servers' => [],
            'domains' => $defaultDomains
        ];
        saveConfig($defaultConfig);
        return $defaultConfig;
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config) {
        return ['servers' => [], 'domains' => $defaultDomains];
    }
    
    // Ensure domains are present
    if (!isset($config['domains']) || empty($config['domains'])) {
        $config['domains'] = $defaultDomains;
    }
    
    return $config;
}

function saveConfig($config) {
    global $configFile;
    
    // Ensure data directory exists
    $dataDir = dirname($configFile);
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }
    
    return file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
}

function validateDnsServer($server) {
    // Remove any protocol prefixes
    $server = preg_replace('/^https?:\/\//', '', $server);
    
    // Check if it's a valid IP address
    if (filter_var($server, FILTER_VALIDATE_IP)) {
        return $server;
    }
    
    // Check if it's a valid hostname
    if (filter_var($server, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        return $server;
    }
    
    return false;
}

function sendSignalToDaemon($signal = 'SIGUSR1') {
    // First try the PID file approach
    $pidFile = __DIR__ . '/data/dns-daemon.pid';
    
    if (file_exists($pidFile)) {
        $pidContent = trim(file_get_contents($pidFile));
        if (!empty($pidContent)) {
            $pid = (int)$pidContent;
            if ($pid > 0) {
                // Check if process is running
                if (posix_kill($pid, 0)) {
                    // Send signal - map signal name to number
                    $signalNum = 10; // Default to SIGUSR1=10
                    if ($signal === 'SIGUSR1') {
                        $signalNum = 10;
                    } elseif ($signal === 'SIGUSR2') {
                        $signalNum = 12;
                    } elseif ($signal === 'SIGTERM') {
                        $signalNum = 15;
                    }
                    return posix_kill($pid, $signalNum);
                } else {
                    // Process not running, remove stale PID file
                    unlink($pidFile);
                }
            }
        }
    }
    
    // Fallback: Find daemon process by name
    $output = shell_exec('ps aux | grep dns-monitor-daemon | grep -v grep');
    if (!empty($output)) {
        $lines = explode("\n", trim($output));
        if (!empty($lines[0])) {
            $parts = preg_split('/\s+/', trim($lines[0]));
            $pid = (int)$parts[1];
            
            if ($pid > 0) {
                // Send signal - map signal name to number
                $signalNum = 10; // Default to SIGUSR1=10
                if ($signal === 'SIGUSR1') {
                    $signalNum = 10;
                } elseif ($signal === 'SIGUSR2') {
                    $signalNum = 12;
                } elseif ($signal === 'SIGTERM') {
                    $signalNum = 15;
                }
                return posix_kill($pid, $signalNum);
            }
        }
    }
    
    return false;
}

// Function to output clean JSON response
function outputJson($data) {
    ob_clean(); // Clear any accidental output
    echo json_encode($data);
    ob_end_flush();
    exit;
}

function logError($message) {
    $logFile = __DIR__ . '/data/api-error.log';
    
    // Check if log file needs truncation (1MB limit)
    if (file_exists($logFile) && filesize($logFile) > 1024 * 1024) {
        $lines = file($logFile);
        $totalLines = count($lines);
        $targetSize = 900 * 1024; // 900KB
        $currentSize = filesize($logFile);
        $keepRatio = $targetSize / $currentSize;
        $linesToKeep = max(100, (int)($totalLines * $keepRatio));
        $newContent = implode('', array_slice($lines, -$linesToKeep));
        file_put_contents($logFile, $newContent, LOCK_EX);
    }
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Get JSON input for POST requests
$jsonInput = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);
}

// Get action from GET, POST, or JSON body
$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');

try {
    switch ($action) {
        case 'list':
            $config = loadConfig();
            outputJson([
                'success' => true,
                'servers' => $config['servers'],
                'domains' => $config['domains']
            ]);
            break;
            
        case 'add':
            $server = trim($jsonInput['server'] ?? '');
            
            if (empty($server)) {
                throw new Exception('DNS server address is required');
            }
            
            $validatedServer = validateDnsServer($server);
            if (!$validatedServer) {
                throw new Exception('Invalid DNS server address');
            }
            
            $config = loadConfig();
            
            if (in_array($validatedServer, $config['servers'])) {
                throw new Exception('DNS server already exists');
            }
            
            $config['servers'][] = $validatedServer;
            
            if (!saveConfig($config)) {
                throw new Exception('Failed to save configuration');
            }
            
            // Signal daemon to reload config
            sendSignalToDaemon();
            
            outputJson([
                'success' => true,
                'message' => 'DNS server added successfully',
                'servers' => $config['servers']
            ]);
            break;
            
        case 'remove':
            $server = trim($jsonInput['server'] ?? '');
            
            if (empty($server)) {
                throw new Exception('DNS server address is required');
            }
            
            $config = loadConfig();
            $index = array_search($server, $config['servers']);
            
            if ($index === false) {
                throw new Exception('DNS server not found');
            }
            
            array_splice($config['servers'], $index, 1);
            
            if (!saveConfig($config)) {
                throw new Exception('Failed to save configuration');
            }
            
            // Signal daemon to reload config
            sendSignalToDaemon();
            
            outputJson([
                'success' => true,
                'message' => 'DNS server removed successfully',
                'servers' => $config['servers']
            ]);
            break;
            
        case 'reset':
            global $dataFile;
            
            // Initialize empty data file
            $emptyData = ['tests' => []];
            if (!file_put_contents($dataFile, json_encode($emptyData))) {
                throw new Exception('Failed to reset DNS data');
            }
            
            outputJson([
                'success' => true,
                'message' => 'DNS monitoring data reset successfully'
            ]);
            break;
            
        case 'force-lookup':
            // Signal daemon to perform immediate lookup
            if (sendSignalToDaemon('SIGUSR2')) {
                outputJson([
                    'success' => true,
                    'message' => 'Immediate DNS lookup triggered'
                ]);
            } else {
                outputJson([
                    'success' => false,
                    'message' => 'DNS daemon not running - unable to trigger lookup'
                ]);
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    logError('Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(400);
    outputJson([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    logError('Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    outputJson([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
?>