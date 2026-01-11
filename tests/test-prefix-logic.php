<?php
// tests/test-prefix-logic.php

echo "🧪 Testing Prefix Logic\n";
echo "=======================\n\n";

// Test the logic step by step
function debugChannelConversion($channel, $configPrefix = '')
{
    echo "Input: '{$channel}'";
    if ($configPrefix) {
        echo " (prefix: '{$configPrefix}')";
    }
    echo "\n";

    $subject = $channel;
    $channelType = null;

    // Determine channel type and strip prefix
    echo "  Step 1 - Check channel type:\n";
    if (str_starts_with($subject, 'private-encrypted-')) {
        $channelType = 'private.encrypted.';
        $subject = substr($subject, strlen('private-encrypted-'));
        echo "    Found private-encrypted-, type: {$channelType}, remaining: '{$subject}'\n";
    } elseif (str_starts_with($subject, 'private-')) {
        $channelType = 'private.';
        $subject = substr($subject, strlen('private-'));
        echo "    Found private-, type: {$channelType}, remaining: '{$subject}'\n";
    } elseif (str_starts_with($subject, 'presence-')) {
        $channelType = 'presence.';
        $subject = substr($subject, strlen('presence-'));
        echo "    Found presence-, type: {$channelType}, remaining: '{$subject}'\n";
    } else {
        echo "    No special channel type found\n";
    }

    // Convert dots to hyphens for NATS subject hierarchy
    echo "  Step 2 - Convert dots to hyphens:\n";
    $original = $subject;
    $subject = str_replace('.', '-', $subject);
    echo "    '{$original}' => '{$subject}'\n";

    // Add channel type back if it was a special channel
    echo "  Step 3 - Add channel type back:\n";
    if ($channelType !== null) {
        $subject = $channelType . $subject;
        echo "    Added '{$channelType}' => '{$subject}'\n";
    } else {
        echo "    No channel type to add\n";
    }

    // Add application prefix for non-special channels
    echo "  Step 4 - Add application prefix:\n";
    if ($configPrefix && $channelType === null) {
        if (!str_starts_with($subject, $configPrefix . '.')) {
            $subject = $configPrefix . '.' . $subject;
            echo "    Added prefix '{$configPrefix}.' => '{$subject}'\n";
        } else {
            echo "    Already starts with prefix, no change\n";
        }
    } else {
        echo "    No prefix to add (prefix: '{$configPrefix}', channelType: " . ($channelType ?: 'null') . ")\n";
    }

    echo "  Result: '{$subject}'\n\n";

    return $subject;
}

// Test cases
echo "Test 1: test.channel with prefix 'test'\n";
debugChannelConversion('test.channel', 'test');

echo "\nTest 2: test.channel with prefix 'app'\n";
debugChannelConversion('test.channel', 'app');

echo "\nTest 3: test.channel with no prefix\n";
debugChannelConversion('test.channel', '');

echo "\nTest 4: private-channel with prefix 'test'\n";
debugChannelConversion('private-channel', 'test');

echo "\nTest 5: myapp.chat.message with prefix 'myapp'\n";
debugChannelConversion('myapp.chat.message', 'myapp');

// Now test what happens with the actual broadcaster
echo "\n🧪 Testing with actual broadcaster:\n";
echo str_repeat('-', 80) . "\n";

require __DIR__ . '/../vendor/autoload.php';

// Test 1: With prefix 'test'
$broadcaster1 = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster([
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'prefix' => 'test',
    'debug' => false,
]);

$reflection = new ReflectionClass($broadcaster1);
$method = $reflection->getMethod('getSubjectFromChannel');
$method->setAccessible(true);

echo "Broadcaster with prefix 'test':\n";
echo "  test.channel => " . $method->invoke($broadcaster1, 'test.channel') . "\n";
echo "  Expected: test.test-channel (with prefix)\n\n";

// Test 2: Without prefix
$broadcaster2 = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster([
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'debug' => false,
]);

$reflection = new ReflectionClass($broadcaster2);
$method = $reflection->getMethod('getSubjectFromChannel');
$method->setAccessible(true);

echo "Broadcaster without prefix:\n";
echo "  test.channel => " . $method->invoke($broadcaster2, 'test.channel') . "\n";
echo "  Expected: test-channel (no prefix)\n\n";

// The issue might be in the test expectations!
echo "💡 Analysis:\n";
echo "The test expects 'test-test-channel' but gets 'test.test-channel'\n";
echo "This suggests either:\n";
echo "1. The test expectation is wrong (should be 'test.test-channel' with prefix)\n";
echo "2. There's an issue with double 'test' in 'test.test-channel'\n";
echo "3. Maybe we need to prevent adding prefix when channel starts with same word?\n";

// Let's check what makes sense
echo "\n🤔 What should 'test.channel' with prefix 'test' become?\n";
echo "Option A: test.test-channel (current behavior)\n";
echo "Option B: test-channel (skip prefix if channel starts with same word)\n";
echo "Option C: test-test-channel (convert dots differently?)\n";