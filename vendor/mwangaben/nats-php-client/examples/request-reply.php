<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nats\Client;

// Server
$server = new Client();
$server->connect();

$server->subscribe('math.add', function($msg) {
    global $server;
    $data = json_decode($msg->getBody(), true);
    $result = $data['a'] + $data['b'];

    if ($msg->getReplyTo()) {
        $server->publish($msg->getReplyTo(), json_encode(['sum' => $result]));
    }
});

// Client
$client = new Client();
$client->connect();

$response = $client->requestSync('math.add', json_encode(['a' => 5, 'b' => 3]), 2.0);

if ($response) {
    $data = json_decode($response->getBody(), true);
    echo "Result: " . $data['sum'] . "\n";
} else {
    echo "Request timeout!\n";
}

$server->close();
$client->close();