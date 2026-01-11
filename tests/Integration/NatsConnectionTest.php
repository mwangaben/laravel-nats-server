<?php

namespace Mwangaben\NatsBroadcaster\Tests\Integration;

use Mwangaben\NatsBroadcaster\Tests\TestCase;

class NatsConnectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Start NATS server once for all tests
        if (!isNatsServerRunning()) {
            self::$natsProcess = startNatsServer();
            sleep(3); // Give server time to start
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Stop NATS server after all tests
        if (self::$natsProcess) {
            stopNatsServer(self::$natsProcess);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!isNatsServerRunning()) {
            $this->markTestSkipped('NATS server is not running. Start it with: nats server run');
        }
    }
    /** @test */
    public function it_can_connect_with_local_credentials()
    {
        $broadcaster = app('nats.broadcaster');

        $broadcaster->connect();

        $this->assertTrue($broadcaster->isConnected());
        $this->assertEquals('localhost', $broadcaster->getClient()->getOptions()->getHost());
        $this->assertEquals(53969, $broadcaster->getClient()->getOptions()->getPort());
    }

    /** @test */
    public function it_can_authenticate_with_user_password()
    {
        $config = [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'local',
            'pass' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
            'debug' => true,
        ];

        $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster($config);
        $broadcaster->connect();

        $this->assertTrue($broadcaster->isConnected());
    }

    /** @test */
    public function it_fails_with_wrong_credentials()
    {
        $config = [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'wronguser',
            'pass' => 'wrongpass',
        ];

        $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster($config);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/(auth|credentials|failed)/i');

        $broadcaster->connect();
    }


    /** @test */
    public function it_can_use_system_credentials()
    {
        $config = [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'system',
            'pass' => 'LsVYSAeCr7HveUAigwGdinxyZpIxQk5g',
            'debug' => true,
        ];

        $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster($config);
        $broadcaster->connect();

        $this->assertTrue($broadcaster->isConnected());
    }
    /** @test */
    public function it_can_use_service_credentials()
    {
        $config = [
            'host' => 'localhost',
            'port' => 53969,
            'user' => 'service',
            'pass' => 'aqV24E1iBAffEAIHmbYJ4HeynQ520ndA',
            'debug' => true,
        ];

        $broadcaster = new \Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster($config);
        $broadcaster->connect();

        $this->assertTrue($broadcaster->isConnected());
    }

    //TODo



    /** @test */
    public function it_can_connect_to_nats_server()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $broadcaster->connect();

        $this->assertTrue($broadcaster->isConnected());
    }

    /** @test */
    public function it_can_send_and_receive_messages()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $receivedMessages = [];

        // Subscribe to test channel
        $broadcaster->subscribe('integration.test', function ($message) use (&$receivedMessages) {
            $receivedMessages[] = $message;
        });

        // Send multiple messages
        for ($i = 1; $i <= 3; $i++) {
            $broadcaster->broadcast(['integration.test'], 'TestMessage', [
                'index' => $i,
                'message' => "Message {$i}",
                'timestamp' => now()->toISOString(),
            ]);
        }

        // Wait for messages
        sleep(2);

        $this->assertCount(3, $receivedMessages);

        foreach ($receivedMessages as $index => $message) {
            $this->assertEquals('TestMessage', $message['event']);
            $this->assertEquals($index + 1, $message['data']['index']);
        }
    }

    /** @test */
    public function it_supports_queue_groups()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $messages1 = [];
        $messages2 = [];

        // Create two subscribers in the same queue group
        // With NATS queue groups, only one subscriber should receive each message
        $broadcaster->subscribe('queue.test', function ($message) use (&$messages1) {
            $messages1[] = $message;
        });

        $broadcaster->subscribe('queue.test', function ($message) use (&$messages2) {
            $messages2[] = $message;
        });

        // Send 5 messages
        for ($i = 1; $i <= 5; $i++) {
            $broadcaster->broadcast(['queue.test'], 'QueueMessage', [
                'index' => $i,
                'timestamp' => now()->toISOString(),
            ]);
        }

        sleep(2);

        // Total messages received should be 5, split between the two subscribers
        $totalMessages = count($messages1) + count($messages2);
        $this->assertEquals(5, $totalMessages);

        // Each subscriber should have received at least one message
        $this->assertGreaterThan(0, count($messages1));
        $this->assertGreaterThan(0, count($messages2));
    }

    /** @test */
    public function it_preserves_message_structure()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $receivedMessage = null;

        $broadcaster->subscribe('structure.test', function ($message) use (&$receivedMessage) {
            $receivedMessage = $message;
        });

        $testData = [
            'nested' => [
                'array' => [1, 2, 3],
                'object' => (object) ['key' => 'value'],
            ],
            'string' => 'Hello, World!',
            'number' => 42,
            'boolean' => true,
            'null' => null,
        ];

        $broadcaster->broadcast(['structure.test'], 'StructuredEvent', $testData);

        sleep(1);

        $this->assertNotNull($receivedMessage);
        $this->assertEquals('StructuredEvent', $receivedMessage['event']);
        $this->assertEquals($testData, $receivedMessage['data']);
    }

    /** @test */
    public function it_handles_large_messages()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $received = false;

        $broadcaster->subscribe('large.test', function ($message) use (&$received) {
            $received = true;
            $this->assertIsString($message['data']['largeString']);
            $this->assertEquals(10000, strlen($message['data']['largeString']));
        });

        // Create a large string (10KB)
        $largeString = str_repeat('0123456789', 1000);

        $broadcaster->broadcast(['large.test'], 'LargeMessage', [
            'largeString' => $largeString,
            'timestamp' => now()->toISOString(),
        ]);

        sleep(1);

        $this->assertTrue($received);
    }

    /** @test */
    public function it_supports_multiple_subjects_simultaneously()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = app('nats.broadcaster');

        $messagesSubject1 = [];
        $messagesSubject2 = [];

        // Subscribe to two different subjects
        $broadcaster->subscribe('subject.one', function ($message) use (&$messagesSubject1) {
            $messagesSubject1[] = $message;
        });

        $broadcaster->subscribe('subject.two', function ($message) use (&$messagesSubject2) {
            $messagesSubject2[] = $message;
        });

        // Send messages to both subjects
        for ($i = 1; $i <= 3; $i++) {
            $broadcaster->broadcast(['subject.one'], 'SubjectOneEvent', [
                'index' => $i,
                'subject' => 'one',
            ]);

            $broadcaster->broadcast(['subject.two'], 'SubjectTwoEvent', [
                'index' => $i,
                'subject' => 'two',
            ]);
        }

        sleep(2);

        $this->assertCount(3, $messagesSubject1);
        $this->assertCount(3, $messagesSubject2);

        foreach ($messagesSubject1 as $message) {
            $this->assertEquals('SubjectOneEvent', $message['event']);
        }

        foreach ($messagesSubject2 as $message) {
            $this->assertEquals('SubjectTwoEvent', $message['event']);
        }
    }
}