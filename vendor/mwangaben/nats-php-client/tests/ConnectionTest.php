<?php

namespace Nats\Tests;

use Nats\Client;
use Nats\Connection;
use Nats\ConnectionOptions;
use Nats\Message;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    public function testClientCreation()
    {
        $client = new Client();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConnectionOptions()
    {
        $options = ConnectionOptions::create()
            ->setHost('nats.example.com')
            ->setPort(4222)
            ->setUser('test')
            ->setPassword('pass')
            ->setVerbose(true);

        $this->assertEquals('nats.example.com', $options->getHost());
        $this->assertEquals(4222, $options->getPort());
        $this->assertEquals('test', $options->getUser());
        $this->assertEquals('pass', $options->getPassword());
        $this->assertTrue($options->getVerbose());
    }

    public function testMessageCreation()
    {
        $message = new Message('test.subject', 'Hello World', 'reply.123', 'sid123');
        $this->assertEquals('test.subject', $message->getSubject());
        $this->assertEquals('Hello World', $message->getBody());
        $this->assertEquals('reply.123', $message->getReplyTo());
        $this->assertEquals('sid123', $message->getSid());
    }

    public function testStaticClientCreation()
    {
        $client = Client::createDefault();
        $this->assertInstanceOf(Client::class, $client);
    }
}