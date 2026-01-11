<?php

namespace Nats;

use Nats\Exceptions\ConnectionException;

class Client
{
    private Connection $connection;
    private ConnectionOptions $options;

    public function __construct(?ConnectionOptions $options = null)
    {
        $this->options = $options ?? ConnectionOptions::create();
        $this->connection = new Connection($this->options);
    }

    public function connect(): void
    {
        $this->connection->connect();
    }

    /**
     * @throws ConnectionException
     */
    public function publish(string $subject, $data, ?string $replyTo = null): void
    {
        $this->connection->publish($subject, $data, $replyTo);
    }

    public function subscribe(
        string $subject,
        callable $callback,
        ?string $queueGroup = null,
        ?int $maxMessages = null
    ): Subscription {
        return $this->connection->subscribe($subject, $callback, $queueGroup, $maxMessages);
    }

    public function request(
        string $subject,
        $data,
        callable $callback,
        float $timeout = 2.0
    ): string {
        return $this->connection->request($subject, $data, $callback, $timeout);
    }

    public function requestSync(string $subject, $data, float $timeout = 2.0): ?Message
    {
        return $this->connection->requestSync($subject, $data, $timeout);
    }

    public function wait(?float $timeout = null): void
    {
        $this->connection->wait($timeout);
    }

    public function flush(?float $timeout = 1.0): void
    {
        $this->connection->flush($timeout);
    }

    public function ping(): void
    {
        $this->connection->ping();
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public static function createDefault(): self
    {
        return new self();
    }

    public static function createWithOptions(array $options): self
    {
        $connectionOptions = ConnectionOptions::create();

        foreach ($options as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($connectionOptions, $method)) {
                $connectionOptions->$method($value);
            }
        }

        return new self($connectionOptions);
    }
}
