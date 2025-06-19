#!/usr/bin/env php
<?php

function testMultiDomainSupport(): bool {
    $configFile = __DIR__ . '/data/dns_config.json';
    $performanceFile = __DIR__ . '/data/dns_performance.json';
    
    $requiredDomains = [
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
    
    echo "Testing Multi-Domain DNS Support\n";
    echo "================================\n\n";
    
    // Test 1: Check config contains all required domains
    echo "Test 1: Configuration contains all 10 domains\n";
    if (!file_exists($configFile)) {
        echo "❌ FAIL: Config file missing\n";
        return false;
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    if (!$config || !isset($config['domains'])) {
        echo "❌ FAIL: Invalid config structure\n";
        return false;
    }
    
    $configuredDomains = $config['domains'];
    echo "Configured domains: " . implode(', ', $configuredDomains) . "\n";
    echo "Required domains: " . implode(', ', $requiredDomains) . "\n";
    
    $missingDomains = array_diff($requiredDomains, $configuredDomains);
    if (!empty($missingDomains)) {
        echo "❌ FAIL: Missing domains: " . implode(', ', $missingDomains) . "\n";
        return false;
    }
    
    if (count($configuredDomains) !== count($requiredDomains)) {
        echo "❌ FAIL: Expected " . count($requiredDomains) . " domains, got " . count($configuredDomains) . "\n";
        return false;
    }
    
    echo "✅ PASS: All 10 domains configured\n\n";
    
    // Test 2: Check performance data contains multiple domains
    echo "Test 2: Performance data contains multiple domains\n";
    if (!file_exists($performanceFile)) {
        echo "❌ FAIL: Performance file missing\n";
        return false;
    }
    
    $performanceData = json_decode(file_get_contents($performanceFile), true);
    if (!$performanceData || !isset($performanceData['tests'])) {
        echo "❌ FAIL: Invalid performance data structure\n";
        return false;
    }
    
    $uniqueDomains = [];
    foreach ($performanceData['tests'] as $test) {
        if (isset($test['domain'])) {
            $uniqueDomains[$test['domain']] = true;
        }
    }
    
    $foundDomains = array_keys($uniqueDomains);
    echo "Domains found in performance data: " . implode(', ', $foundDomains) . "\n";
    
    if (count($foundDomains) < 5) {
        echo "❌ FAIL: Expected at least 5 domains in performance data, got " . count($foundDomains) . "\n";
        return false;
    }
    
    echo "✅ PASS: Multiple domains found in performance data\n\n";
    
    // Test 3: Check recent test cycle contains all domains
    echo "Test 3: Recent test cycle contains all configured domains\n";
    
    $latestCycleId = $performanceData['metadata']['current_cycle_id'] ?? null;
    if (!$latestCycleId) {
        echo "❌ FAIL: No current cycle ID found\n";
        return false;
    }
    
    $latestCycleDomains = [];
    foreach ($performanceData['tests'] as $test) {
        if (isset($test['cycle_id']) && $test['cycle_id'] === $latestCycleId) {
            $latestCycleDomains[$test['domain']] = true;
        }
    }
    
    $latestDomains = array_keys($latestCycleDomains);
    echo "Domains in latest cycle ($latestCycleId): " . implode(', ', $latestDomains) . "\n";
    
    $expectedDomainCount = count($configuredDomains);
    $actualDomainCount = count($latestDomains);
    
    if ($actualDomainCount !== $expectedDomainCount) {
        echo "❌ FAIL: Expected $expectedDomainCount domains in latest cycle, got $actualDomainCount\n";
        return false;
    }
    
    $missingFromCycle = array_diff($configuredDomains, $latestDomains);
    if (!empty($missingFromCycle)) {
        echo "❌ FAIL: Missing from latest cycle: " . implode(', ', $missingFromCycle) . "\n";
        return false;
    }
    
    echo "✅ PASS: Latest cycle contains all configured domains\n\n";
    
    echo "🎉 ALL TESTS PASSED: Multi-domain support working correctly\n";
    return true;
}

// Run the test
$success = testMultiDomainSupport();
exit($success ? 0 : 1);