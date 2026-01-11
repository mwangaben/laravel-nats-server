<?php

// Load test configuration
$testConfig = require __DIR__ . '/config.php';

// Define constants for test configuration ONLY if they don't exist
if (!defined('NATS_TEST_HOST')) {
    define('NATS_TEST_HOST', $testConfig['host']);
}

if (!defined('NATS_TEST_PORT')) {
    define('NATS_TEST_PORT', $testConfig['port']);
}

if (!defined('NATS_TEST_USER')) {
    define('NATS_TEST_USER', $testConfig['user']);
}

if (!defined('NATS_TEST_PASSWORD')) {
    define('NATS_TEST_PASSWORD', $testConfig['password']);
}

if (!defined('NATS_DEBUG')) {
    define('NATS_DEBUG', $testConfig['debug'] ?? false);
}

require_once __DIR__ . '/../vendor/autoload.php';