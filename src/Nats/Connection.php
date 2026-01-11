<?php

namespace Mwangaben\NatsBroadcaster\Nats;


class Connection
{
    private $socket;
    private $host;
    private $port;
    private $user;
    private $password;
    private $token;
    private $connected = false;
    private $sid = 0;
    private $subscriptions = [];
    private $pongs = [];
    private $timeout = 5.0;
    private $verbose = false;
    private $pedantic = false;
    private $name = 'php-nats-client';
    private $lang = 'php';
    private $version = '1.0.0';
    private $serverInfo = null;
    private $debug = false;
    private $pendingData = '';

    public function __construct(string $host = 'localhost', int $port = 4222)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function setAuth(string $user, string $password): self
    {
        $this->user = $user;
        $this->password = $password;
        return $this;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function setPedantic(bool $pedantic): self
    {
        $this->pedantic = $pedantic;
        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    private function log(string $message): void
    {
        if ($this->debug) {
            error_log("[NATS] $message");
        }
    }

    public function connect(): void
    {
        $this->log("Connecting to {$this->host}:{$this->port}");

        $address = "tcp://{$this->host}:{$this->port}";

        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout
        );

        if (!$this->socket) {
            throw new NatsException("Connection failed: $errstr ($errno)");
        }

        $this->log("Socket created successfully");

        // Use blocking mode for initial handshake
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, (int)ceil($this->timeout));

        // Read the INFO line - server sends this immediately
        $infoLine = fgets($this->socket);

        if ($infoLine === false) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                throw new NatsException("Timeout waiting for server INFO");
            }
            throw new NatsException("Failed to read server INFO");
        }

        $infoLine = rtrim($infoLine, "\r\n");
        $this->log("Received: $infoLine");

        // Parse INFO message
        if (strpos($infoLine, 'INFO ') === 0) {
            $infoJson = substr($infoLine, 5);
            $this->serverInfo = json_decode($infoJson, true);
            if ($this->serverInfo === null) {
                throw new NatsException("Invalid JSON in server INFO");
            }

            // Check if auth is required
            if (($this->serverInfo['auth_required'] ?? false) && !$this->user && !$this->token) {
                throw new NatsException("Server requires authentication");
            }

            $this->log("Server INFO parsed successfully");
        } else {
            throw new NatsException("Expected INFO message");
        }

        // Build CONNECT message
        $connectOpts = [
            'verbose' => $this->verbose,
            'pedantic' => $this->pedantic,
            'lang' => $this->lang,
            'version' => $this->version,
            'name' => $this->name,
        ];

        // Add authentication
        if ($this->token) {
            $connectOpts['auth_token'] = $this->token;
        } elseif ($this->user && $this->password) {
            $connectOpts['user'] = $this->user;
            $connectOpts['pass'] = $this->password;
        }

        $connectMsg = "CONNECT " . json_encode($connectOpts) . "\r\n";
        $this->log("Sending CONNECT");

        $written = fwrite($this->socket, $connectMsg);
        if ($written === false || $written !== strlen($connectMsg)) {
            throw new NatsException("Failed to send CONNECT");
        }

        // Switch to non-blocking for normal operations
        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, 0, 100000); // 100ms

        $this->connected = true;
        $this->log("Connection established");

        // Read any immediate response (PING or +OK)
        $this->processBuffer();
    }

    public function publish(string $subject, string $payload = '', ?string $reply = null): void
    {
        if (!$this->isConnected()) {
            throw new NatsException("Not connected");
        }

        $msg = "PUB $subject";
        if ($reply) {
            $msg .= " $reply";
        }
        $msg .= " " . strlen($payload) . "\r\n" . $payload . "\r\n";

        $this->send($msg);
    }

    public function subscribe(string $subject, callable $callback, ?string $queue = null): string
    {
        if (!$this->isConnected()) {
            throw new NatsException("Not connected");
        }

        $this->sid++;
        $sid = (string)$this->sid;

        $cmd = "SUB $subject";
        if ($queue) {
            $cmd .= " $queue";
        }
        $cmd .= " $sid\r\n";

        $this->send($cmd);

        $this->subscriptions[$sid] = [
            'subject' => $subject,
            'callback' => $callback,
            'queue' => $queue,
            'received' => 0
        ];

        return $sid;
    }

    public function unsubscribe(string $sid, ?int $max = null): void
    {
        if (!isset($this->subscriptions[$sid])) {
            return;
        }

        $cmd = "UNSUB $sid";
        if ($max !== null) {
            $cmd .= " $max";
        }
        $cmd .= "\r\n";

        $this->send($cmd);

        if ($max === null || $max <= 0) {
            unset($this->subscriptions[$sid]);
        }
    }

    public function request(string $subject, string $data, callable $callback, float $timeout = 2.0): string
    {
        $inbox = "_INBOX." . bin2hex(random_bytes(16));
        $sid = $this->subscribe($inbox, function($msg) use ($callback, $inbox) {
            $callback($msg);
            $this->unsubscribe($inbox);
        });

        $this->publish($subject, $data, $inbox);

        // Wait for response
        $start = microtime(true);
        while ((microtime(true) - $start) < $timeout) {
            $this->wait(0.1);

            // Check if we still have the subscription (means we haven't received response)
            if (!isset($this->subscriptions[$inbox])) {
                break;
            }
        }

        // Clean up if still subscribed
        if (isset($this->subscriptions[$inbox])) {
            $this->unsubscribe($inbox);
        }

        return $inbox;
    }

    public function wait(?float $timeout = null): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $start = microtime(true);
        $timeout = $timeout ?? $this->timeout;

        while (true) {
            $this->processBuffer();

            // Check timeout
            if ((microtime(true) - $start) >= $timeout) {
                break;
            }

            // Small sleep to prevent CPU spinning
            usleep(1000); // 1ms
        }
    }

    public function flush(?float $timeout = 1.0): void
    {
        $this->wait($timeout);
    }

    public function ping(): void
    {
        $this->send("PING\r\n");
    }

    public function pong(): void
    {
        $this->send("PONG\r\n");
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->connected = false;
        $this->subscriptions = [];
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket && !feof($this->socket);
    }

    private function send(string $data): void
    {
        if (!$this->isConnected()) {
            throw new NatsException("Not connected");
        }

        $total = strlen($data);
        $sent = 0;

        while ($sent < $total) {
            $chunk = fwrite($this->socket, substr($data, $sent));
            if ($chunk === false) {
                $this->close();
                throw new NatsException("Write failed");
            }
            $sent += $chunk;
        }
    }

    private function processBuffer(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        // Read available data
        $data = fread($this->socket, 8192);
        if ($data === false || $data === '') {
            return;
        }

        $this->pendingData .= $data;

        // Process complete lines
        while (($pos = strpos($this->pendingData, "\r\n")) !== false) {
            $line = substr($this->pendingData, 0, $pos);
            $this->pendingData = substr($this->pendingData, $pos + 2);

            if ($line !== '') {
                $this->processLine($line);
            }
        }
    }

    private function processLine(string $line): void
    {
        $this->log("Processing line: $line");

        if (strpos($line, 'PING') === 0) {
            $this->pong();
            return;
        }

        if (strpos($line, 'PONG') === 0) {
            $this->pongs[] = $line;
            return;
        }

        if (strpos($line, '+OK') === 0) {
            // Server acknowledged our command
            return;
        }

        if (strpos($line, '-ERR') === 0) {
            $this->log("Server error: $line");
            if (preg_match('/-ERR\s+\'(.+)\'/', $line, $matches)) {
                throw new NatsException("Server error: " . $matches[1]);
            }
            return;
        }

        if (strpos($line, 'MSG') === 0) {
            $this->processMsg($line);
            return;
        }
    }

    private function processMsg(string $line): void
    {
        $parts = explode(' ', $line);
        if (count($parts) < 4) {
            $this->log("Invalid MSG format: $line");
            return;
        }

        $subject = $parts[1];
        $sid = $parts[2];
        $replyTo = null;
        $bytes = 0;

        // Check if there's a reply-to
        if (count($parts) >= 5 && !is_numeric($parts[3])) {
            $replyTo = $parts[3];
            $bytes = (int)$parts[4];
        } else {
            $bytes = (int)$parts[3];
        }

        // Read payload (may need to wait for it)
        $payload = $this->readPayload($bytes);

        if (isset($this->subscriptions[$sid])) {
            $msg = new Message($subject, $payload, $replyTo, $sid);
            $callback = $this->subscriptions[$sid]['callback'];
            try {
                $callback($msg);
            } catch (\Exception $e) {
                $this->log("Callback error: " . $e->getMessage());
            }
            $this->subscriptions[$sid]['received']++;
        }
    }

    private function readPayload(int $bytes): string
    {
        $payload = '';

        while (strlen($payload) < $bytes) {
            // Check if we have enough data in buffer
            $needed = $bytes - strlen($payload);
            if (strlen($this->pendingData) >= $needed + 2) { // +2 for \r\n
                $payload .= substr($this->pendingData, 0, $needed);
                $this->pendingData = substr($this->pendingData, $needed + 2); // Skip payload and \r\n
                break;
            }

            // Read more data
            $data = fread($this->socket, 8192);
            if ($data === false || $data === '') {
                // Try non-blocking read
                usleep(1000);
                continue;
            }

            $this->pendingData .= $data;
        }

        return $payload;
    }

    public function __destruct()
    {
        $this->close();
    }
}

class Message
{
    public $subject;
    public $data;
    public $reply;
    public $sid;

    public function __construct(string $subject, string $data, ?string $reply, string $sid)
    {
        $this->subject = $subject;
        $this->data = $data;
        $this->reply = $reply;
        $this->sid = $sid;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getReplyTo(): ?string
    {
        return $this->reply;
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function respond(string $data, Connection $connection): void
    {
        if ($this->reply) {
            $connection->publish($this->reply, $data);
        }
    }
}