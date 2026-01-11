<?php

namespace Nats\Tests;

use Nats\Client;
use Nats\ConnectionOptions;
use Nats\Message;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @requires extension sockets
 */
class RealConnectionTest extends TestCase
{
    private static bool $natsAvailable = false;
    private Client $client;

    public static function setUpBeforeClass(): void
    {
        // Check if NATS server is available
        $socket = @fsockopen(NATS_TEST_HOST, NATS_TEST_PORT, $errno, $errstr, 2);
        if ($socket) {
            self::$natsAvailable = true;
            fclose($socket);
        }
    }

    protected function setUp(): void
    {
        if (!self::$natsAvailable) {
            $this->markTestSkipped('NATS server not available at ' . NATS_TEST_HOST . ':' . NATS_TEST_PORT);
        }

        $options = ConnectionOptions::create()
            ->setHost(NATS_TEST_HOST)
            ->setPort(NATS_TEST_PORT)
            ->setUser(NATS_TEST_USER)
            ->setPassword(NATS_TEST_PASSWORD)
            ->setTimeout(2.0);

        $this->client = new Client($options);
    }

    protected function tearDown(): void
    {
        if (isset($this->client)) {
            $this->client->close();
        }
    }

    public function testConnectToRealServer()
    {
        $this->client->connect();
        $this->assertTrue($this->client->isConnected());

        // Verify we can ping the server
        $this->client->ping();
        $this->client->wait(0.5);

        $this->client->close();
        $this->assertFalse($this->client->isConnected());
    }

    public function testPublishToRealServer()
    {
        $this->client->connect();

        // Publish a message
        $this->client->publish('test.subject', 'Hello from PHPUnit!');

        // Flush to ensure message is sent
        $this->client->flush(1.0);

        $this->assertTrue($this->client->isConnected());
    }

    public function testSubscribeAndReceive()
    {
        $this->client->connect();

        $receivedMessage = null;
        $subscription = $this->client->subscribe('test.integration', function(Message $msg) use (&$receivedMessage) {
            $receivedMessage = $msg;
        });

        // Publish a message to ourselves
        $this->client->publish('test.integration', 'Test message content');

        // Wait for message (with timeout)
        $start = microtime(true);
        while ($receivedMessage === null && (microtime(true) - $start) < 2.0) {
            $this->client->wait(0.1);
        }

        $this->assertNotNull($receivedMessage);
        $this->assertInstanceOf(Message::class, $receivedMessage);
        $this->assertEquals('test.integration', $receivedMessage->getSubject());
        $this->assertEquals('Test message content', $receivedMessage->getBody());

        // Clean up
        $this->client->unsubscribe($subscription->getSid());
    }

    public function testRequestReplyPattern()
    {
        $this->client->connect();

        // Set up a responder
        $this->client->subscribe('math.double', function(Message $msg) {
            $number = (int)$msg->getBody();
            $result = $number * 2;
            $msg->respond((string)$result, $this->client->getConnection());
        });

        // Make a request
        $response = $this->client->requestSync('math.double', '5', 2.0);

        $this->assertNotNull($response);
        $this->assertEquals('10', $response->getBody());
    }

    public function testQueueGroups()
    {
        $this->client->connect();

        $client1Received = 0;
        $client2Received = 0;

        // Create two subscribers in the same queue group
        $sub1 = $this->client->subscribe('queue.test', function() use (&$client1Received) {
            $client1Received++;
        }, 'test-queue');

        $sub2 = $this->client->subscribe('queue.test', function() use (&$client2Received) {
            $client2Received++;
        }, 'test-queue');

        // Publish 10 messages
        for ($i = 0; $i < 10; $i++) {
            $this->client->publish('queue.test', "Message $i");
        }

        // Wait a bit for messages to be distributed
        $this->client->wait(1.0);

        // In a queue group, messages should be distributed between subscribers
        $totalReceived = $client1Received + $client2Received;
        $this->assertEquals(10, $totalReceived);

        // Each should have received at least some messages
        $this->assertGreaterThan(0, $client1Received);
        $this->assertGreaterThan(0, $client2Received);
    }

    public function testMultipleMessages()
    {
        $this->client->connect();

        $receivedMessages = [];
        $maxMessages = 5;

        $subscription = $this->client->subscribe('multi.test', function(Message $msg) use (&$receivedMessages) {
            $receivedMessages[] = $msg->getBody();
        }, null, $maxMessages);

        // Publish more messages than we'll receive
        for ($i = 0; $i < 10; $i++) {
            $this->client->publish('multi.test', "Message $i");
        }

        // Wait for messages
        $this->client->wait(2.0);

        // Should only receive maxMessages
        $this->assertCount($maxMessages, $receivedMessages);
    }
}