<?php

namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;

class NatsDebugCommand extends Command
{
    protected $signature = 'nats:debug';
    protected $description = 'Debug NATS configuration';

    public function handle()
    {
        $this->info('🔍 NATS Configuration Debug');
        $this->line('===========================');

        // Check env
        $this->info('Environment Variables:');
        $this->table(
            ['Variable', 'Value'],
            [
                ['NATS_TLS', env('NATS_TLS')],
                ['NATS_TLS_ENABLED', env('NATS_TLS_ENABLED')],
                ['NATS_HOST', env('NATS_HOST')],
                ['NATS_PORT', env('NATS_PORT')],
            ]
        );

        // Check configs
        $this->info('"\nConfiguration Files:');

        $broadcastingConfig = config('broadcasting.connections.nats');
        $natsConfig = config('nats-broadcasting.connections.nats');

        $this->info('Broadcasting config (broadcasting.connections.nats):');
        dump($broadcastingConfig);

        $this->info('NATS config (nats-broadcasting.connections.nats):');
        dump($natsConfig);

        // Check which one is being used
        $this->info('"\nActive Configuration:');
        if (!empty($broadcastingConfig)) {
            $this->line('Using broadcasting.php config');
            $this->line('TLS enabled: '.($broadcastingConfig['tls']['enabled'] ?? 'false'));
        } elseif (!empty($natsConfig)) {
            $this->line('Using nats-broadcasting.php config');
            $this->line('TLS enabled: '.($natsConfig['tls']['enabled'] ?? 'false'));
        }
    }
}