<?php

echo "🔐 NATS Authentication Debug\n";
echo "=============================\n\n";

// Test with different credential combinations
$testCases = [
    [
        'name' => 'Local user credentials',
        'config' => [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'local',
            'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
            'debug' => true,
            'verbose' => true,
        ]
    ],
    [
        'name' => 'System user credentials',
        'config' => [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'system',
            'pass' => 'LsVYSAeCr7HveUAigwGdinxyZpIxQk5g',
            'debug' => true,
            'verbose' => true,
        ]
    ],
    [
        'name' => 'Service user credentials',
        'config' => [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'service',
            'pass' => 'aqV24E1iBAffEAIHmbYJ4HeynQ520ndA',
            'debug' => true,
            'verbose' => true,
        ]
    ],
    [
        'name' => 'No authentication',
        'config' => [
            'host' => 'localhost',
            'port' => 53969,
            'debug' => true,
            'verbose' => true,
        ]
    ]
];

require __DIR__ . '/../vendor/autoload.php';

foreach ($testCases as $testCase) {
    echo "🧪 Testing: {$testCase['name']}\n";
    echo "   Config: " . json_encode($testCase['config']) . "\n";

    try {
        $options = new Nats\ConnectionOptions($testCase['config']);
        $client = new Nats\Client($options);

        $client->connect();
        echo "✅ SUCCESS: Connected!\n\n";

        // Test publish
        $client->publish('test.auth', json_encode(['test' => $testCase['name']]));
        echo "   Published test message\n";

        $client->close();
        break; // Stop at first success

    } catch (\Exception $e) {
        echo "❌ FAILED: " . $e->getMessage() . "\n";
        echo "   Error code: " . $e->getCode() . "\n";
        echo "\n";
    }
}