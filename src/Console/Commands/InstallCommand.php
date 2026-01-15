<?php
//src/Console/Commands/InstallCommand.php


namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'nats:install
                            {--force : Force overwrite existing files}
                            {--config-only : Only publish configuration}';

    protected $description = 'Install NATS broadcaster configuration';

    public function handle(): void
    {
        $this->info('Installing NATS Broadcaster...');

        // Publish config
        $this->call('vendor:publish', [
            '--tag' => 'nats-config',
            '--force' => $this->option('force')
        ]);

        if (!$this->option('config-only')) {
            // Update .env file
            $this->updateEnvFile();

            // Update broadcasting config
            $this->updateBroadcastingConfig();

            // Display installation summary
            $this->displayInstallationSummary();
        }

        $this->info('NATS Broadcaster installed successfully!');
    }


    protected function updateEnvFile(): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            $this->warn('.env file not found. Skipping environment variable updates.');
            return;
        }

        $envContent = File::get($envPath);
        $envUpdates = [];

        // Updated variables including TLS settings
        $variables = [
            'BROADCAST_CONNECTION' => 'nats',
            'NATS_HOST' => 'localhost',
            'NATS_PORT' => '4222',
            'NATS_USER' => '',
            'NATS_PASS' => '',
            'NATS_TOKEN' => '',
            'NATS_PREFIX' => 'app',
            'NATS_DEBUG' => 'false',
            'NATS_RECONNECT' => 'true',
            'NATS_TIMEOUT' => '5',

            // TLS Configuration
            'NATS_TLS_ENABLED' => 'false',
            'NATS_TLS_CERT_FILE' => '',
            'NATS_TLS_KEY_FILE' => '',
            'NATS_TLS_CA_FILE' => '',
            'NATS_TLS_VERIFY_PEER' => 'true',
            'NATS_TLS_VERIFY_PEER_NAME' => 'true',
            'NATS_TLS_ALLOW_SELF_SIGNED' => 'false',

            // JetStream
            'NATS_JETSTREAM' => 'false',
        ];

        foreach ($variables as $key => $defaultValue) {
            if (!preg_match("/^{$key}=/m", $envContent)) {
                if (in_array($key, ['NATS_TLS_CERT_FILE', 'NATS_TLS_KEY_FILE', 'NATS_TLS_CA_FILE'])) {
                    // Skip asking for TLS file paths during install
                    $envUpdates[] = "{$key}={$defaultValue}";
                } else {
                    $value = $this->ask("Enter value for {$key} [{$defaultValue}]", $defaultValue);
                    $envUpdates[] = "{$key}={$value}";
                }
            }
        }

        if (!empty($envUpdates)) {
            $envContent .= PHP_EOL . '# NATS Broadcasting' . PHP_EOL;
            $envContent .= implode(PHP_EOL, $envUpdates);

            File::put($envPath, $envContent);
            $this->info('Environment variables updated.');
        } else {
            $this->info('All NATS environment variables already configured.');
        }
    }

    protected function updateBroadcastingConfig(): void
    {
        $configPath = config_path('broadcasting.php');

        if (!File::exists($configPath)) {
            return;
        }

        $configContent = File::get($configPath);

        // Check if nats connection is already configured
        if (!str_contains($configContent, "'driver' => 'nats'")) {
            $natsConfig = <<<'PHP'
        'nats' => [
            'driver' => 'nats',
            'host' => env('NATS_HOST', 'localhost'),
            'port' => env('NATS_PORT', 4222),
            'user' => env('NATS_USER'),
            'pass' => env('NATS_PASS'),
            'token' => env('NATS_TOKEN'),
        ],

PHP;

            // Insert after pusher config or at the end of connections array
            if (str_contains($configContent, "'driver' => 'pusher'")) {
                $configContent = str_replace(
                    "'driver' => 'pusher'",
                    "'driver' => 'pusher'" . PHP_EOL . $natsConfig,
                    $configContent
                );
            } else {
                // Add before the closing bracket of connections array
                $configContent = preg_replace(
                    '/\s+\],\s+\/\/ Other connections/',
                    PHP_EOL . $natsConfig . '    ],' . PHP_EOL . '    // Other connections',
                    $configContent
                );
            }

            File::put($configPath, $configContent);
            $this->info('Broadcasting configuration updated.');
        }
    }

    protected function displayInstallationSummary(): void
    {
        $this->newLine();
        $this->info('📦 NATS Broadcaster Installation Summary');
        $this->line('----------------------------------------');
        $this->line('✅ Configuration published to config/nats-broadcasting.php');
        $this->line('✅ Environment variables added to .env');
        $this->line('✅ Broadcasting configuration updated');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('1. Update your .env file with actual NATS server credentials');
        $this->line('2. Run php artisan config:clear');
        $this->line('3. Test connection with php artisan nats:info');
        $this->newLine();
    }
}