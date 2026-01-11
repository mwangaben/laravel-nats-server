<?php

namespace Nats;

use Nats\Encoders\EncoderInterface;
use Nats\Exceptions\ConnectionException;
use Nats\Exceptions\NatsException;
use Nats\Exceptions\ProtocolException;
use Nats\Exceptions\TimeoutException;

class Connection
{
    private $socket;
    private ConnectionOptions $options;
    private bool $connected = false;
    private int $sid = 0;
    private array $subscriptions = [];
    private array $pongs = [];
    private ?array $serverInfo = null;
    private string $pendingData = '';
    private int $pingCount = 0;
    private int $pongCount = 0;
    private int $outMsgs = 0;
    private int $inMsgs = 0;
    private int $reconnectAttempts = 0;
    private bool $closing = false;
    private EncoderInterface $encoder;

    public function __construct(?ConnectionOptions $options = null)
    {
        $this->options = $options ?? new ConnectionOptions();
        $encoderClass = $this->options->getEncoderClass();
        $this->encoder = new $encoderClass(); // Instantiate the encoder class
    }

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->closing = false;
        $this->connectToServer();
    }

    private function connectToServer(): void
    {
        $address = $this->buildAddress();
        $context = $this->createStreamContext();

        $this->log("Connecting to {$this->options->getHost()}:{$this->options->getPort()}");

        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->options->getTimeout(),
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new ConnectionException("Connection failed: $errstr ($errno)");
        }

        $this->socket = $socket;
        $this->log("Socket created successfully");

        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, (int) ceil($this->options->getTimeout()));

        $this->readServerInfo();
        $this->sendConnect();

        stream_set_blocking($this->socket, false);
        stream_set_timeout($this->socket, 0, 100000);

        $this->connected = true;
        $this->reconnectAttempts = 0;
        $this->log("Connection established successfully");

        $this->processBuffer();
    }

    private function buildAddress(): string
    {
        $host = $this->options->getHost();
        $port = $this->options->getPort();

        if ($this->options->getTlsCertFile() || $this->options->getTlsCaFile()) {
            return "tls://{$host}:{$port}";
        }

        return "tcp://{$host}:{$port}";
    }

    private function createStreamContext()
    {
        $options = [];

        if ($this->options->getTlsCertFile() || $this->options->getTlsCaFile()) {
            $options['ssl'] = [
                'verify_peer'      => $this->options->getTlsVerify(),
                'verify_peer_name' => $this->options->getTlsVerify(),
            ];

            if ($this->options->getTlsCertFile()) {
                $options['ssl']['local_cert'] = $this->options->getTlsCertFile();
            }

            if ($this->options->getTlsKeyFile()) {
                $options['ssl']['local_pk'] = $this->options->getTlsKeyFile();
            }

            if ($this->options->getTlsCaFile()) {
                $options['ssl']['cafile'] = $this->options->getTlsCaFile();
            }

            if (!$this->options->getTlsVerify()) {
                $options['ssl']['allow_self_signed'] = true;
            }
        }

        return stream_context_create($options);
    }

    private function readServerInfo(): void
    {
        $infoLine = fgets($this->socket);

        if ($infoLine === false) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                throw new TimeoutException("Timeout waiting for server INFO");
            }
            throw new ConnectionException("Failed to read server INFO");
        }

        $infoLine = rtrim($infoLine, "\r\n");
        $this->log("Received: $infoLine");

        if (strpos($infoLine, 'INFO ') !== 0) {
            throw new ProtocolException("Expected INFO message from server");
        }

        $infoJson = substr($infoLine, 5);
        $this->serverInfo = json_decode($infoJson, true);

        if ($this->serverInfo === null) {
            throw new ProtocolException("Invalid JSON in server INFO");
        }

        $this->log("Server INFO parsed successfully");
        $this->validateServerAuth();
    }

    private function validateServerAuth(): void
    {
        if (($this->serverInfo['auth_required'] ?? false)) {
            if (!$this->options->getToken() && !$this->options->getUser()) {
                throw new ConnectionException("Server requires authentication but no credentials provided");
            }
        }
    }

    private function sendConnect(): void
    {
        $connectData = $this->buildConnectData();
        $connectMsg = "CONNECT " . json_encode($connectData) . "\r\n";

        $this->log("Sending CONNECT");
        $written = fwrite($this->socket, $connectMsg);

        if ($written === false || $written !== strlen($connectMsg)) {
            throw new ConnectionException("Failed to send CONNECT message");
        }
    }

    private function buildConnectData(): array
    {
        $data = [
            'verbose'  => $this->options->getVerbose(),
            'pedantic' => $this->options->getPedantic(),
            'lang'     => $this->options->getLang(),
            'version'  => $this->options->getVersion(),
            'name'     => $this->options->getName(),
        ];

        if ($this->options->getToken()) {
            $data['auth_token'] = $this->options->getToken();
        } elseif ($this->options->getUser() && $this->options->getPassword()) {
            $data['user'] = $this->options->getUser();
            $data['pass'] = $this->options->getPassword();
        }

        if ($this->options->getClusterId()) {
            $data['cluster_id'] = $this->options->getClusterId();
        }

        if ($this->options->getClientId()) {
            $data['client_id'] = $this->options->getClientId();
        }

        return $data;
    }

    public function publish(string $subject, $data, ?string $replyTo = null): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException("Not connected");
        }

        $payload = $this->encoder->encode($data);
        $msg = "PUB $subject";

        if ($replyTo) {
            $msg .= " $replyTo";
        }

        $msg .= " " . strlen($payload) . "\r\n" . $payload . "\r\n";
        $this->send($msg);
        $this->outMsgs++;
    }

    public function subscribe(
        string $subject,
        callable $callback,
        ?string $queueGroup = null,
        ?int $maxMessages = null
    ): Subscription {
        if (!$this->isConnected()) {
            throw new ConnectionException("Not connected");
        }

        $this->sid++;
        $sid = (string) $this->sid;

        $cmd = "SUB $subject";
        if ($queueGroup) {
            $cmd .= " $queueGroup";
        }
        $cmd .= " $sid";

        if ($maxMessages) {
            $cmd .= " $maxMessages";
        }

        $cmd .= "\r\n";

        $this->send($cmd);

        $subscription = new Subscription(
            $sid,
            $subject,
            \Closure::fromCallable($callback),
            $queueGroup,
            $maxMessages
        );

        $this->subscriptions[$sid] = $subscription;

        return $subscription;
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

    public function request(
        string $subject,
        $data,
        callable $callback,
        float $timeout = 2.0
    ): string {
        $inbox = $this->options->getInboxPrefix() . "." . bin2hex(random_bytes(16));

        $sid = $this->subscribe($inbox, function ($msg) use ($callback, $inbox) {
            $callback($msg);
            $this->unsubscribe($inbox);
        });

        $this->publish($subject, $data, $inbox);

        $start = microtime(true);
        while ((microtime(true) - $start) < $timeout) {
            $this->wait(0.1);

            if (!isset($this->subscriptions[$inbox])) {
                break;
            }
        }

        if (isset($this->subscriptions[$inbox])) {
            $this->unsubscribe($inbox);
        }

        return $inbox;
    }

    public function requestSync(string $subject, $data, float $timeout = 2.0): ?Message
    {
        $response = null;
        $inbox = $this->options->getInboxPrefix() . "." . bin2hex(random_bytes(16));

        $sid = $this->subscribe($inbox, function ($msg) use (&$response) {
            $response = $msg;
        }, null, 1);

        $this->publish($subject, $data, $inbox);

        $start = microtime(true);
        while ((microtime(true) - $start) < $timeout) {
            $this->wait(0.1);

            if ($response !== null) {
                break;
            }

            if (!isset($this->subscriptions[$inbox])) {
                break;
            }
        }

        $this->unsubscribe($inbox);

        return $response;
    }

    public function wait(?float $timeout = null): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $start = microtime(true);
        $timeout ??= $this->options->getTimeout();

        while (true) {
            $this->processBuffer();

            if ((microtime(true) - $start) >= $timeout) {
                break;
            }

            usleep(1000);
        }
    }

    public function flush(?float $timeout = 1.0): void
    {
        $this->ping();
        $this->waitForPong($timeout);
    }

    private function waitForPong(float $timeout): void
    {
        $start = microtime(true);
        $initialPongCount = $this->pongCount;

        while ((microtime(true) - $start) < $timeout) {
            $this->processBuffer();

            if ($this->pongCount > $initialPongCount) {
                return;
            }

            usleep(1000);
        }

        throw new TimeoutException("Timeout waiting for PONG");
    }

    public function ping(): void
    {
        $this->send("PING\r\n");
        $this->pingCount++;
    }

    public function pong(): void
    {
        $this->send("PONG\r\n");
    }

    public function close(): void
    {
        $this->closing = true;

        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }

        $this->connected = false;
        $this->subscriptions = [];
        $this->pendingData = '';
        $this->log("Connection closed");
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket && !feof($this->socket);
    }

    public function getStats(): array
    {
        return [
            'in_msgs'            => $this->inMsgs,
            'out_msgs'           => $this->outMsgs,
            'ping_count'         => $this->pingCount,
            'pong_count'         => $this->pongCount,
            'subscriptions'      => count($this->subscriptions),
            'reconnect_attempts' => $this->reconnectAttempts,
        ];
    }

    public function getServerInfo(): ?array
    {
        return $this->serverInfo;
    }

    public function getOptions(): ConnectionOptions
    {
        return $this->options;
    }

    public function setEncoder(EncoderInterface $encoder): void
    {
        $this->encoder = $encoder;
    }

    private function send(string $data): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException("Not connected");
        }

        $total = strlen($data);
        $sent = 0;

        while ($sent < $total) {
            $chunk = fwrite($this->socket, substr($data, $sent));
            if ($chunk === false) {
                $this->handleWriteError();
            }
            $sent += $chunk;
        }
    }

    private function handleWriteError(): void
    {
        $this->close();

        if ($this->options->getReconnect() && !$this->closing) {
            $this->attemptReconnect();
        } else {
            throw new ConnectionException("Write failed and reconnection disabled");
        }
    }

    private function attemptReconnect(): void
    {
        $this->reconnectAttempts++;

        if ($this->reconnectAttempts > $this->options->getMaxReconnectAttempts()) {
            throw new ConnectionException("Maximum reconnect attempts exceeded");
        }

        $this->log("Attempting reconnect ({$this->reconnectAttempts}/{$this->options->getMaxReconnectAttempts()})");

        sleep($this->options->getReconnectWait());

        try {
            $this->connectToServer();
            $this->resubscribe();
        } catch (NatsException $e) {
            $this->attemptReconnect();
        }
    }

    private function resubscribe(): void
    {
        foreach ($this->subscriptions as $subscription) {
            $cmd = "SUB {$subscription->getSubject()}";

            if ($queueGroup = $subscription->getQueueGroup()) {
                $cmd .= " $queueGroup";
            }

            $cmd .= " {$subscription->getSid()}";

            if ($max = $subscription->getMaxMessages()) {
                $cmd .= " $max";
            }

            $cmd .= "\r\n";

            $this->send($cmd);
        }
    }

    private function processBuffer(): void
    {
        if (!$this->isConnected()) {
            return;
        }

        $data = fread($this->socket, 8192);

        if ($data === false) {
            return;
        }

        if ($data === '') {
            if (feof($this->socket)) {
                $this->handleDisconnect();
            }

            return;
        }

        $this->pendingData .= $data;

        while (($pos = strpos($this->pendingData, "\r\n")) !== false) {
            $line = substr($this->pendingData, 0, $pos);
            $this->pendingData = substr($this->pendingData, $pos + 2);

            if ($line !== '') {
                $this->processLine($line);
            }
        }
    }

    private function handleDisconnect(): void
    {
        $this->connected = false;

        if ($this->options->getReconnect() && !$this->closing) {
            $this->attemptReconnect();
        }
    }

    private function processLine(string $line): void
    {
        $this->log("Processing: $line");

        if (strpos($line, 'PING') === 0) {
            $this->pong();

            return;
        }

        if (strpos($line, 'PONG') === 0) {
            $this->pongCount++;

            return;
        }

        if (strpos($line, '+OK') === 0) {
            return;
        }

        if (strpos($line, '-ERR') === 0) {
            $this->handleServerError($line);

            return;
        }

        if (strpos($line, 'MSG') === 0) {
            $this->processMsg($line);

            return;
        }

        $this->log("Unhandled line: $line");
    }

    private function handleServerError(string $line): void
    {
        $this->log("Server error: $line");

        if (preg_match('/-ERR\s+\'(.+)\'/', $line, $matches)) {
            throw new ProtocolException("Server error: " . $matches[1]);
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

        if (count($parts) >= 5 && !is_numeric($parts[3])) {
            $replyTo = $parts[3];
            $bytes = (int) $parts[4];
        } else {
            $bytes = (int) $parts[3];
        }

        $payload = $this->readPayload($bytes);

        if (isset($this->subscriptions[$sid])) {
            $body = $this->encoder->decode($payload);
            $msg = new Message($subject, $body, $replyTo, $sid, $this->encoder);

            $subscription = $this->subscriptions[$sid];
            $callback = $subscription->getCallback();

            try {
                $callback($msg);
            } catch (\Exception $e) {
                $this->log("Callback error: " . $e->getMessage());
            }

            $subscription->incrementReceived();
            $this->inMsgs++;

            if ($subscription->shouldAutoUnsubscribe()) {
                $this->unsubscribe($sid);
            }
        }
    }

    private function readPayload(int $bytes): string
    {
        $payload = '';

        while (strlen($payload) < $bytes) {
            $needed = $bytes - strlen($payload);

            if (strlen($this->pendingData) >= $needed + 2) {
                $payload .= substr($this->pendingData, 0, $needed);
                $this->pendingData = substr($this->pendingData, $needed + 2);
                break;
            }

            $data = fread($this->socket, 8192);
            if ($data === false || $data === '') {
                usleep(1000);
                continue;
            }

            $this->pendingData .= $data;
        }

        return $payload;
    }

    private function log(string $message): void
    {
        if ($this->options->getDebug()) {
            error_log("[NATS] $message");
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
