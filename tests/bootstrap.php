<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

// Load environment variables for testing
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Set default timezone
date_default_timezone_set('UTC');

// Display errors during testing
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Check if NATS server should be started automatically
if (env('START_NATS_FOR_TESTS', false)) {
    echo "Starting NATS server for tests...\n";

    if (!isNatsServerRunning()) {
        $process = startNatsServer();
        if ($process) {
            register_shutdown_function(function () use ($process) {
                echo "Stopping NATS server...\n";
                stopNatsServer($process);
            });

            // Wait for server to be ready
            sleep(3);
        }
    }
}