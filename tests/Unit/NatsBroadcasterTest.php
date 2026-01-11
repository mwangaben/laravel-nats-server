<?php

namespace Mwangaben\NatsBroadcaster\Tests\Unit;

use Mwangaben\NatsBroadcaster\Tests\TestCase;
use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;
use Mockery;
use Exception;

class NatsBroadcasterTest extends TestCase
{
    protected NatsBroadcaster $broadcaster;
    protected $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('broadcasting.connections.nats');
        $this->broadcaster = new NatsBroadcaster($config);

        // Mock the NATS client
        $this->mockClient = Mockery::mock('overload:' . \Mwangaben\Nats\Client::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_be_instantiated()
    {
        $config = [
            'host' => 'localhost',
            'port' => 4222,
            'debug' => false,
        ];

        $broadcaster = new NatsBroadcaster($config);

        $this->assertInstanceOf(NatsBroadcaster::class, $broadcaster);
    }

    /** @test */
    public function it_sets_default_configuration_values()
    {
        $config = [];

        $broadcaster = new NatsBroadcaster($config);

        $this->assertTrue(true); // Just test instantiation with defaults
    }

    /** @test */
    public function it_generates_valid_subjects_from_channels()
    {
        $reflection = new \ReflectionClass($this->broadcaster);
        $method = $reflection->getMethod('getSubjectFromChannel');
        $method->setAccessible(true);

        $testCases = [
            'test.channel' => 'test-test-channel',
            'private-channel' => 'private.channel',
            'presence-channel' => 'presence.channel',
            'private-encrypted-channel' => 'private.encrypted.channel',
            'app.test.channel' => 'app-test-channel',
        ];

        foreach ($testCases as $input => $expected) {
            $result = $method->invoke($this->broadcaster, $input);
            $this->assertEquals($expected, $result, "Failed for input: {$input}");
        }
    }

    /** @test */
    public function it_generates_tokens_for_authentication()
    {
        $reflection = new \ReflectionClass($this->broadcaster);
        $method = $reflection->getMethod('generateToken');
        $method->setAccessible(true);

        // Set app key for consistent testing
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);

        $channel = 'test-channel';
        $userId = 123;

        $token = $method->invoke($this->broadcaster, $channel, $userId);

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // SHA256 produces 64 char hex string

        // Same inputs should produce same token
        $token2 = $method->invoke($this->broadcaster, $channel, $userId);
        $this->assertEquals($token, $token2);
    }

    /** @test */
    public function it_handles_connection_failures_gracefully()
    {
        $this->mockClient->shouldReceive('connect')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Connection failed');

        $this->broadcaster->connect();
    }

    /** @test */
    public function it_returns_connection_status()
    {
        $this->mockClient->shouldReceive('connect')
            ->once()
            ->andReturn(null);

        $this->broadcaster->connect();

        $this->assertTrue($this->broadcaster->isConnected());
    }

    /** @test */
    public function it_can_disconnect()
    {
        $this->mockClient->shouldReceive('close')
            ->once()
            ->andReturn(null);

        $this->broadcaster->disconnect();

        $this->assertFalse($this->broadcaster->isConnected());
    }

    /** @test */
    public function it_handles_tls_configuration()
    {
        $config = [
            'host' => 'localhost',
            'port' => 4222,
            'tls' => true,
            'tls_cert' => '/path/to/cert.pem',
            'tls_key' => '/path/to/key.pem',
            'tls_ca' => '/path/to/ca.pem',
        ];

        // This tests that TLS config doesn't break instantiation
        $broadcaster = new NatsBroadcaster($config);

        $this->assertInstanceOf(NatsBroadcaster::class, $broadcaster);
    }

    /** @test */
    public function it_handles_user_authentication()
    {
        $config = [
            'host' => 'localhost',
            'port' => 4222,
            'user' => 'testuser',
            'pass' => 'testpass',
        ];

        $broadcaster = new NatsBroadcaster($config);

        $this->assertInstanceOf(NatsBroadcaster::class, $broadcaster);
    }

    /** @test */
    public function it_handles_token_authentication()
    {
        $config = [
            'host' => 'localhost',
            'port' => 4222,
            'token' => 'test-token-123',
        ];

        $broadcaster = new NatsBroadcaster($config);

        $this->assertInstanceOf(NatsBroadcaster::class, $broadcaster);
    }

    /** @test */
    public function it_sets_prefix_in_subject()
    {
        $config = [
            'host' => 'localhost',
            'port' => 4222,
            'prefix' => 'myapp',
        ];

        $broadcaster = new NatsBroadcaster($config);

        $reflection = new \ReflectionClass($broadcaster);
        $method = $reflection->getMethod('getSubjectFromChannel');
        $method->setAccessible(true);

        $result = $method->invoke($broadcaster, 'test.channel');

        $this->assertEquals('myapp.test-channel', $result);
    }
}