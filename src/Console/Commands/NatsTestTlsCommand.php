<?php

namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;

class NatsTestTlsCommand extends Command
{
    protected $signature = 'nats:test-tls';
    protected $description = 'Test TLS connection to NATS server';

    public function handle()
    {
        $this->info('🔐 Testing TLS Connection to NATS');
        $this->line('==================================');

        $host = env('NATS_HOST', 'localhost');
        $port = env('NATS_PORT', 4222);

        $this->info("Testing connection to {$host}:{$port}");

// Test 1: Direct TCP connection
        $this->testDirectTcp($host, $port);

// Test 2: Direct TLS connection
        $this->testDirectTls($host, $port);

// Test 3: Stream context test
        $this->testWithStreamContext($host, $port);
    }

    private function testDirectTcp($host, $port)
    {
        $this->info("\nTest 1: Direct TCP connection");

        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket) {
            fwrite($socket, "INFO\r\n");
            $response = fread($socket, 4096);
            fclose($socket);

            $this->info("✅ TCP connection successful");
            $this->line("Response: ".substr($response, 0, 100));

// Parse INFO
            if (strpos($response, 'INFO ') === 0) {
                $infoJson = substr($response, 5);
                $info = json_decode($infoJson, true);
                $this->line("TLS required: ".($info['tls_required'] ?? 'false'));
                $this->line("TLS available: ".($info['tls_available'] ?? 'false'));
            }
        } else {
            $this->error("❌ TCP connection failed: {$errstr}");
        }
    }

    private function testDirectTls($host, $port)
    {
        $this->info("\nTest 2: Direct TLS connection");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ]
        ]);

        $socket = @stream_socket_client(
            "tls://{$host}:{$port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket) {
            $this->info("✅ Direct TLS connection successful!");

// Get TLS info
            $crypto = stream_socket_get_name($socket, true);
            $this->line("Crypto protocol: {$crypto}");

// Test NATS protocol
            fwrite($socket, "INFO\r\n");
            $response = fread($socket, 4096);
            $this->line("NATS INFO: ".substr($response, 0, 100));

            fclose($socket);
        } else {
            $this->error("❌ Direct TLS failed: {$errstr}");
        }
    }

    private function testWithStreamContext($host, $port)
    {
        $this->info("\nTest 3: TLS with specific configuration");

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
                'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                'SNI_enabled'       => true,
                'peer_name'         => $host,
            ]
        ]);

        $socket = @stream_socket_client(
            "tls://{$host}:{$port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket) {
            $this->info("✅ TLS with context successful!");
            fclose($socket);
        } else {
            $this->error("❌ Failed: {$errstr}");
        }
    }
}