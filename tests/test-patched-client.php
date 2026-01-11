<?php
// tests/test-patched-client.php

require __DIR__ . '/../src/Nats/PatchedClient.php';

use Mwangaben\NatsBroadcaster\Nats\PatchedClient;

echo "🧪 Testing Patched NATS Client\n";
echo "===============================\n\n";

try {
    $config = [
        'host' => 'localhost',
        'port' => 53969,
        'user' => 'local',
        'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
        'debug' => true,
        'timeout' => 5,
    ];

    echo "Creating client...\n";
    $client = new PatchedClient($config);

    echo "Connecting...\n";
    $client->connect();

    echo "✅ Connected successfully!\n\n";

    echo "Publishing test message...\n";
    $client->publish('test.patched', json_encode([
        'message' => 'Hello from patched client!',
        'timestamp' => date('c'),
    ]));

    echo "✅ Published!\n\n";

    echo "Testing subscription...\n";
    $received = false;

    // Note: This is a simple test - in production you'd need proper async handling
    $client->subscribe('test.patched', function ($message) use (&$received) {
        echo "✅ Received: " . json_encode($message) . "\n";
        $received = true;
    });

    // Publish another to trigger
    $client->publish('test.patched', json_encode(['trigger' => 'subscription']));

    // Wait a bit
    echo "Waiting for message...\n";
    sleep(1);

    $client->close();
    echo "✅ Closed connection\n";

    echo "\n🎉 Patched client works!\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}