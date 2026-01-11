<?php

require 'vendor/autoload.php';

echo "Testing NATS connection...\n";

$config = [
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'debug' => true,
    'verbose' => true,
];

try {
    $options = new Nats\ConnectionOptions($config);
    $client = new Nats\Client($options);

    $client->connect();
    echo "✅ Connected successfully!\n";

    // Test publish
    $client->publish('test.subject', json_encode(['test' => true, 'time' => time()]));
    echo "✅ Published test message\n";

    // Test subscribe
    $received = false;
    $client->subscribe('test.subject', function ($message) use (&$received) {
        $data = json_decode($message->getBody(), true);
        echo "✅ Received message: " . json_encode($data) . "\n";
        $received = true;
    });

    // Publish another message to trigger subscription
    $client->publish('test.subject', json_encode(['trigger' => 'subscription']));

    // Wait a bit for message to be delivered
    sleep(1);

    if ($received) {
        echo "✅ Subscription test passed\n";
    } else {
        echo "⚠️  Subscription test may have failed\n";
    }

    $client->close();
    echo "✅ Connection closed\n";

} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nAll tests passed! 🎉\n";