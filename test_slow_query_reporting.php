#!/usr/bin/env php
<?php

function runReportingTests(): bool
{
    echo "Running Slow Query Reporting API & UI Tests...\n";
    echo "==============================================\n\n";

    $results = [];
    $results['testApiSummaryEndpoint'] = testApiSummaryEndpoint();
    $results['testApiDetailEndpoint'] = testApiDetailEndpoint();
    $results['testUiStructureReadiness'] = testUiStructureReadiness();

    $overallSuccess = !in_array(false, $results, true);

    echo "\n----------------------------------------------\n";
    if ($overallSuccess) {
        echo "✅ ALL REPORTING TESTS PASSED!\n";
    } else {
        echo "❌ SOME REPORTING TESTS FAILED.\n";
    }
    echo "==============================================\n";

    return $overallSuccess;
}

function testApiSummaryEndpoint(): bool
{
    echo "Test 1: API summary endpoint returns correct, sorted JSON\n";
    $apiFile = __DIR__ . '/slow-query-api.php';
    if (!file_exists($apiFile)) {
        echo "❌ FAIL: API file '{$apiFile}' does not exist yet.\n";
        return false;
    }

    // Setup: Create a mock data file for the API to read
    $slowLogFile = __DIR__ . '/data/slow_queries.json';
    $mockData = [
        'queries' => [
            ['server' => '8.8.8.8', 'domain' => 'a.com', 'response_time' => 1100, 'timestamp' => date('c')],
            ['server' => '1.1.1.1', 'domain' => 'b.com', 'response_time' => 1200, 'timestamp' => date('c')],
            ['server' => '8.8.8.8', 'domain' => 'c.com', 'response_time' => 1300, 'timestamp' => date('c')],
        ]
    ];
    file_put_contents($slowLogFile, json_encode($mockData));

    $responseJson = shell_exec("/usr/bin/php8.3 -r '\$_GET[\"action\"] = \"summary\"; include \"{$apiFile}\";'");
    $response = json_decode($responseJson, true);

    if (!$response || !isset($response['success']) || $response['success'] !== true) {
        echo "❌ FAIL: API response was not successful or invalid JSON.\n";
        return false;
    }
    if (!isset($response['summary']) || count($response['summary']) !== 2) {
        echo "❌ FAIL: API summary did not return the correct number of servers.\n";
        return false;
    }
    if ($response['summary'][0]['server'] !== '8.8.8.8' || $response['summary'][0]['count'] !== 2) {
        echo "❌ FAIL: API summary is not sorted correctly by count (most frequent first).\n";
        return false;
    }

    echo "✅ PASS: API summary endpoint is working correctly.\n\n";
    @unlink($slowLogFile);
    return true;
}

function testApiDetailEndpoint(): bool
{
    echo "Test 2: API detail endpoint returns correct HTML content\n";
    $apiFile = __DIR__ . '/slow-query-api.php';
    if (!file_exists($apiFile)) {
        // This is already checked above, but good practice for standalone tests
        echo "❌ FAIL: API file '{$apiFile}' does not exist yet.\n";
        return false;
    }

    // Setup: Use the same mock data
    $slowLogFile = __DIR__ . '/data/slow_queries.json';
    $mockData = [
        'queries' => [
            ['server' => '8.8.8.8', 'domain' => 'a.com', 'response_time' => 1100, 'timestamp' => date('c')],
            ['server' => '1.1.1.1', 'domain' => 'b.com', 'response_time' => 1200, 'timestamp' => date('c')],
            ['server' => '8.8.8.8', 'domain' => 'c.com', 'response_time' => 1300, 'timestamp' => date('c')],
        ]
    ];
    file_put_contents($slowLogFile, json_encode($mockData));

    $responseHtml = shell_exec("/usr/bin/php8.3 -r '\$_GET[\"action\"] = \"detail\"; \$_GET[\"server\"] = \"8.8.8.8\"; include \"{$apiFile}\";'");

    // Check for HTML content instead of header (since we can't capture headers in this test method)
    if (strpos($responseHtml, '<!DOCTYPE html>') === false) {
        echo "❌ FAIL: API detail response is not valid HTML.\n";
        return false;
    }
    if (substr_count($responseHtml, '<tr>') < 2) { // At least 1 for header, 1+ for data rows
        echo "❌ FAIL: HTML response for server 8.8.8.8 did not contain the expected table rows.\n";
        return false;
    }
    if (strpos($responseHtml, '>c.com</td>') === false) {
        echo "❌ FAIL: HTML response is missing expected data.\n";
        return false;
    }

    echo "✅ PASS: API detail endpoint is working correctly.\n\n";
    @unlink($slowLogFile);
    return true;
}

function testUiStructureReadiness(): bool
{
    echo "Test 3: index.html has the required placeholders for the new UI\n";
    $uiFile = __DIR__ . '/index.html';
    if (!file_exists($uiFile)) {
        echo "❌ FAIL: UI file '{$uiFile}' does not exist.\n";
        return false;
    }

    $htmlContent = file_get_contents($uiFile);

    if (strpos($htmlContent, 'id="dnsMonitoring"') === false) {
        echo "❌ FAIL: The anchor element 'id=\"dnsMonitoring\"' for placing the new report is missing.\n";
        return false;
    }
    if (strpos($htmlContent, 'function loadDnsStatus()') === false) {
        echo "❌ FAIL: The required JS function 'loadDnsStatus' (to add our new call into) is missing.\n";
        return false;
    }

    echo "✅ PASS: UI file has the necessary anchor points.\n\n";
    return true;
}


// To allow running this file directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
    runReportingTests();
}

?>