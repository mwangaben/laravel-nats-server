<?php
// simple_test.php

// Simple NATS client without composer
class SimpleNatsClient
{
    private $socket;

    public function connect(string $host, int $port, string $user = null, string $pass = null): void
    {
        $address = "tcp://$host:$port";
        echo "Connecting to $address...\n";

        $this->socket = stream_socket_client($address, $errno, $errstr, 5);

        if (!$this->socket) {
            throw new Exception("Connection failed: $errstr ($errno)");
        }

        echo "Connected. Waiting for server INFO...\n";

        // Set timeout
        stream_set_timeout($this->socket, 5);

        // Read the INFO line
        $info = fgets($this->socket);
        if ($info === false) {
            throw new Exception("Failed to read server greeting");
        }

        echo "Server says: " . trim($info) . "\n";

        // Send CONNECT
        $connect = [
            'verbose' => false,
            'pedantic' => false,
            'lang' => 'php',
            'version' => '1.0.0'
        ];

        if ($user && $pass) {
            $connect['user'] = $user;
            $connect['pass'] = $pass;
        }

        $connectMsg = "CONNECT " . json_encode($connect) . "\r\n";
        echo "Sending: $connectMsg";
        fwrite($this->socket, $connectMsg);

        // Read response
        $response = fgets($this->socket);
        echo "Response: " . ($response ? trim($response) : 'none') . "\n";

        // Send PING
        fwrite($this->socket, "PING\r\n");
        echo "Sent PING\n";

        // Read PONG
        $pong = fgets($this->socket);
        echo "PONG: " . ($pong ? trim($pong) : 'none') . "\n";
    }

    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}

// Test it
try {
    $client = new SimpleNatsClient();
    $client->connect('localhost', 53969, 'local', '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH');
    $client->close();
    echo "✓ Success!\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}