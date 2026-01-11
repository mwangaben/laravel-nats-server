<?php

namespace Mwangaben\NatsBroadcaster\Nats;

class PatchedClient
{
    private $socket;
    private $host;
    private $port;
    private $user;
    private $pass;
    private $token;
    private $connected = false;
    private $debug = false;
    private $timeout = 5;
    private $subscriptions = [];
    private $shouldStop = false;

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 4222;
        $this->user = $config['user'] ?? null;
        $this->pass = $config['pass'] ?? null;
        $this->token = $config['token'] ?? null;
        $this->debug = $config['debug'] ?? false;
        $this->timeout = $config['timeout'] ?? 5;
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $address = "tcp://{$this->host}:{$this->port}";

        if ($this->debug) {
            error_log("[NATS] Connecting to {$address}");
        }

        $context = stream_context_create();
        $this->socket = stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new \RuntimeException("Connection failed: {$errstr} ({$errno})");
        }

        // Set non-blocking for reading
        stream_set_blocking($this->socket, false);

        // Read INFO message
        $info = $this->readLine();
        if (strpos($info, 'INFO ') !== 0) {
            throw new \RuntimeException("Invalid server response: {$info}");
        }

        // Parse INFO to check auth requirements
        $infoJson = substr($info, 5);
        $infoData = json_decode($infoJson, true);

        // Prepare CONNECT message
        $connectData = [
            'verbose' => $this->debug,
            'pedantic' => false,
            'lang' => 'php',
            'version' => '1.0.0',
        ];

        if ($this->token) {
            $connectData['auth_token'] = $this->token;
        } elseif ($this->user && $this->pass) {
            $connectData['user'] = $this->user;
            $connectData['pass'] = $this->pass;
        }

        // Send CONNECT
        $this->write("CONNECT " . json_encode($connectData) . "\r\n");

        // Read response (should be +OK or -ERR)
        $response = $this->readLine();

        if (strpos($response, '-ERR') === 0) {
            throw new \RuntimeException("Authentication failed: {$response}");
        }

        $this->connected = true;

        if ($this->debug) {
            error_log("[NATS] Connected successfully");
        }
    }

    public function publish(string $subject, string $payload, string $replyTo = null): void
    {
        if (!$this->connected) {
            $this->connect();
        }

        $cmd = "PUB {$subject}";
        if ($replyTo) {
            $cmd .= " {$replyTo}";
        }
        $cmd .= " " . strlen($payload) . "\r\n{$payload}\r\n";

        $this->write($cmd);
    }

    public function subscribe(string $subject, callable $callback, string $queue = null): string
    {
        if (!$this->connected) {
            $this->connect();
        }

        $sid = uniqid('sid_', true);
        $cmd = "SUB {$subject}";
        if ($queue) {
            $cmd .= " {$queue}";
        }
        $cmd .= " {$sid}\r\n";

        $this->write($cmd);

        // Store subscription
        $this->subscriptions[$sid] = [
            'subject' => $subject,
            'callback' => $callback,
            'queue' => $queue,
        ];

        // Start listening in background if not already
        $this->startListener();

        return $sid;
    }

    public function unsubscribe(string $sid): void
    {
        if (isset($this->subscriptions[$sid])) {
            $this->write("UNSUB {$sid}\r\n");
            unset($this->subscriptions[$sid]);
        }
    }

    private function startListener(): void
    {
        static $listenerStarted = false;

        if ($listenerStarted || empty($this->subscriptions)) {
            return;
        }

        $listenerStarted = true;

        // Simple message listener
        while ($this->connected && !$this->shouldStop && !empty($this->subscriptions)) {
            $this->processMessages();
            usleep(100000); // 100ms
        }

        $listenerStarted = false;
    }

    private function processMessages(): void
    {
        $line = $this->readLine();

        if (!$line) {
            return;
        }

        if (strpos($line, 'MSG') === 0) {
            // Parse MSG line: MSG <subject> <sid> [reply-to] <#bytes>
            $parts = explode(' ', $line);

            if (count($parts) >= 4) {
                $subject = $parts[1];
                $sid = $parts[2];
                $bytesIndex = count($parts) - 1;
                $bytes = (int)$parts[$bytesIndex];

                // Read payload
                $payload = $this->readBytes($bytes + 2); // +2 for \r\n
                $payload = substr($payload, 0, -2); // Remove \r\n

                // Find subscription by SID
                if (isset($this->subscriptions[$sid])) {
                    $callback = $this->subscriptions[$sid]['callback'];
                    try {
                        $callback([
                            'subject' => $subject,
                            'sid' => $sid,
                            'payload' => $payload,
                            'body' => $payload, // Alias for compatibility
                        ]);
                    } catch (\Exception $e) {
                        if ($this->debug) {
                            error_log("[NATS] Callback error: " . $e->getMessage());
                        }
                    }
                }
            }
        } elseif (strpos($line, 'PING') === 0) {
            $this->write("PONG\r\n");
        } elseif (strpos($line, '-ERR') === 0) {
            if ($this->debug) {
                error_log("[NATS] Server error: {$line}");
            }
        }
    }

    private function write(string $data): void
    {
        if ($this->debug) {
            error_log("[NATS] >>> " . trim($data));
        }

        fwrite($this->socket, $data);
    }

    private function readLine(): string
    {
        $line = '';
        $start = microtime(true);

        while (true) {
            $char = fgetc($this->socket);
            if ($char === false) {
                if ((microtime(true) - $start) > 0.1) { // 100ms timeout for reading
                    break;
                }
                usleep(1000);
                continue;
            }

            $line .= $char;
            if (substr($line, -2) === "\r\n") {
                break;
            }
        }

        if ($this->debug && $line) {
            error_log("[NATS] <<< " . trim($line));
        }

        return $line;
    }

    private function readBytes(int $bytes): string
    {
        $data = '';
        $start = microtime(true);
        $remaining = $bytes;

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false) {
                if ((microtime(true) - $start) > 0.1) { // 100ms timeout
                    break;
                }
                usleep(1000);
                continue;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    public function close(): void
    {
        $this->shouldStop = true;
        $this->subscriptions = [];

        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function __destruct()
    {
        $this->close();
    }
}