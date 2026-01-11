<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nats\Client;

$client = new Client();
$client->connect();

// Publish a simple message
$client->publish('test.subject', 'Hello NATS!');

// Publish JSON data
$data = ['action' => 'user.created', 'userId' => 123];
$client->publish('events.user', json_encode($data));

echo "Messages published!\n";

$client->close();