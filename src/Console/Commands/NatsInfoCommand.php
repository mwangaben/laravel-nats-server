<?php
namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;
use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;

class NatsInfoCommand extends Command
{
    protected $signature = 'nats:info';
    protected $description = 'Display NATS connection information and test connectivity';

    public function handle(): void
    {
        $this->info('🔌 NATS Connection Information');
        $this->line('===============================');

        try {
            // Get configuration properly
            $config = config('broadcasting.connections.nats', []);

            // Extract TLS config safely
            $tlsEnabled = isset($config['tls']) && is_array($config['tls']) && ($config['tls']['enabled'] ?? false);

            // Display basic configuration
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Host', $config['host'] ?? 'localhost'],
                    ['Port', $config['port'] ?? 4222],
                    ['User', $config['user'] ?? 'Not set'],
                    ['Password', isset($config['pass']) && $config['pass'] ? '••••••' : 'Not set'],
                    ['Token', isset($config['token']) && $config['token'] ? '••••••' : 'Not set'],
                    ['Prefix', $config['prefix'] ?? 'Not set'],
                    ['Timeout', $config['timeout'] ?? 5],
                    ['Reconnect', $config['reconnect'] ?? true ? 'Yes' : 'No'],
                    ['Debug', $config['debug'] ?? false ? 'Yes' : 'No'],
                    ['TLS Enabled', $tlsEnabled ? '✅ Yes' : '❌ No'],
                ]
            );

            // Display TLS configuration details
            if ($tlsEnabled) {
                $this->info("\n🔐 TLS Configuration:");
                $tlsConfig = $config['tls'];
                $tlsTable = [
                    ['Certificate File', $tlsConfig['cert_file'] ?? 'Not set'],
                    ['Key File', $tlsConfig['key_file'] ?? 'Not set'],
                    ['CA File', $tlsConfig['ca_file'] ?? 'Not set'],
                    ['Verify Peer', $tlsConfig['verify_peer'] ?? true ? 'Yes' : 'No'],
                    ['Verify Peer Name', $tlsConfig['verify_peer_name'] ?? true ? 'Yes' : 'No'],
                    ['Allow Self-Signed', $tlsConfig['allow_self_signed'] ?? false ? 'Yes' : 'No'],
                ];

                $this->table(['TLS Setting', 'Value'], $tlsTable);

                // Check if certificate files exist
                $this->info("\n📁 Certificate Files Check:");
                $this->checkCertificateFiles($tlsConfig);
            }

            // Test connection
            $this->newLine();
            $this->info('Testing connection...');

            // Get broadcaster instance
            $broadcaster = app(NatsBroadcaster::class);

            // Get client to check TLS info before connecting
            $client = $broadcaster->getClient();

            // Try to get TLS info from client if method exists
            try {
                if (method_exists($client, 'getTlsInfo')) {
                    $tlsInfo = $client->getTlsInfo();
                    if ($tlsInfo['enabled'] ?? false) {
                        $this->info('🔒 TLS is configured in client');
                    }
                }
            } catch (\Exception $e) {
                // Ignore if method doesn't exist
            }

            $broadcaster->connect();

            if ($broadcaster->isConnected()) {
                $this->info('✅ Connected to NATS server successfully!');

                // Display connection type
                if ($tlsEnabled) {
                    $this->info('🔒 Connection is using TLS');
                } else {
                    $this->info('🔓 Connection is NOT using TLS (plain text)');
                }

                // Test publish
                try {
                    $broadcaster->getClient()->publish('test.connection', json_encode([
                        'test' => true,
                        'timestamp' => now()->toISOString(),
                        'tls' => $tlsEnabled,
                        'config_tls_enabled' => $tlsEnabled,
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
            $this->line('');
            $this->warn('Troubleshooting steps:');
            $this->line('1. Make sure NATS server is running');
            $this->line('2. Check if port is accessible');
            $this->line('3. Verify NATS_HOST in .env file');
            $this->line('4. Check TLS certificates if enabled');

            // Show debug info
            $this->line("\n📋 Debug Information:");
            $this->line("Error: " . $e->getMessage());
            if ($config['debug'] ?? false) {
                $this->line("Trace: " . $e->getTraceAsString());
            }
        }
    }

    /**
     * Check certificate files
     */
    private function checkCertificateFiles(array $tlsConfig): void
    {
        $files = [
            'Certificate' => $tlsConfig['cert_file'] ?? null,
            'Private Key' => $tlsConfig['key_file'] ?? null,
            'CA Certificate' => $tlsConfig['ca_file'] ?? null,
        ];

        foreach ($files as $type => $file) {
            if (empty($file)) {
                $this->line("{$type}: ❌ Not configured");
                continue;
            }

            if (!file_exists($file)) {
                $this->line("{$type}: ❌ File not found: {$file}");
            } else {
                $size = filesize($file);
                $perms = fileperms($file);
                $permissionString = substr(sprintf('%o', $perms), -4);

                $this->line("{$type}: ✅ {$file} ({$size} bytes, permissions: {$permissionString})");

                // Validate certificate format for .crt files
                if ($type === 'Certificate' || $type === 'CA Certificate') {
                    $content = file_get_contents($file);
                    if (strpos($content, '-----BEGIN CERTIFICATE-----') === false) {
                        $this->warn("       ⚠️  File doesn't appear to be a valid PEM certificate");
                    } else {
                        // Try to parse certificate
                        $cert = openssl_x509_read($content);
                        if ($cert) {
                            $info = openssl_x509_parse($cert);
                            $validTo = date('Y-m-d', $info['validTo_time_t']);
                            $daysLeft = floor(($info['validTo_time_t'] - time()) / (60 * 60 * 24));

                            $this->line("       Valid to: {$validTo} ({$daysLeft} days remaining)");
                            $this->line("       Subject: {$info['name']}");

                            if ($daysLeft < 30) {
                                $this->warn("       ⚠️  Certificate expires soon!");
                            }
                        }
                    }
                }

                // Check key permissions
                if ($type === 'Private Key' && ($perms & 0777) != 0600) {
                    $this->warn("       ⚠️  Private key should have 600 permissions");
                }
            }
        }
    }
}