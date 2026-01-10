<?php

require __DIR__.'/vendor/autoload.php';

use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;

$config = [
    'host' => 'localhost',
    'port' => 4222,
    'timeout' => 5,
    'reconnect' => true,
    'debug' => true,
];

echo "Testing NATS Broadcaster...\n";

try {
    $broadcaster = new NatsBroadcaster($config);
    
    // Test connection
    $broadcaster->connect();
    echo "✅ Connected to NATS\n";
    
    // Test broadcast
    $channels = ['test-channel'];
    $event = 'TestEvent';
    $payload = ['message' => 'Hello from test', 'timestamp' => date('c')];
    
    $broadcaster->broadcast($channels, $event, $payload);
    echo "✅ Message broadcasted\n";
    
    // Test subscription
    $broadcaster->subscribe('test-channel', function ($message) {
        echo "✅ Received message: " . json_encode($message) . "\n";
    });
    echo "✅ Subscribed to test-channel\n";
    
    // Clean up
    $broadcaster->disconnect();
    echo "✅ Disconnected\n";
    
    echo "\n🎉 All tests passed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}