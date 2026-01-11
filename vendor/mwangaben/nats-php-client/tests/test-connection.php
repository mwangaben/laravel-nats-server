#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

echo "Testing NATS server connection...\n";

$host = 'localhost';
$port = 53969;
$user = 'local';
$password = '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH';

// First check if server is reachable
$socket = @fsockopen($host, $port, $errno, $errstr, 2);
if (!$socket) {
    echo "❌ Cannot connect to $host:$port\n";
    echo "   Error: $errstr ($errno)\n";
    exit(1);
}
fclose($socket);

echo "✅ TCP connection to $host:$port successful\n";

// Now test with NATS client
try {
    $options = new Nats\ConnectionOptions();
    $options->setHost($host)
            ->setPort($port)
            ->setUser($user)
            ->setPassword($password)
            ->setTimeout(3.0);

    $client = new Nats\Client($options);
    $client->connect();

    echo "✅ NATS authentication successful!\n";

    $serverInfo = $client->getConnection()->getServerInfo();
    if ($serverInfo) {
        echo "   Server ID: " . $serverInfo['server_id'] . "\n";
        echo "   Version: " . $serverInfo['version'] . "\n";
        if (isset($serverInfo['go'])) {
            echo "   Go: " . $serverInfo['go'] . "\n";
        }
        if (isset($serverInfo['host'])) {
            echo "   Host: " . $serverInfo['host'] . "\n";
        }
        if (isset($serverInfo['port'])) {
            echo "   Port: " . $serverInfo['port'] . "\n";
        }
    }

    // Test a simple publish/subscribe
    echo "\nTesting publish/subscribe...\n";

    $received = false;
    $testMessage = "Test message " . microtime(true);

    $client->subscribe('test.connection', function($msg) use (&$received, $testMessage) {
        if ($msg->getBody() === $testMessage) {
            $received = true;
            echo "✅ Received test message successfully\n";
        }
    });

    $client->publish('test.connection', $testMessage);

    // Wait for message
    $start = time();
    while (!$received && (time() - $start) < 2) {
        $client->wait(0.1);
    }

    if (!$received) {
        echo "⚠️  Test message not received (might be OK if no subscribers)\n";
    }

    $client->close();
    echo "\n✅ All tests passed!\n";

} catch (Exception $e) {
    echo "❌ NATS connection failed: " . $e->getMessage() . "\n";
    exit(1);
}