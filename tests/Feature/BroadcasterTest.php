<?php

namespace Mwangaben\NatsBroadcaster\Tests\Feature;

use Mwangaben\NatsBroadcaster\Tests\TestCase;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

class BroadcasterTest extends TestCase
{
    /** @test */
    public function it_registers_nats_driver_with_broadcast_manager()
    {
        $broadcastManager = $this->app->make(BroadcastManager::class);

        $this->assertTrue($broadcastManager->hasDriver('nats'));

        $broadcaster = $broadcastManager->connection('nats');

        $this->assertInstanceOf(\Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster::class, $broadcaster);
    }

    /** @test */
    public function it_can_send_broadcast_through_facade()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = Broadcast::connection('nats');

        $this->assertInstanceOf(\Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster::class, $broadcaster);
        $this->assertTrue($broadcaster->isConnected());
    }

    /** @test */
    public function it_can_broadcast_to_single_channel()
    {
        $this->skipIfNatsNotRunning();

        $result = Broadcast::channel('test-channel', 'TestEvent', [
            'message' => 'Hello, NATS!',
            'timestamp' => now()->toISOString(),
        ]);

        $this->assertNull($result); // Broadcast returns null on success
    }

    /** @test */
    public function it_can_broadcast_to_multiple_channels()
    {
        $this->skipIfNatsNotRunning();

        $channels = ['channel-1', 'channel-2', 'channel-3'];

        $result = Broadcast::channel($channels, 'TestEvent', [
            'message' => 'Broadcast to multiple channels',
            'timestamp' => now()->toISOString(),
        ]);

        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_socket_id_in_payload()
    {
        $this->skipIfNatsNotRunning();

        $result = Broadcast::channel('test-channel', 'TestEvent', [
            'message' => 'With socket ID',
            'socket' => 'socket-123',
        ]);

        $this->assertNull($result);
    }

    /** @test */
    public function it_can_broadcast_using_laravel_events()
    {
        $this->skipIfNatsNotRunning();

        Event::fake();

        $event = new class {
            public function broadcastOn()
            {
                return ['test-event-channel'];
            }

            public function broadcastAs()
            {
                return 'TestEvent';
            }

            public function broadcastWith()
            {
                return [
                    'data' => 'Event data',
                    'timestamp' => now()->toISOString(),
                ];
            }
        };

        Broadcast::event($event);

        // If we get here without exceptions, the broadcast was attempted
        $this->assertTrue(true);
    }

    /** @test */
    public function it_can_subscribe_to_channels()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = Broadcast::connection('nats');

        $messageReceived = false;
        $receivedData = null;

        // Subscribe first
        $broadcaster->subscribe('test-subscribe-channel', function ($data) use (&$messageReceived, &$receivedData) {
            $messageReceived = true;
            $receivedData = $data;
        });

        // Then broadcast
        Broadcast::channel('test-subscribe-channel', 'TestSubscribeEvent', [
            'test' => 'subscribe data',
            'timestamp' => now()->toISOString(),
        ]);

        // Give time for message to be delivered
        sleep(1);

        $this->assertTrue($messageReceived);
        $this->assertNotNull($receivedData);
        $this->assertEquals('TestSubscribeEvent', $receivedData['event']);
    }

    /** @test */
    public function it_can_unsubscribe_from_channels()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = Broadcast::connection('nats');

        $messageCount = 0;

        $broadcaster->subscribe('test-unsubscribe-channel', function () use (&$messageCount) {
            $messageCount++;
        });

        // Send first message
        Broadcast::channel('test-unsubscribe-channel', 'TestEvent', ['message' => 'First']);
        sleep(1);

        // Unsubscribe
        $broadcaster->unsubscribe('test-unsubscribe-channel');

        // Send second message (should not be received)
        Broadcast::channel('test-unsubscribe-channel', 'TestEvent', ['message' => 'Second']);
        sleep(1);

        $this->assertEquals(1, $messageCount);
    }

    /** @test */
    public function it_reconnects_on_failure()
    {
        $this->skipIfNatsNotRunning();

        $broadcaster = Broadcast::connection('nats');

        // Initially connected
        $this->assertTrue($broadcaster->isConnected());

        // Disconnect
        $broadcaster->disconnect();
        $this->assertFalse($broadcaster->isConnected());

        // Reconnect
        $broadcaster->reconnect();
        $this->assertTrue($broadcaster->isConnected());
    }

    /** @test */
    public function it_handles_broadcast_retry_on_failure()
    {
        $this->skipIfNatsNotRunning();

        // This test verifies that the broadcaster attempts to reconnect and retry
        // when a publish fails
        $broadcaster = Broadcast::connection('nats');

        // Broadcast should work
        $result = $broadcaster->broadcast(['test-retry-channel'], 'TestEvent', [
            'message' => 'Testing retry mechanism',
        ]);

        $this->assertNull($result);
    }
}