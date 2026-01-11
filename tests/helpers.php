<?php

use Symfony\Component\Process\Process;

if (!function_exists('startNatsServer')) {
    /**
     * Start a local NATS server for testing
     */
    function startNatsServer(): ?Process
    {
        if (!`which nats`) {
            echo "NATS CLI not found. Please install it: brew tap nats-io/nats-tools && brew install nats-io/nats-tools/nats\n";
            return null;
        }

        // Start NATS server in background
        $process = new Process(['nats', 'server', 'run']);
        $process->start();

        // Wait for server to be ready
        sleep(3);

        return $process;
    }
}

if (!function_exists('stopNatsServer')) {
    /**
     * Stop the NATS server
     */
    function stopNatsServer(Process $process): void
    {
        $process->stop();
    }
}

if (!function_exists('isNatsServerRunning')) {
    /**
     * Check if NATS server is running
     */
    function isNatsServerRunning(string $host = 'localhost', int $port = 53969): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($socket) {
            fclose($socket);
            return true;
        }

        return false;
    }
}

if (!function_exists('getNatsConnectionInfo')) {
    /**
     * Get NATS connection info from running server
     */
    function getNatsConnectionInfo(): array
    {
        $output = [];
        exec('nats context list --json', $output, $returnCode);

        if ($returnCode === 0) {
            $contexts = json_decode(implode("\n", $output), true);
            if (isset($contexts['nats_development'])) {
                return [
                    'url' => 'nats://localhost:53969',
                    'user' => 'local',
                    'password' => '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH',
                ];
            }
        }

        // Fallback to default values
        return [
            'url' => env('NATS_HOST', 'localhost') . ':' . env('NATS_PORT', 4222),
            'user' => env('NATS_USER', 'local'),
            'password' => env('NATS_PASS', '4elDZZTb7ofiN83BKjXOvhZvOuhUouhH'),
        ];
    }
}