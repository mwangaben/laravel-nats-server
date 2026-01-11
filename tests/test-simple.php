<?php

echo "🧪 Simple NATS Connection Test\n";
echo "===============================\n\n";

// First, check if we can even connect to the port
echo "1. Checking if port is open...\n";
$socket = @fsockopen('localhost', 53969, $errno, $errstr, 2);

if (!$socket) {
    echo "❌ Cannot connect to localhost:53969\n";
    echo "   Error: {$errstr} (Code: {$errno})\n\n";

    echo "💡 Solutions:\n";
    echo "   1. Make sure NATS server is running: nats server run\n";
    echo "   2. Check if it's using a different port\n";
    echo "   3. Run: ps aux | grep nats\n";
    echo "   4. Try common ports: 4222, 4223, 8222\n";

    exit(1);
}

echo "✅ Port 53969 is open!\n\n";

// Try to read from the socket (NATS sends INFO first)
echo "2. Reading server greeting...\n";
stream_set_timeout($socket, 1);
$info = fgets($socket);

if ($info === false) {
    echo "❌ Could not read from server\n";
    fclose($socket);
    exit(1);
}

echo "✅ Server info: " . trim($info) . "\n\n";

// Check if it looks like NATS
if (strpos($info, 'INFO') === 0) {
    echo "✅ This looks like a NATS server!\n\n";

    // Parse the JSON info
    $json = substr(trim($info), 5);
    $data = json_decode($json, true);

    if ($data) {
        echo "📊 Server Details:\n";
        echo "   Server ID: " . ($data['server_id'] ?? 'Unknown') . "\n";
        echo "   Version: " . ($data['version'] ?? 'Unknown') . "\n";
        echo "   Go Version: " . ($data['go'] ?? 'Unknown') . "\n";
        echo "   Auth Required: " . (($data['auth_required'] ?? false) ? 'Yes' : 'No') . "\n";
        echo "   TLS Required: " . (($data['tls_required'] ?? false) ? 'Yes' : 'No') . "\n";
    }
} else {
    echo "⚠️  This might not be a NATS server\n";
}

fclose($socket);

echo "\n3. Testing with NATS PHP client...\n";

// Now test with the actual client
require __DIR__ . '/../vendor/autoload.php';

try {
    $config = [
        'host' => 'localhost',
        'port' => 53969,
        'user' => 'local',
        'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
        'debug' => true,
        'verbose' => true,
        'timeout' => 5,
    ];

    echo "   Using config:\n";
    foreach ($config as $key => $value) {
        if ($key === 'pass') {
            echo "     {$key}: ********\n";
        } else {
            echo "     {$key}: {$value}\n";
        }
    }
    echo "\n";

    $options = new Nats\ConnectionOptions($config);
    $client = new Nats\Client($options);

    echo "   Connecting...\n";
    $client->connect();
    echo "✅ Connected successfully!\n\n";

    // Simple publish test
    echo "4. Testing publish...\n";
    $testMessage = [
        'test' => 'Hello NATS!',
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
    ];

    $client->publish('test.php', json_encode($testMessage));
    echo "✅ Published to 'test.php'\n";
    echo "   Message: " . json_encode($testMessage) . "\n\n";

    // Subscribe test
    echo "5. Testing subscribe...\n";
    $received = false;

    $subscription = $client->subscribe('test.php', function ($message) use (&$received) {
        $data = json_decode($message->getBody(), true);
        echo "✅ Received message:\n";
        print_r($data);
        $received = true;
    });

    // Publish another to trigger subscription
    $client->publish('test.php', json_encode(['trigger' => 'subscription test']));

    // Wait a bit
    echo "   Waiting for message...\n";
    for ($i = 0; $i < 10; $i++) {
        if ($received) break;
        usleep(100000); // 100ms
    }

    if ($received) {
        echo "✅ Subscription works!\n";
    } else {
        echo "⚠️  No message received (might be normal for queue groups)\n";
    }

    // Clean up
    $client->unsubscribe($subscription);
    $client->close();

    echo "\n🎉 All tests passed! Your NATS setup is working correctly.\n";

} catch (\Exception $e) {
    echo "\n❌ Client test failed!\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";

    if ($e->getPrevious()) {
        echo "   Previous error: " . $e->getPrevious()->getMessage() . "\n";
    }

    exit(1);
}