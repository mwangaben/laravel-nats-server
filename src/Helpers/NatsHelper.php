<?php

namespace Mwangaben\NatsBroadcaster\Helpers;

use Nats\Client;
use Exception;

class NatsHelper
{
    protected Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function withJetStream(string $streamName = 'broadcast'): self
    {
        // Note: mwangaben/nats-php-client may not have direct JetStream support
        // You might need to implement this differently or add JetStream support
        // For now, we'll use regular NATS features

        return $this;
    }

    public function createConsumer(string $consumerName = 'broadcast-consumer')
    {
        // Placeholder for consumer creation
        // You'll need to implement JetStream consumer logic if needed
        return null;
    }

    public function publishToStream(string $subject, array $data): void
    {
        // For now, use regular publish
        $this->client->publish($subject, json_encode($data));
    }

    public function getConsumerMessages(string $consumerName, int $batch = 10): array
    {
        // Placeholder - implement if using JetStream
        return [];
    }
}