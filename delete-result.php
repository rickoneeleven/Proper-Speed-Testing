<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Only allow POST or DELETE methods
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the timestamp from the request
$input = json_decode(file_get_contents('php://input'), true);
$timestamp = $input['timestamp'] ?? null;

if (!$timestamp) {
    http_response_code(400);
    echo json_encode(['error' => 'Timestamp is required']);
    exit;
}

$resultsFile = __DIR__ . '/data/results.json';

// Check if results file exists
if (!file_exists($resultsFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Results file not found']);
    exit;
}

// Read all results
$fileContent = file_get_contents($resultsFile);
$lines = array_filter(explode("\n", $fileContent), 'strlen');
$results = [];
$found = false;

// Parse each line and filter out the one to delete
foreach ($lines as $line) {
    try {
        $result = json_decode($line, true);
        if ($result && isset($result['timestamp'])) {
            if ($result['timestamp'] !== $timestamp) {
                $results[] = $line;
            } else {
                $found = true;
            }
        }
    } catch (Exception $e) {
        // Keep malformed lines
        $results[] = $line;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(['error' => 'Result not found']);
    exit;
}

// Write the filtered results back to the file
$newContent = implode("\n", $results) . "\n";
if (file_put_contents($resultsFile, $newContent) === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update results file']);
    exit;
}

// Success response
echo json_encode(['success' => true, 'message' => 'Result deleted successfully']);
?>