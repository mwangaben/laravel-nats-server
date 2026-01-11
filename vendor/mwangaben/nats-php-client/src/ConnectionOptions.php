<?php

namespace Nats;

use Nats\Encoders\RawEncoder;

class ConnectionOptions
{
    private string $host = 'localhost';
    private int $port = 4222;
    private ?string $user = null;
    private ?string $password = null;
    private ?string $token = null;
    private ?string $tlsCertFile = null;
    private ?string $tlsKeyFile = null;
    private ?string $tlsCaFile = null;
    private bool $tlsVerify = true;
    private bool $verbose = false;
    private bool $pedantic = false;
    private bool $reconnect = true;
    private int $maxReconnectAttempts = 60;
    private int $reconnectWait = 2; // seconds
    private float $timeout = 5.0;
    private string $name = 'php-nats-client';
    private string $lang = 'php';
    private string $version = '2.0.0';
    private bool $debug = false;
    private string $encoderClass = RawEncoder::class; // Fixed: Use class constant
    private ?string $clusterId = null;
    private ?string $clientId = null;
    private ?string $inboxPrefix = '_INBOX';

    public static function create(): self
    {
        return new self();
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;
        return $this;
    }

    public function getUser(): ?string
    {
        return $this->user;
    }

    public function setUser(?string $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): self
    {
        $this->token = $token;
        return $this;
    }

    public function getTlsCertFile(): ?string
    {
        return $this->tlsCertFile;
    }

    public function setTlsCertFile(?string $tlsCertFile): self
    {
        $this->tlsCertFile = $tlsCertFile;
        return $this;
    }

    public function getTlsKeyFile(): ?string
    {
        return $this->tlsKeyFile;
    }

    public function setTlsKeyFile(?string $tlsKeyFile): self
    {
        $this->tlsKeyFile = $tlsKeyFile;
        return $this;
    }

    public function getTlsCaFile(): ?string
    {
        return $this->tlsCaFile;
    }

    public function setTlsCaFile(?string $tlsCaFile): self
    {
        $this->tlsCaFile = $tlsCaFile;
        return $this;
    }

    public function getTlsVerify(): bool
    {
        return $this->tlsVerify;
    }

    public function setTlsVerify(bool $tlsVerify): self
    {
        $this->tlsVerify = $tlsVerify;
        return $this;
    }

    public function getVerbose(): bool
    {
        return $this->verbose;
    }

    public function setVerbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    public function getPedantic(): bool
    {
        return $this->pedantic;
    }

    public function setPedantic(bool $pedantic): self
    {
        $this->pedantic = $pedantic;
        return $this;
    }

    public function getReconnect(): bool
    {
        return $this->reconnect;
    }

    public function setReconnect(bool $reconnect): self
    {
        $this->reconnect = $reconnect;
        return $this;
    }

    public function getMaxReconnectAttempts(): int
    {
        return $this->maxReconnectAttempts;
    }

    public function setMaxReconnectAttempts(int $maxReconnectAttempts): self
    {
        $this->maxReconnectAttempts = $maxReconnectAttempts;
        return $this;
    }

    public function getReconnectWait(): int
    {
        return $this->reconnectWait;
    }

    public function setReconnectWait(int $reconnectWait): self
    {
        $this->reconnectWait = $reconnectWait;
        return $this;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    public function setTimeout(float $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    public function getEncoderClass(): string
    {
        return $this->encoderClass;
    }

    public function setEncoderClass(string $encoderClass): self
    {
        $this->encoderClass = $encoderClass;
        return $this;
    }

    public function getClusterId(): ?string
    {
        return $this->clusterId;
    }

    public function setClusterId(?string $clusterId): self
    {
        $this->clusterId = $clusterId;
        return $this;
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): self
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getInboxPrefix(): string
    {
        return $this->inboxPrefix;
    }

    public function setInboxPrefix(string $inboxPrefix): self
    {
        $this->inboxPrefix = $inboxPrefix;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password,
            'token' => $this->token,
            'verbose' => $this->verbose,
            'pedantic' => $this->pedantic,
            'reconnect' => $this->reconnect,
            'maxReconnectAttempts' => $this->maxReconnectAttempts,
            'reconnectWait' => $this->reconnectWait,
            'timeout' => $this->timeout,
            'name' => $this->name,
            'debug' => $this->debug,
            'clusterId' => $this->clusterId,
            'clientId' => $this->clientId,
        ];
    }
}
