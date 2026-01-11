<?php
// tests/test-final.php

echo "🧪 Final Integration Test\n";
echo "=========================\n\n";

require __DIR__ . '/../vendor/autoload.php';

// Test the broadcaster directly
$config = [
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'debug' => true,
    'prefix' => 'test',
];

try {
    echo "1. Creating NatsBroadcaster...\n";
    $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster($config);

    echo "2. Connecting...\n";
    $broadcaster->connect();

    echo "✅ Connected: " . ($broadcaster->isConnected() ? 'Yes' : 'No') . "\n\n";

    echo "3. Broadcasting test message...\n";
    $broadcaster->broadcast(['test.channel'], 'TestEvent', [
        'message' => 'Hello from broadcaster!',
        'data' => ['foo' => 'bar'],
        'timestamp' => date('c'),
    ]);

    echo "✅ Broadcast successful!\n\n";

    echo "4. Testing subscription...\n";
    $received = false;

    $broadcaster->subscribe('test.channel', function ($message) use (&$received) {
        echo "✅ Received broadcast: " . json_encode($message) . "\n";
        $received = true;
    });

    // Broadcast another to trigger subscription
    $broadcaster->broadcast(['test.channel'], 'SubscriptionTest', [
        'test' => 'subscription',
    ]);

    echo "Waiting for subscription...\n";
    sleep(2);

    if ($received) {
        echo "✅ Subscription test passed!\n";
    } else {
        echo "⚠️  Subscription may not have received message\n";
    }

    echo "\n5. Testing channel name conversion...\n";
    $reflection = new ReflectionClass($broadcaster);
    $method = $reflection->getMethod('getSubjectFromChannel');
    $method->setAccessible(true);

    $testCases = [
        'test.channel' => 'test-test-channel',
        'private-channel' => 'private.channel',
        'presence-channel' => 'presence.channel',
    ];

    foreach ($testCases as $input => $expected) {
        $result = $method->invoke($broadcaster, $input);
        echo "  {$input} => {$result} ";
        echo ($result === $expected) ? "✅\n" : "❌ (expected: {$expected})\n";
    }

    $broadcaster->disconnect();
    echo "\n✅ Disconnected\n";

    echo "\n🎉 All tests completed!\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
}