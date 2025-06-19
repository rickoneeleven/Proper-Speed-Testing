<?php
// Slow Query API
// Provides endpoints for slow DNS query reporting

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

$slowQueryFile = __DIR__ . '/data/slow_queries.json';

function loadSlowQueries(): array {
    global $slowQueryFile;
    if (!file_exists($slowQueryFile)) {
        return ['queries' => []];
    }
    $data = json_decode(file_get_contents($slowQueryFile), true);
    return $data ?: ['queries' => []];
}

function getSummary(): array {
    $data = loadSlowQueries();
    $summary = [];
    
    foreach ($data['queries'] as $query) {
        $server = $query['server'];
        if (!isset($summary[$server])) {
            $summary[$server] = 0;
        }
        $summary[$server]++;
    }
    
    // Convert to array format and sort by count descending
    $result = [];
    foreach ($summary as $server => $count) {
        $result[] = ['server' => $server, 'count' => $count];
    }
    
    usort($result, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    
    return $result;
}

function getDetailHtml(string $server): string {
    $data = loadSlowQueries();
    $serverQueries = array_filter($data['queries'], function($query) use ($server) {
        return $query['server'] === $server;
    });
    
    // Sort by timestamp descending (most recent first)
    usort($serverQueries, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slow Queries for ' . htmlspecialchars($server) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <h1>Slow Queries for ' . htmlspecialchars($server) . '</h1>
    <p>Total slow queries: ' . count($serverQueries) . '</p>
    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Queried Domain</th>
                <th>Response Time (ms)</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($serverQueries as $query) {
        $timestamp = date('Y-m-d H:i:s', strtotime($query['timestamp']));
        $html .= '<tr>
            <td>' . htmlspecialchars($timestamp) . '</td>
            <td>' . htmlspecialchars($query['domain']) . '</td>
            <td>' . htmlspecialchars(number_format($query['response_time'], 1)) . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
    </table>
</body>
</html>';
    
    return $html;
}

// Handle request
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'summary':
        header('Content-Type: application/json');
        $summary = getSummary();
        echo json_encode(['success' => true, 'summary' => $summary]);
        break;
        
    case 'detail':
        $server = $_GET['server'] ?? '';
        if (empty($server)) {
            header('HTTP/1.1 400 Bad Request');
            echo json_encode(['success' => false, 'error' => 'Server parameter required']);
            exit;
        }
        header('Content-Type: text/html');
        echo getDetailHtml($server);
        break;
        
    default:
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid action parameter']);
}
?>