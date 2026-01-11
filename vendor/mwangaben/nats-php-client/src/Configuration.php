<?php

namespace Nats;

class Configuration
{
    private array $config;

    public function __construct(array $config = [])
    {
        $defaults = [
            'host' => 'localhost',
            'port' => 4222,
            'timeout' => 5.0,
            'reconnect' => true,
            'max_reconnect_attempts' => 60,
            'reconnect_wait' => 2,
            'name' => 'php-nats-client',
            'verbose' => false,
            'pedantic' => false,
            'debug' => false,
            'encoder' => 'raw',
        ];

        $this->config = array_merge($defaults, $config);
    }

    public function toConnectionOptions(): ConnectionOptions
    {
        $options = ConnectionOptions::create()
            ->setHost($this->config['host'])
            ->setPort($this->config['port'])
            ->setTimeout($this->config['timeout'])
            ->setReconnect($this->config['reconnect'])
            ->setMaxReconnectAttempts($this->config['max_reconnect_attempts'])
            ->setReconnectWait($this->config['reconnect_wait'])
            ->setName($this->config['name'])
            ->setVerbose($this->config['verbose'])
            ->setPedantic($this->config['pedantic'])
            ->setDebug($this->config['debug']);

        // Set encoder
        $encoder = $this->config['encoder'];
        if ($encoder === 'json') {
            $options->setEncoderClass(Encoders\JSONEncoder::class);
        }

        // Set authentication if provided
        if (isset($this->config['user']) && isset($this->config['password'])) {
            $options->setUser($this->config['user'])
                ->setPassword($this->config['password']);
        }

        if (isset($this->config['token'])) {
            $options->setToken($this->config['token']);
        }

        // Set TLS options if provided
        if (isset($this->config['tls_cert_file'])) {
            $options->setTlsCertFile($this->config['tls_cert_file']);
        }

        if (isset($this->config['tls_key_file'])) {
            $options->setTlsKeyFile($this->config['tls_key_file']);
        }

        if (isset($this->config['tls_ca_file'])) {
            $options->setTlsCaFile($this->config['tls_ca_file']);
        }

        if (isset($this->config['tls_verify'])) {
            $options->setTlsVerify($this->config['tls_verify']);
        }

        return $options;
    }

    public function createClient(): Client
    {
        return new Client($this->toConnectionOptions());
    }
}
