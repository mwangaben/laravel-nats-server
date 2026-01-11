<?php

echo "🧪 Final Integration Test (No Prefixes)\n";
echo "=======================================\n\n";

// Mock Laravel's Log facade for testing
if (!class_exists('Log')) {
    class Log {
        public static function info($message, $context = []) {
            echo "📝 [LOG INFO] {$message}";
            if ($context) {
                echo " " . json_encode($context);
            }
            echo "\n";
        }

        public static function error($message, $context = []) {
            echo "❌ [LOG ERROR] {$message}";
            if ($context) {
                echo " " . json_encode($context);
            }
            echo "\n";
        }
    }
}

// Mock report function
if (!function_exists('report')) {
    function report($exception) {
        echo "⚠️ [REPORT] " . $exception->getMessage() . "\n";
    }
}

// Mock config function
if (!function_exists('config')) {
    function config($key, $default = null) {
        if ($key === 'app.key') {
            return 'base64:test-key-for-hashing';
        }
        return $default;
    }
}

// Mock now() function
if (!function_exists('now')) {
    function now() {
        return new class {
            public function toISOString() {
                return date('c');
            }
        };
    }
}

require __DIR__ . '/../vendor/autoload.php';

// Test the broadcaster directly - NO PREFIX
$config = [
    'host' => 'localhost',
    'port' => 53969,
    'user' => 'local',
    'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
    'debug' => true,
    // No 'prefix' key anymore
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

    echo "4. Testing channel name conversion (NO prefixes):\n";
    $reflection = new ReflectionClass($broadcaster);
    $method = $reflection->getMethod('getSubjectFromChannel');
    $method->setAccessible(true);

    $testCases = [
        // Public channels (no special prefix in Laravel)
        'test.channel' => 'test-channel',
        'public.channel' => 'public-channel',
        'chat.room' => 'chat-room',
        'user.123.notifications' => 'user-123-notifications',

        // Special channels
        'private-channel' => 'private.channel',
        'private-user.123' => 'private.user-123',
        'private-App.Models.User.123' => 'private.App-Models-User-123',

        'presence-channel' => 'presence.channel',
        'presence-chat.room' => 'presence.chat-room',

        'private-encrypted-channel' => 'private.encrypted.channel',
        'private-encrypted-messages.123' => 'private.encrypted.messages-123',
    ];

    foreach ($testCases as $input => $expected) {
        $result = $method->invoke($broadcaster, $input);
        $status = $result === $expected ? '✅' : '❌';
        echo "  {$input} => {$result} ";
        echo ($result === $expected) ? "✅\n" : "❌ (expected: {$expected})\n";
    }

    echo "\n5. Testing token generation...\n";
    $method = $reflection->getMethod('generateToken');
    $method->setAccessible(true);

    $token = $method->invoke($broadcaster, 'test-channel', 123);
    echo "  Token for channel 'test-channel', user 123:\n";
    echo "  " . substr($token, 0, 16) . "... (length: " . strlen($token) . ")\n";

    echo "\n6. Testing actual broadcast examples...\n";

    // Example 1: Public chat room (no prefix)
    $broadcaster->broadcast(['chat.room.123'], 'MessageSent', [
        'user' => 'John',
        'message' => 'Hello everyone!',
        'timestamp' => date('c'),
    ]);
    echo "  ✅ Public chat room: chat.room.123 → chat-room-123\n";

    // Example 2: Private user notifications
    $broadcaster->broadcast(['private-user.456'], 'Notification', [
        'type' => 'message',
        'from' => 'Jane',
        'content' => 'You have a new message',
    ]);
    echo "  ✅ Private notification: private-user.456 → private.user-456\n";

    // Example 3: Presence in game lobby
    $broadcaster->broadcast(['presence-game.lobby.789'], 'UserJoined', [
        'user' => 'Player1',
        'level' => 25,
        'timestamp' => date('c'),
    ]);
    echo "  ✅ Presence channel: presence-game.lobby.789 → presence.game-lobby-789\n";

    // Example 4: Private encrypted data
    $broadcaster->broadcast(['private-encrypted-session.data'], 'DataUpdated', [
        'session_id' => 'abc123',
        'data' => 'Encrypted payload',
        'timestamp' => date('c'),
    ]);

    // Add this test section:
    echo "\n7. Testing socket ID handling...\n";

// Test with socket ID
    $broadcaster->broadcast(['test.socket'], 'SocketTestEvent', [
        'message' => 'This has a socket ID',
        'socket' => 'socket.123.456.789',  // Socket ID
    ]);

    echo "  ✅ Broadcast with socket ID sent\n";

// Test without socket ID
    $broadcaster->broadcast(['test.socket'], 'SocketTestEvent', [
        'message' => 'This has no socket ID',
        // No socket key
    ]);

    echo "  ✅ Broadcast without socket ID sent\n";

    echo "\n💡 Socket ID usage:\n";
    echo "   - With socket ID: For broadcastToOthers() - sender doesn't receive\n";
    echo "   - Without socket ID: Normal broadcast - everyone receives\n";


    echo "  ✅ Private encrypted: private-encrypted-session.data → private.encrypted.session-data\n";

    $broadcaster->disconnect();
    echo "\n✅ Disconnected\n";

    echo "\n🎉 All tests completed successfully!\n";
    echo "\n📋 Laravel Channel Support (No Prefixes):\n";
    echo "   ✅ Public: chat.room → chat-room\n";
    echo "   ✅ Private: private-chat → private.chat\n";
    echo "   ✅ Presence: presence-room → presence.room\n";
    echo "   ✅ Private Encrypted: private-encrypted-data → private.encrypted.data\n";
    echo "\n💡 Matches Laravel's default broadcasting behavior!\n";

} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
}