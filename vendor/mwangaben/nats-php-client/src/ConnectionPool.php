<?php

namespace Nats;

class ConnectionPool
{
    private array $connections = [];
    private ConnectionOptions $defaultOptions;

    public function __construct(?ConnectionOptions $defaultOptions = null)
    {
        $this->defaultOptions = $defaultOptions ?? ConnectionOptions::create();
    }

    public function getConnection(string $name = 'default'): Connection
    {
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = new Connection($this->defaultOptions);
        }

        return $this->connections[$name];
    }

    public function addConnection(string $name, ConnectionOptions $options): void
    {
        $this->connections[$name] = new Connection($options);
    }

    public function connectAll(): void
    {
        foreach ($this->connections as $connection) {
            if (!$connection->isConnected()) {
                $connection->connect();
            }
        }
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
    }

    public function publish(string $subject, $data, ?string $replyTo = null, string $connectionName = 'default'): void
    {
        $connection = $this->getConnection($connectionName);
        $connection->publish($subject, $data, $replyTo);
    }

    public function getStats(): array
    {
        $stats = [];
        foreach ($this->connections as $name => $connection) {
            $stats[$name] = [
                'connected' => $connection->isConnected(),
                'stats' => $connection->getStats(),
            ];
        }
        return $stats;
    }
}
