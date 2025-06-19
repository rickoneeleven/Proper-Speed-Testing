#!/bin/bash
# Wrapper script to run tests with PHP 8.3

echo "Running DNS Slow Query Tests with PHP 8.3..."
echo "=============================================="

echo -e "\n--- Daemon Tests ---"
/usr/bin/php8.3 test_slow_query_daemon.php

echo -e "\n--- Reporting Tests ---"  
/usr/bin/php8.3 test_slow_query_reporting.php

echo -e "\nAll tests completed!"