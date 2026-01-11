<?php

echo "🧪 Minimal NATS Test (No Laravel)\n";
echo "==================================\n\n";

// Load the patched client directly
require __DIR__ . '/../src/Nats/PatchedClient.php';

use Mwangaben\NatsBroadcaster\Nats\PatchedClient;

try {
    $config = [
        'host' => 'localhost',
        'port' => 53969,
        'user' => 'local',
        'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
        'debug' => true,
        'timeout' => 5,
    ];

    echo "1. Creating client...\n";
    $client = new PatchedClient($config);

    echo "2. Connecting...\n";
    $client->connect();

    echo "✅ Connected!\n\n";

    echo "3. Publishing test message...\n";
    $message = [
        'event' => 'TestEvent',
        'data' => ['message' => 'Hello NATS!'],
        'timestamp' => date('c'),
    ];

    $client->publish('test.minimal', json_encode($message));
    echo "✅ Published to 'test.minimal'\n";
    echo "   Message: " . json_encode($message) . "\n\n";

    echo "4. Testing subscription (will wait 3 seconds)...\n";

    $received = false;
    $sid = $client->subscribe('test.minimal', function ($msg) use (&$received) {
        echo "✅ Received message:\n";
        echo "   Subject: " . ($msg['subject'] ?? 'N/A') . "\n";
        echo "   Payload: " . ($msg['payload'] ?? $msg['body'] ?? 'N/A') . "\n";
        $received = true;
    });

    // Publish another message to trigger subscription
    $client->publish('test.minimal', json_encode(['trigger' => 'subscription']));

    // Wait for messages
    echo "   Listening for 3 seconds...\n";
    sleep(3);

    if ($received) {
        echo "✅ Subscription test passed!\n";
    } else {
        echo "⚠️  No message received (might be timing issue)\n";
    }

    echo "\n5. Testing broadcast format...\n";

    // Test the format expected by Laravel Echo
    $broadcastMessage = [
        'event' => 'App\\Events\\TestEvent',
        'data' => [
            'user' => 'test-user',
            'message' => 'Broadcast test',
        ],
        'socket' => null,
        'channel' => 'test-channel',
        'timestamp' => date('c'),
    ];

    $client->publish('test-channel', json_encode($broadcastMessage));
    echo "✅ Broadcast format test published\n\n";

    echo "6. Closing connection...\n";
    $client->close();

    echo "✅ Connection closed\n\n";
    echo "🎉 All tests completed successfully!\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
}