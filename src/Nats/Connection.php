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
    private $timeout = 2.0;
    private $verbose = false;
    private $pedantic = false;
    private $name = 'php-client';
    private $lang = 'php';
    private $version = '1.0.0';
    private $tls = false;
    private $tlsOptions = [];
    private $pingInterval = 120; // seconds
    private $maxPingsOut = 2;
    private $pingsOut = 0;
    private $lastPing = 0;
    private $lastActivity = 0;

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

    public function setTls(bool $tls, array $options = []): self
    {
        $this->tls = $tls;
        $this->tlsOptions = $options;
        return $this;
    }

    public function connect(): void
    {
        $address = "tcp://{$this->host}:{$this->port}";

        // Add SSL context if TLS is enabled
        $context = null;
        if ($this->tls) {
            $context = stream_context_create([
                'ssl' => array_merge([
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ], $this->tlsOptions)
            ]);
        }

        $this->socket = stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new NatsException("Connection failed to {$this->host}:{$this->port}: $errstr ($errno)");
        }

        // Set non-blocking mode and timeout
        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, 0, 100000); // 100ms timeout

        // Read server INFO line
        $infoLine = $this->readLine();
        if ($infoLine === false || $infoLine === '') {
            throw new NatsException("Failed to read server info");
        }

        // Parse server info (starts with "INFO ")
        if (strpos($infoLine, 'INFO ') === 0) {
            $infoJson = substr($infoLine, 5);
            $info = json_decode($infoJson, true);
            if ($info === null) {
                throw new NatsException("Invalid server info JSON");
            }
        }

        // Build CONNECT command
        $connectOptions = [
            'verbose' => $this->verbose,
            'pedantic' => $this->pedantic,
            'lang' => $this->lang,
            'version' => $this->version,
            'name' => $this->name,
        ];

        // Add authentication if provided
        if ($this->token) {
            $connectOptions['auth_token'] = $this->token;
        } elseif ($this->user && $this->password) {
            $connectOptions['user'] = $this->user;
            $connectOptions['pass'] = $this->password;
        }

        $connectCmd = "CONNECT " . json_encode($connectOptions) . "\r\n";
        $this->send($connectCmd);

        $this->connected = true;
        $this->lastActivity = microtime(true);
        $this->lastPing = $this->lastActivity;

        // Flush any pending data
        $this->flush();
    }

    public function flush(?float $timeout = null): void
    {
        $start = microtime(true);
        $timeout = $timeout ?? $this->timeout;

        while ($this->isConnected()) {
            $line = $this->readLine();
            if ($line === false || $line === '') {
                if ((microtime(true) - $start) >= $timeout) {
                    break;
                }
                usleep(1000); // 1ms
                continue;
            }

            $this->processLine($line);

            // Check for PONG or OK
            if (strpos($line, 'PONG') === 0 || strpos($line, '+OK') === 0) {
                break;
            }

            if ((microtime(true) - $start) >= $timeout) {
                break;
            }
        }
    }

    public function publish(string $subject, string $payload = '', ?string $reply = null): void
    {
        $msg = "PUB $subject";
        if ($reply) {
            $msg .= " $reply";
        }
        $msg .= " " . strlen($payload) . "\r\n" . $payload . "\r\n";
        $this->send($msg);
    }

    public function subscribe(string $subject, callable $callback, ?string $queue = null): string
    {
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

    public function request(string $subject, string $data, callable $callback, ?float $timeout = 1.0): string
    {
        $inbox = "_INBOX." . bin2hex(random_bytes(16));
        $sid = $this->subscribe($inbox, function($msg) use ($callback, $inbox) {
            $callback($msg);
            $this->unsubscribe($inbox);
        });

        $this->publish($subject, $data, $inbox);

        if ($timeout > 0) {
            $this->waitForResponse($inbox, $timeout);
        }

        return $inbox;
    }

    public function wait(?int $maxMessages = null, ?float $timeout = null): void
    {
        $start = microtime(true);
        $messagesProcessed = 0;
        $timeout = $timeout ?? $this->timeout;

        while (true) {
            // Check ping interval
            $now = microtime(true);
            if (($now - $this->lastPing) >= $this->pingInterval) {
                $this->ping();
                $this->lastPing = $now;
                $this->pingsOut++;

                if ($this->pingsOut > $this->maxPingsOut) {
                    $this->close();
                    throw new NatsException("No PONG response, connection lost");
                }
            }

            $line = $this->readLine();
            if ($line === false || $line === '') {
                if ((microtime(true) - $start) >= $timeout) {
                    break;
                }
                usleep(1000); // 1ms
                continue;
            }

            $this->lastActivity = microtime(true);
            $this->processLine($line);
            $messagesProcessed++;

            // Reset pingsOut on any activity
            $this->pingsOut = 0;

            if ($maxMessages !== null && $messagesProcessed >= $maxMessages) {
                break;
            }

            if ((microtime(true) - $start) >= $timeout) {
                break;
            }
        }
    }

    private function waitForResponse(string $inbox, float $timeout): void
    {
        $start = microtime(true);

        while (true) {
            $line = $this->readLine();
            if ($line === false || $line === '') {
                if ((microtime(true) - $start) >= $timeout) {
                    throw new NatsException("Request timeout for inbox: $inbox");
                }
                usleep(1000);
                continue;
            }

            $this->processLine($line);

            // Check if we've received the response
            if (!isset($this->subscriptions[$inbox])) {
                break;
            }

            if ((microtime(true) - $start) >= $timeout) {
                throw new NatsException("Request timeout for inbox: $inbox");
            }
        }
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

    public function getServerInfo(): ?array
    {
        // This would be populated from the initial INFO message
        return $this->serverInfo ?? null;
    }

    private function send(string $data): void
    {
        if (!$this->isConnected()) {
            throw new NatsException("Not connected to NATS server");
        }

        $written = fwrite($this->socket, $data);
        if ($written === false || $written !== strlen($data)) {
            $this->close();
            throw new NatsException("Failed to write to NATS server");
        }
    }

    private function readLine(): string
    {
        if (!$this->isConnected()) {
            return '';
        }

        $line = fgets($this->socket);
        if ($line === false) {
            // Check if it's just a timeout
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                return '';
            }
            $this->close();
            throw new NatsException("Failed to read from NATS server");
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $bytes): string
    {
        $data = '';
        $remaining = $bytes;

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false) {
                $this->close();
                throw new NatsException("Failed to read from NATS server");
            }
            if (strlen($chunk) === 0) {
                // No data available
                usleep(1000);
                continue;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    private function processLine(string $line): void
    {
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
            error_log("NATS server error: $line");
            // Parse error if needed
            if (preg_match('/-ERR\s+\'(.+)\'/', $line, $matches)) {
                throw new NatsException("NATS server error: " . $matches[1]);
            }
            return;
        }

        if (strpos($line, 'MSG') === 0) {
            $this->processMsg($line);
            return;
        }

        // Handle INFO messages (during reconnection)
        if (strpos($line, 'INFO ') === 0) {
            $infoJson = substr($line, 5);
            $this->serverInfo = json_decode($infoJson, true);
            return;
        }
    }

    private function processMsg(string $line): void
    {
        $parts = explode(' ', $line);
        if (count($parts) < 4) {
            error_log("Invalid MSG format: $line");
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

        // Read payload
        $payload = $this->readBytes($bytes);

        // Read trailing \r\n
        $this->readBytes(2);

        if (isset($this->subscriptions[$sid])) {
            $msg = new Message($subject, $payload, $replyTo, $sid);
            $callback = $this->subscriptions[$sid]['callback'];
            try {
                $callback($msg);
            } catch (\Exception $e) {
                error_log("Error in subscription callback: " . $e->getMessage());
            }
            $this->subscriptions[$sid]['received']++;
        }
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