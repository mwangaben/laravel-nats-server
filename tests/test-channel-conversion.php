<?php
// tests/test-channel-conversion.php

echo "🧪 Testing Channel Name Conversion\n";
echo "==================================\n\n";

// Mock functions for testing
if (!function_exists('config')) {
    function config($key, $default = null) { return $default; }
}

require __DIR__ . '/../vendor/autoload.php';

// Test cases: [input, expected_output, config]
$testCases = [
    // Basic cases
    ['test.channel', 'test-channel', []],
    ['user.123.notifications', 'user-123-notifications', []],

    // Private channels
    ['private-channel', 'private.channel', []],
    ['private-user.123', 'private.user-123', []],
    ['private-app.chat.123', 'private.app-chat-123', []],

    // Presence channels
    ['presence-channel', 'presence.channel', []],
    ['presence-chat.room', 'presence.chat-room', []],

    // Private encrypted channels
    ['private-encrypted-channel', 'private.encrypted.channel', []],
    ['private-encrypted-chat.123', 'private.encrypted.chat-123', []],

    // With prefix
    ['test.channel', 'myapp.test-channel', ['prefix' => 'myapp']],
    ['private-channel', 'private.channel', ['prefix' => 'myapp']], // Should NOT add prefix to private
    ['presence-channel', 'presence.channel', ['prefix' => 'myapp']], // Should NOT add prefix to presence
    ['private-encrypted-channel', 'private.encrypted.channel', ['prefix' => 'myapp']], // Should NOT add prefix

    // Complex cases with prefix
    ['myapp.chat.message', 'myapp.myapp-chat-message', ['prefix' => 'myapp']],
    ['private-myapp.chat', 'private.myapp-chat', ['prefix' => 'myapp']],
];

$broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster([
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'debug' => false,
]);

$reflection = new ReflectionClass($broadcaster);
$method = $reflection->getMethod('getSubjectFromChannel');
$method->setAccessible(true);

echo "Testing without prefix:\n";
echo str_repeat('-', 80) . "\n";

foreach ($testCases as $index => $testCase) {
    if (!empty($testCase[2]['prefix'])) continue; // Skip prefixed for now

    list($input, $expected, $config) = $testCase;

    // Update config if needed
    if ($config) {
        $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster(array_merge([
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'local',
            'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
            'debug' => false,
        ], $config));

        $reflection = new ReflectionClass($broadcaster);
        $method = $reflection->getMethod('getSubjectFromChannel');
        $method->setAccessible(true);
    }

    $result = $method->invoke($broadcaster, $input);
    $status = $result === $expected ? '✅' : '❌';

    echo "{$status} {$input} => {$result}";
    if ($result !== $expected) {
        echo " (expected: {$expected})";
    }
    echo "\n";
}

echo "\nTesting with prefix 'myapp':\n";
echo str_repeat('-', 80) . "\n";

$broadcasterWithPrefix = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster([
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'prefix' => 'myapp',
    'debug' => false,
]);

$reflection = new ReflectionClass($broadcasterWithPrefix);
$method = $reflection->getMethod('getSubjectFromChannel');
$method->setAccessible(true);

foreach ($testCases as $testCase) {
    list($input, $expected, $config) = $testCase;

    if (empty($config['prefix'])) continue; // Only test prefixed cases

    $result = $method->invoke($broadcasterWithPrefix, $input);
    $status = $result === $expected ? '✅' : '❌';

    echo "{$status} {$input} => {$result}";
    if ($result !== $expected) {
        echo " (expected: {$expected})";
    }
    echo "\n";
}

echo "\n🧪 Special test cases for Laravel Echo compatibility:\n";
echo str_repeat('-', 80) . "\n";

$echoTestCases = [
    // Laravel Echo style channels
    ['private-App.Models.User.123', 'private.App-Models-User-123', 'Private model channel'],
    ['presence-chat.room.456', 'presence.chat-room-456', 'Presence channel'],
    ['private-encrypted-messages.789', 'private.encrypted.messages-789', 'Private encrypted channel'],
];

foreach ($echoTestCases as $testCase) {
    list($input, $expected, $description) = $testCase;

    $result = $method->invoke($broadcasterWithPrefix, $input);
    $status = $result === $expected ? '✅' : '❌';

    echo "{$status} {$description}\n";
    echo "   Input:    {$input}\n";
    echo "   Output:   {$result}\n";
    echo "   Expected: {$expected}\n\n";
}