<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Nats\Client;

$client = new Client();
$client->connect();

// Subscribe to a subject
$subscription = $client->subscribe('test.subject', function($msg) {
    echo "Received: " . $msg->getBody() . "\n";
});

echo "Listening for messages...\n";

// Wait for messages
$client->wait(30);

$client->close();