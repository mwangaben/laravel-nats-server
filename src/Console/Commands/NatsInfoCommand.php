<?php

namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;
use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;

class NatsInfoCommand extends Command
{
    protected $signature = 'nats:info';
    protected $description = 'Display NATS connection information and test connectivity';

    public function handle(NatsBroadcaster $broadcaster): void
    {
        $this->info('🔌 NATS Connection Information');
        $this->line('===============================');

        try {
            $config = config('broadcasting.connections.nats');

            // Display configuration
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Host', $config['host'] ?? 'localhost'],
                    ['Port', $config['port'] ?? 4222],
                    ['User', $config['user'] ?? 'Not set'],
                    ['Password', $config['pass'] ? '••••••' : 'Not set'],
                    ['Token', $config['token'] ? '••••••' : 'Not set'],
                    ['Prefix', $config['prefix'] ?? 'Not set'],
                    ['Timeout', $config['timeout'] ?? 5],
                    ['Reconnect', $config['reconnect'] ? 'Yes' : 'No'],
                    ['Debug', $config['debug'] ? 'Yes' : 'No'],
                    ['TLS', $config['tls'] ? 'Yes' : 'No'],
                ]
            );

            // Test connection
            $this->newLine();
            $this->info('Testing connection...');

            $broadcaster->connect();

            if ($broadcaster->isConnected()) {
                $this->info('✅ Connected to NATS server successfully!');

                // Test publish
                try {
                    $broadcaster->getClient()->publish('test.connection', json_encode([
                        'test' => true,
                        'timestamp' => now()->toISOString(),
                    ]));
                    $this->info('✅ Test message published successfully');
                } catch (\Exception $e) {
                    $this->warn('⚠️ Could not publish test message: ' . $e->getMessage());
                }

            } else {
                $this->error('❌ Failed to connect to NATS server');
            }

        } catch (\Exception $e) {
            $this->error('❌ Connection failed: ' . $e->getMessage());

            if ($config['debug'] ?? false) {
                $this->line('');
                $this->warn('Debug Information:');
                $this->line($e->getTraceAsString());
            }

            $this->line('');
            $this->warn('Troubleshooting steps:');
            $this->line('1. Make sure NATS server is running: docker run -d -p 4222:4222 nats:latest');
            $this->line('2. Check if port 4222 is accessible');
            $this->line('3. Verify NATS_HOST in .env file');
            $this->line('4. Check TLS configuration if using secure connection');
        }
    }
}