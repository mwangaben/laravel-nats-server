<?php

namespace Nats\Tests;

use Nats\Client;
use Nats\Connection;
use Nats\ConnectionOptions;
use Nats\Encoders\JSONEncoder;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class IntegrationTest extends TestCase
{
    private static bool $natsAvailable = false;

    public static function setUpBeforeClass(): void
    {
        $socket = @fsockopen(NATS_TEST_HOST, NATS_TEST_PORT, $errno, $errstr, 2);
        if ($socket) {
            self::$natsAvailable = true;
            fclose($socket);
        }
    }

    public function testClientWithJSONEncoder()
    {
        if (!self::$natsAvailable) {
            $this->markTestSkipped('NATS server not available');
        }

        $options = ConnectionOptions::create()
            ->setHost(NATS_TEST_HOST)
            ->setPort(NATS_TEST_PORT)
            ->setUser(NATS_TEST_USER)
            ->setPassword(NATS_TEST_PASSWORD)
            ->setEncoderClass(JSONEncoder::class)
            ->setTimeout(2.0);

        $client = new Client($options);
        $client->connect();

        $this->assertTrue($client->isConnected());

        // Test JSON encoding/decoding
        $data = ['action' => 'test', 'value' => 123];
        $client->publish('json.test', $data);

        $receivedData = null;
        $client->subscribe('json.test', function($msg) use (&$receivedData) {
            $receivedData = $msg->getBody();
        });

        // Wait a bit
        $client->wait(0.5);

        $client->close();
    }

    public function testConfigurationWithRealServer()
    {
        if (!self::$natsAvailable) {
            $this->markTestSkipped('NATS server not available');
        }

        $config = new \Nats\Configuration([
            'host' => NATS_TEST_HOST,
            'port' => NATS_TEST_PORT,
            'user' => NATS_TEST_USER,
            'password' => NATS_TEST_PASSWORD,
            'verbose' => false,
            'timeout' => 2.0
        ]);

        $client = $config->createClient();
        $client->connect();

        $this->assertTrue($client->isConnected());

        // Test basic publish
        $client->publish('config.test', 'Test message');
        $client->flush(1.0);

        $client->close();
    }

    public function testClientFactoryWithRealServer()
    {
        if (!self::$natsAvailable) {
            $this->markTestSkipped('NATS server not available');
        }

        // Test with URL
        $url = sprintf('nats://%s:%s@%s:%d?verbose=false&timeout=2',
            NATS_TEST_USER,
            NATS_TEST_PASSWORD,
            NATS_TEST_HOST,
            NATS_TEST_PORT
        );

        $client = \Nats\ClientFactory::createFromUrl($url);
        $client->connect();

        $this->assertTrue($client->isConnected());
        $client->close();

        // Test with array
        $client2 = \Nats\ClientFactory::createFromArray([
            'host' => NATS_TEST_HOST,
            'port' => NATS_TEST_PORT,
            'user' => NATS_TEST_USER,
            'password' => NATS_TEST_PASSWORD,
            'name' => 'phpunit-test-client'
        ]);

        $client2->connect();
        $this->assertTrue($client2->isConnected());
        $client2->close();
    }

    /**
     * @requires extension sockets
     */
    public function testConnectionPoolWithRealServer()
    {
        if (!self::$natsAvailable) {
            $this->markTestSkipped('NATS server not available');
        }

        $options = ConnectionOptions::create()
            ->setHost(NATS_TEST_HOST)
            ->setPort(NATS_TEST_PORT)
            ->setUser(NATS_TEST_USER)
            ->setPassword(NATS_TEST_PASSWORD)
            ->setTimeout(2.0);

        $pool = new \Nats\ConnectionPool($options);
        $connection = $pool->getConnection();

        $this->assertInstanceOf(Connection::class, $connection);

        // Test adding another connection
        $pool->addConnection('secondary', $options);
        $stats = $pool->getStats();

        $this->assertCount(2, $stats);
        $this->assertArrayHasKey('default', $stats);
        $this->assertArrayHasKey('secondary', $stats);
    }
}