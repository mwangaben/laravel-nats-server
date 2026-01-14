<?php
namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class NatsTlsCommand extends Command
{
    protected $signature = 'nats:tls
                            {action : Action to perform (generate, test, info)}
                            {--domain=localhost : Domain for certificate generation}
                            {--days=365 : Validity in days}
                            {--output=storage/certs/nats : Output directory}
                            {--force : Overwrite existing files}';

    protected $description = 'Manage TLS certificates for NATS';

    public function handle(): void
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'generate':
                $this->generateCertificates();
                break;
            case 'test':
                $this->testTlsConnection();
                break;
            case 'info':
                $this->showTlsInfo();
                break;
            default:
                $this->error("Unknown action: {$action}");
                $this->info("Available actions: generate, test, info");
                break;
        }
    }

    protected function generateCertificates(): void
    {
        $domain = $this->option('domain');
        $days = (int) $this->option('days');
        $outputDir = $this->option('output');
        $force = $this->option('force');

        // Create output directory
        if (!File::exists($outputDir)) {
            File::makeDirectory($outputDir, 0755, true);
        }

        $certPath = "{$outputDir}/{$domain}.crt";
        $keyPath = "{$outputDir}/{$domain}.key";
        $caPath = "{$outputDir}/ca.crt";

        // Check if files exist
        if (!$force && (File::exists($certPath) || File::exists($keyPath) || File::exists($caPath))) {
            if (!$this->confirm("Certificate files already exist. Overwrite?")) {
                return;
            }
        }

        $this->info("Generating TLS certificates for domain: {$domain}");
        $this->info("Output directory: {$outputDir}");
        $this->info("Validity: {$days} days");

        // Generate CA
        $this->info("\nGenerating Certificate Authority...");
        $this->generateCA($caPath, $days, $domain);

        // Generate server certificate
        $this->info("\nGenerating Server Certificate...");
        $this->generateServerCertificate($certPath, $keyPath, $caPath, $domain, $days);

        // Generate client certificate (optional)
        $clientCertPath = "{$outputDir}/client.crt";
        $clientKeyPath = "{$outputDir}/client.key";
        $this->info("\nGenerating Client Certificate...");
        $this->generateClientCertificate($clientCertPath, $clientKeyPath, $caPath, $domain, $days);

        $this->info("\n✅ TLS certificates generated successfully!");

        $this->showCertificateSummary($certPath, $keyPath, $caPath, $clientCertPath, $clientKeyPath);
        $this->showEnvConfiguration($certPath, $keyPath, $caPath);
        $this->showSecurityTips($certPath, $keyPath);
    }

    protected function generateCA(string $caPath, int $days, string $domain): void
    {
        $dn = [
            "countryName" => "US",
            "stateOrProvinceName" => "California",
            "localityName" => "San Francisco",
            "organizationName" => "Laravel NATS",
            "organizationalUnitName" => "Certificate Authority",
            "commonName" => "Laravel NATS CA - {$domain}",
            "emailAddress" => "ca@{$domain}"
        ];

        $privateKey = openssl_pkey_new([
            "private_key_bits" => 4096,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $privateKey, $days, ['digest_alg' => 'sha256']);

        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        File::put($caPath, $certOut . $privateKeyOut);
        $this->info("CA certificate generated: {$caPath}");
    }

    protected function generateServerCertificate(string $certPath, string $keyPath, string $caPath, string $domain, int $days): void
    {
        $dn = [
            "countryName" => "US",
            "stateOrProvinceName" => "California",
            "localityName" => "San Francisco",
            "organizationName" => "Laravel NATS",
            "organizationalUnitName" => "Server",
            "commonName" => $domain,
            "emailAddress" => "server@{$domain}"
        ];

        // Add Subject Alternative Names for localhost
        $san = "DNS:{$domain}, DNS:*.{$domain}, IP:127.0.0.1, IP:::1";

        $config = [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_req',
            'req_extensions' => 'v3_req',
        ];

        $privateKey = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new($dn, $privateKey, $config);

        // Add SAN to CSR
        $x509 = openssl_csr_sign($csr, null, $privateKey, $days, $config, 0);

        // Load CA
        $caContent = File::get($caPath);
        $caPrivateKey = openssl_pkey_get_private($caContent);
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $caContent, $matches);
        $caCert = openssl_x509_read($matches[0] ?? '');

        // Sign with CA
        $cert = openssl_csr_sign($csr, $caCert, $caPrivateKey, $days, $config, 0);

        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        File::put($certPath, $certOut);
        File::put($keyPath, $privateKeyOut);

        $this->info("Server certificate generated: {$certPath}");
        $this->info("Server private key generated: {$keyPath}");
    }

    protected function generateClientCertificate(string $certPath, string $keyPath, string $caPath, string $domain, int $days): void
    {
        $dn = [
            "countryName" => "US",
            "stateOrProvinceName" => "California",
            "localityName" => "San Francisco",
            "organizationName" => "Laravel NATS",
            "organizationalUnitName" => "Client",
            "commonName" => "client.{$domain}",
            "emailAddress" => "client@{$domain}"
        ];

        $privateKey = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);

        // Load CA
        $caContent = File::get($caPath);
        $caPrivateKey = openssl_pkey_get_private($caContent);
        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $caContent, $matches);
        $caCert = openssl_x509_read($matches[0] ?? '');

        $cert = openssl_csr_sign($csr, $caCert, $caPrivateKey, $days, ['digest_alg' => 'sha256']);

        openssl_x509_export($cert, $certOut);
        openssl_pkey_export($privateKey, $privateKeyOut);

        File::put($certPath, $certOut);
        File::put($keyPath, $privateKeyOut);

        $this->info("Client certificate generated: {$certPath}");
        $this->info("Client private key generated: {$keyPath}");
    }

    protected function showCertificateSummary(string $certPath, string $keyPath, string $caPath, string $clientCertPath, string $clientKeyPath): void
    {
        $this->info("\n📋 Certificate Summary:");

        $files = [
            ['CA Certificate', $caPath],
            ['Server Certificate', $certPath],
            ['Server Private Key', $keyPath],
            ['Client Certificate', $clientCertPath],
            ['Client Private Key', $clientKeyPath],
        ];

        $tableData = [];
        foreach ($files as [$type, $path]) {
            if (!File::exists($path)) {
                $tableData[] = [$type, $path, 'File not found', 'N/A'];
                continue;
            }

            $size = File::size($path);
            $perms = fileperms($path);
            $permissionString = $perms !== false ? substr(sprintf('%o', $perms), -4) : 'N/A';

            $tableData[] = [$type, $path, $this->formatBytes($size), $permissionString];
        }

        $this->table(
            ['Type', 'File', 'Size', 'Permissions'],
            $tableData
        );
    }

    protected function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function showEnvConfiguration(string $certPath, string $keyPath, string $caPath): void
    {
        $this->info("\n⚙️  Add to your .env file:");
        $this->line("NATS_TLS_ENABLED=true");
        $this->line("NATS_TLS_CERT_FILE={$certPath}");
        $this->line("NATS_TLS_KEY_FILE={$keyPath}");
        $this->line("NATS_TLS_CA_FILE={$caPath}");
        $this->line("NATS_TLS_VERIFY_PEER=false"); // For self-signed
        $this->line("NATS_TLS_ALLOW_SELF_SIGNED=true"); // For self-signed
    }

    protected function showSecurityTips(string $certPath, string $keyPath): void
    {
        $this->info("\n🔒 Security Tips:");
        $this->line("1. Set proper permissions for private keys:");
        $this->line("   chmod 600 {$keyPath}");
        $this->line("   chmod 600 storage/certs/nats/client.key");
        $this->line("\n2. For production, use proper certificates (not self-signed)");
        $this->line("\n3. Store certificates outside web root directory");
        $this->line("\n4. Consider using Let's Encrypt for production certificates");
    }

    protected function testTlsConnection(): void
    {
        $config = config('broadcasting.connections.nats');

        if (!($config['tls']['enabled'] ?? false)) {
            $this->error("TLS is not enabled in configuration.");
            $this->info("Enable TLS by setting NATS_TLS_ENABLED=true in .env");
            return;
        }

        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 4222;

        $this->info("Testing TLS connection to {$host}:{$port}...");

        try {
            // Test using stream socket
            $sslOptions = [
                'verify_peer' => $config['tls']['verify_peer'] ?? true,
                'verify_peer_name' => $config['tls']['verify_peer_name'] ?? true,
                'allow_self_signed' => $config['tls']['allow_self_signed'] ?? false,
            ];

            // Add certificate files if provided
            if (!empty($config['tls']['ca_file'])) {
                $sslOptions['cafile'] = $config['tls']['ca_file'];
            }
            if (!empty($config['tls']['cert_file'])) {
                $sslOptions['local_cert'] = $config['tls']['cert_file'];
            }
            if (!empty($config['tls']['key_file'])) {
                $sslOptions['local_pk'] = $config['tls']['key_file'];
            }

            $context = stream_context_create(['ssl' => $sslOptions]);

            $socket = @stream_socket_client(
                "tls://{$host}:{$port}",
                $errno,
                $errstr,
                5,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$socket) {
                $this->error("❌ Connection failed: {$errstr} ({$errno})");

                // Provide more detailed troubleshooting
                $this->info("\n🔧 Troubleshooting:");
                if (strpos($errstr, 'certificate') !== false) {
                    $this->line("Certificate error detected:");
                    $this->line("1. Check if certificates are valid and not expired");
                    $this->line("2. Try with NATS_TLS_VERIFY_PEER=false temporarily");
                    $this->line("3. Check certificate paths in .env");
                }
                return;
            }

            $this->info("✅ TLS connection successful!");

            // Get TLS info
            $crypto = @stream_socket_get_name($socket, true);
            if ($crypto) {
                $this->info("Protocol: {$crypto}");
            }

            // Try to read server INFO
            fwrite($socket, "INFO\r\n");
            $response = fread($socket, 4096);

            if (strpos($response, 'INFO') === 0) {
                $this->info("Server responded with INFO");

                // Parse INFO JSON
                $infoJson = substr($response, 5);
                $info = json_decode($infoJson, true);

                if ($info) {
                    $this->line("Server ID: {$info['server_id']}");
                    $this->line("Version: {$info['version']}");
                    if (isset($info['tls_required']) && $info['tls_required']) {
                        $this->info("Server requires TLS: ✅ Yes");
                    }
                }
            }

            fclose($socket);

        } catch (\Exception $e) {
            $this->error("❌ TLS test failed: " . $e->getMessage());

            $this->info("\n🔧 Troubleshooting tips:");
            $this->line("1. Check if NATS server is running with TLS enabled");
            $this->line("2. Verify NATS_HOST and NATS_PORT in .env");
            $this->line("3. Test with: openssl s_client -connect {$host}:{$port} -showcerts");
            $this->line("4. For self-signed certificates, set:");
            $this->line("   NATS_TLS_VERIFY_PEER=false");
            $this->line("   NATS_TLS_ALLOW_SELF_SIGNED=true");
        }
    }

    protected function showTlsInfo(): void
    {
        $config = config('broadcasting.connections.nats');

        $this->info("📊 TLS Configuration Information");
        $this->line("=================================");

        $this->table(
            ['Setting', 'Value'],
            [
                ['TLS Enabled', $config['tls']['enabled'] ? '✅ Yes' : '❌ No'],
                ['Certificate File', $config['tls']['cert_file'] ?? 'Not set'],
                ['Key File', $config['tls']['key_file'] ?? 'Not set'],
                ['CA File', $config['tls']['ca_file'] ?? 'Not set'],
                ['Verify Peer', $config['tls']['verify_peer'] ? 'Yes' : 'No'],
                ['Verify Peer Name', $config['tls']['verify_peer_name'] ? 'Yes' : 'No'],
                ['Allow Self-Signed', $config['tls']['allow_self_signed'] ? 'Yes' : 'No'],
            ]
        );

        if ($config['tls']['enabled']) {
            // Check certificate files
            $this->info("\n🔍 Certificate File Status:");

            $files = [
                'Certificate' => $config['tls']['cert_file'] ?? null,
                'Private Key' => $config['tls']['key_file'] ?? null,
                'CA Certificate' => $config['tls']['ca_file'] ?? null,
            ];

            foreach ($files as $type => $file) {
                if (!$file) {
                    $this->line("{$type}: ❌ Not configured");
                    continue;
                }

                if (!File::exists($file)) {
                    $this->line("{$type}: ❌ File not found: {$file}");
                } else {
                    $size = File::size($file);
                    $perms = fileperms($file);
                    $permissionString = $perms !== false ? substr(sprintf('%o', $perms), -4) : 'N/A';

                    $this->line("{$type}: ✅ {$file} ({$this->formatBytes($size)}, permissions: {$permissionString})");

                    // Validate certificate
                    if ($type === 'Certificate' || $type === 'CA Certificate') {
                        $content = File::get($file);
                        $cert = openssl_x509_read($content);
                        if ($cert) {
                            $info = openssl_x509_parse($cert);
                            $validFrom = date('Y-m-d H:i:s', $info['validFrom_time_t']);
                            $validTo = date('Y-m-d H:i:s', $info['validTo_time_t']);
                            $this->line("       Valid from: {$validFrom} to {$validTo}");
                            $this->line("       Subject: {$info['name']}");

                            // Check if certificate is about to expire (30 days warning)
                            $daysLeft = floor(($info['validTo_time_t'] - time()) / (60 * 60 * 24));
                            if ($daysLeft < 30) {
                                $this->warn("       ⚠️  Certificate expires in {$daysLeft} days!");
                            }
                        } else {
                            $this->warn("       ⚠️  Could not read certificate");
                        }
                    }

                    // Check key permissions
                    if ($type === 'Private Key') {
                        if (($perms & 0777) != 0600) {
                            $this->warn("       ⚠️  Private key should have 600 permissions (currently: {$permissionString})");
                        }
                    }
                }
            }
        }

        // Show connection test suggestion
        $this->info("\n🔧 Quick Test:");
        $this->line("Run: php artisan nats:tls test");
        $this->line("Run: php artisan nats:info");
    }
}