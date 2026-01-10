<?php

namespace Mwangaben\NatsBroadcaster\Helpers;

use Basis\Nats\Client;
use Basis\Nats\Consumer\Consumer;
use Basis\Nats\Stream\Stream;

class NatsHelper
{
    protected Client $client;
    protected ?Stream $stream = null;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function withJetStream(string $streamName = 'broadcast'): self
    {
        $this->client->getApi()->getStream($streamName);
        $this->stream = $this->client->getApi()->getStream($streamName);

        if (!$this->stream->exists()) {
            $this->stream->create();
        }

        return $this;
    }

    public function createConsumer(string $consumerName = 'broadcast-consumer'): Consumer
    {
        if (!$this->stream) {
            throw new \RuntimeException('Stream not initialized. Call withJetStream() first.');
        }

        $consumer = $this->stream->getConsumer($consumerName);

        if (!$consumer->exists()) {
            $consumer->create();
        }

        return $consumer;
    }

    public function publishToStream(string $subject, array $data): void
    {
        if (!$this->stream) {
            throw new \RuntimeException('Stream not initialized. Call withJetStream() first.');
        }

        $this->stream->publish($subject, json_encode($data));
    }

    public function getConsumerMessages(string $consumerName, int $batch = 10): array
    {
        $consumer = $this->createConsumer($consumerName);
        $messages = [];

        $consumer->handle(function ($message) use (&$messages, $batch) {
            $messages[] = json_decode($message->getBody(), true);
            return count($messages) < $batch;
        });

        return $messages;
    }
}