<?php

namespace Mwangaben\NatsBroadcaster\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Mwangaben\NatsBroadcaster\NatsServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            NatsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Set up NATS configuration for local development server
        $app['config']->set('broadcasting.connections.nats', [
            'driver'    => 'nats',
            'host'      => env('NATS_HOST', 'localhost'),
            'port'      => env('NATS_PORT', 53969), // Dynamic port from your server
            'user'      => env('NATS_USER', 'local'), // Default user
            'pass'      => env('NATS_PASS', '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH'), // User password
            'token'     => env('NATS_TOKEN'), // Optional token
            'timeout'   => 2,
            'reconnect' => true,
            'prefix'    => 'test',
            'debug'     => true,
            'verbose'   => true, // Enable verbose for debugging
            'pedantic'  => false,
            'tls'       => false,
        ]);
    }

    protected function startNatsServer(): bool
    {
        // Check if NATS server is running on dynamic port
        $host = config('broadcasting.connections.nats.host', 'localhost');
        $port = config('broadcasting.connections.nats.port', 53969);

        $socket = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($socket) {
            fclose($socket);

            return true;
        }

        // Try to start the server if not running
        return $this->startLocalNatsServer();
    }

    protected function startLocalNatsServer(): bool
    {
        // Check if NATS CLI is available
        if (!`which nats`) {
            return false;
        }

        // Try to start NATS server
        $command = 'nats server run &';
        exec($command, $output, $returnCode);

        // Wait for server to start
        sleep(2);

        // Check if it's running
        $host = config('broadcasting.connections.nats.host', 'localhost');
        $port = config('broadcasting.connections.nats.port', 53969);
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($socket) {
            fclose($socket);

            return true;
        }

        return false;
    }

//todo


    protected function skipIfNatsNotRunning(): void
    {
        if (!$this->startNatsServer()) {
            $this->markTestSkipped('NATS server is not running. Start it with: docker run -d -p 4222:4222 nats:latest');
        }
    }
}