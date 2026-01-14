<?php

namespace Mwangaben\NatsBroadcaster\Nats;

class PatchedClient
{
    private $socket;
    private $host;
    private $port;
    private $user;
    private $pass;
    private $token;
    private $connected = false;
    private $debug = false;
    private $timeout = 5;
    private $subscriptions = [];
    private $shouldStop = false;
    private $tlsConfig = [];
    private $tlsContext = [];
    private $streamContext;
    private $buffer = '';
    private $lastActivity;
    private $useStartTls = false;
    private $socketMeta = [];

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? 'localhost';
        $this->port = $config['port'] ?? 4222;
        $this->user = $config['user'] ?? null;
        $this->pass = $config['pass'] ?? null;
        $this->token = $config['token'] ?? null;
        $this->debug = $config['debug'] ?? false;
        $this->timeout = $config['timeout'] ?? 5;

        // Parse TLS configuration
        $this->parseTlsConfig($config);

        // Handle tls_context
        $this->tlsContext = $config['tls_context'] ?? [];

        // Create stream context
        $this->streamContext = stream_context_create();

        // Configure TLS if enabled
        if ($this->tlsConfig['enabled']) {
            $this->configureTls();
        }

        if ($this->debug) {
            error_log("[NATS] Client configured for {$this->host}:{$this->port}");
            error_log("[NATS] TLS enabled: ".($this->tlsConfig['enabled'] ? 'YES' : 'NO'));
        }
    }

    private function parseTlsConfig(array $config): void
    {
        if (!isset($config['tls'])) {
            $this->tlsConfig = ['enabled' => false];

            return;
        }

        if (is_bool($config['tls'])) {
            $this->tlsConfig = ['enabled' => $config['tls']];
        } elseif (is_array($config['tls'])) {
            $this->tlsConfig = array_merge(['enabled' => false], $config['tls']);
            $this->tlsConfig['enabled'] = (bool) $this->tlsConfig['enabled'];
        }
    }

//    private function configureTls(): void
//    {
//        $sslOptions = [
//            'verify_peer' => $this->tlsConfig['verify_peer'] ?? false,
//            'verify_peer_name' => $this->tlsConfig['verify_peer_name'] ?? false,
//            'allow_self_signed' => $this->tlsConfig['allow_self_signed'] ?? true,
//            'peer_name' => $this->host, // Important for SNI
//        ];
//
//        // Add CA file if specified
//        if (!empty($this->tlsConfig['ca_file']) && file_exists($this->tlsConfig['ca_file'])) {
//            $sslOptions['cafile'] = $this->tlsConfig['ca_file'];
//        } else {
//            // Try system CA bundles
//            $systemCAs = [
//                '/etc/ssl/certs/ca-certificates.crt',
//                '/etc/pki/tls/certs/ca-bundle.crt',
//                '/usr/local/etc/openssl/cert.pem',
//                '/etc/ssl/cert.pem',
//            ];
//            foreach ($systemCAs as $caFile) {
//                if (file_exists($caFile)) {
//                    $sslOptions['cafile'] = $caFile;
//                    break;
//                }
//            }
//        }
//
//        // Add tls_context options
//        if (!empty($this->tlsContext)) {
//            $sslOptions = array_merge($sslOptions, $this->tlsContext);
//        }
//
//        // Set context options
//        stream_context_set_option($this->streamContext, ['ssl' => $sslOptions]);
//
//        if ($this->debug) {
//            error_log("[NATS] SSL options configured");
//        }
//    }


    private function configureTls(): void
    {
        $sslOptions = [
            'verify_peer'         => $this->tlsConfig['verify_peer'] ?? false,
            'verify_peer_name'    => $this->tlsConfig['verify_peer_name'] ?? false,
            'allow_self_signed'   => $this->tlsConfig['allow_self_signed'] ?? true,
            'peer_name'           => $this->host,
            'SNI_enabled'         => true,
            'disable_compression' => true,
            'ciphers'             => 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384',
        ];

        // For NATS, we might need specific TLS settings
        // Try to match what the nats CLI uses

        // Add CA certificates
        $this->addCaCertificates($sslOptions);

        // Add tls_context options
        if (!empty($this->tlsContext)) {
            $sslOptions = array_merge($sslOptions, $this->tlsContext);
        }

        if ($this->debug) {
            error_log("[NATS] Final SSL options keys: ".implode(', ', array_keys($sslOptions)));
        }

        stream_context_set_option($this->streamContext, ['ssl' => $sslOptions]);
    }

    private function addCaCertificates(array &$sslOptions): void
    {
        // First, check if a specific CA file is configured
        if (!empty($this->tlsConfig['ca_file']) && file_exists($this->tlsConfig['ca_file'])) {
            $sslOptions['cafile'] = $this->tlsConfig['ca_file'];

            return;
        }

        // Try system CA bundles
        $systemCAs = [
            // Linux
            '/etc/ssl/certs/ca-certificates.crt',      // Ubuntu/Debian
            '/etc/pki/tls/certs/ca-bundle.crt',        // RHEL/CentOS/Fedora
            '/etc/pki/tls/cacert.pem',                 // OpenSUSE
            '/etc/ssl/cert.pem',                       // Alpine
            '/etc/pki/tls/certs/ca-bundle.trust.crt',  // Some systems

            // macOS
            '/usr/local/etc/openssl/cert.pem',         // Homebrew OpenSSL
            '/usr/local/etc/openssl@1.1/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            '/opt/homebrew/etc/openssl@3/cert.pem',    // Apple Silicon Homebrew
            '/opt/homebrew/etc/openssl/cert.pem',

            // Windows (if using WSL)
            '/usr/lib/ssl/certs/ca-certificates.crt',

            // Fallback - use PHP's built-in CA bundle if available
            function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations()['default_cert_file'] ?? null : null,
        ];

        foreach ($systemCAs as $caFile) {
            if ($caFile && file_exists($caFile)) {
                $sslOptions['cafile'] = $caFile;
                if ($this->debug) {
                    error_log("[NATS] Using CA file: {$caFile}");
                }

                return;
            }
        }

        if ($this->debug) {
            error_log("[NATS] No CA file found, TLS verification may fail");
        }
    }


    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->debug) {
            error_log("[NATS] Connecting to {$this->host}:{$this->port}");
            error_log("[NATS] TLS enabled: ".($this->tlsConfig['enabled'] ? 'YES' : 'NO'));
        }

        if ($this->tlsConfig['enabled']) {
            $this->connectWithNatsTls();
        } else {
            $this->connectWithTcp();
        }

        if (!$this->connected) {
            throw new \RuntimeException("Failed to connect to NATS server");
        }

        $this->lastActivity = time();

        if ($this->debug) {
            error_log("[NATS] Connection established successfully");
        }
    }

    private function connectWithStartTls(): void
    {
        if ($this->debug) {
            error_log("[NATS] Starting STARTTLS connection");
        }

        // Step 1: Connect with plain TCP
        $address = "tcp://{$this->host}:{$this->port}";
        $this->socket = @stream_socket_client($address, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            // Try direct TLS connection instead
            $this->connectWithDirectTls();

            return;
//            throw new \RuntimeException("TCP connection failed: {$errstr} ({$errno})");
        }

        if ($this->debug) {
            error_log("[NATS] TCP connection established");
        }

        // Step 2: Set to non-blocking for handshake
        stream_set_blocking($this->socket, false);

        // Step 3: Read server INFO
        $info = $this->readLine(2); // 2 second timeout for INFO
        if (strpos($info, 'INFO ') !== 0) {
            throw new \RuntimeException("Invalid server response: {$info}");
        }

        $infoJson = substr($info, 5);
        $infoData = json_decode($infoJson, true);

        if ($this->debug) {
            error_log("[NATS] Server INFO received");
            error_log("[NATS] TLS required: ".($infoData['tls_required'] ?? 'false'));
        }

        // Step 4: Send STARTTLS if required
        if (isset($infoData['tls_required']) && $infoData['tls_required']) {
            if ($this->debug) {
                error_log("[NATS] Server requires TLS, sending STARTTLS");
            }

            // Send STARTTLS command
            $this->write("STARTTLS\r\n");

            // Read response
            $response = $this->readLine(2);

            if ($this->debug) {
                error_log("[NATS] STARTTLS response: {$response}");
            }

            if (strpos($response, '+OK') === 0) {
                // Step 5: Upgrade to TLS
                if ($this->debug) {
                    error_log("[NATS] Upgrading socket to TLS");
                }

                // Set blocking for crypto operation
                stream_set_blocking($this->socket, true);

                // Enable TLS crypto
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
                }
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT')) {
                    $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
                }

                if (!stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
                    throw new \RuntimeException("Failed to enable TLS on socket");
                }

                $this->useStartTls = true;

                if ($this->debug) {
                    $meta = stream_get_meta_data($this->socket);
                    $this->socketMeta = $meta;
                    error_log("[NATS] Socket upgraded to TLS");
                    error_log("[NATS] Crypto protocol: ".($meta['wrapper_type'] ?? 'unknown'));
                }
            } else {
                throw new \RuntimeException("STARTTLS not supported: {$response}");
            }
        }

        // Step 6: Complete authentication
        $this->completeAuthentication();
        $this->connected = true;
    }

    private function connectWithDirectTls(): void
    {
        if ($this->debug) {
            error_log("[NATS] Attempting direct TLS connection");
        }

        $address = "tls://{$this->host}:{$this->port}";

        // Apply SSL context
        if ($this->streamContext) {
            $this->socket = @stream_socket_client(
                $address,
                $errno,
                $errstr,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $this->streamContext
            );
        } else {
            $this->socket = @stream_socket_client($address, $errno, $errstr, $this->timeout);
        }

        if (!$this->socket) {
            throw new \RuntimeException("TLS connection failed: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->socket, false);

        // Read server INFO
        $info = $this->readLine(2);
        if (strpos($info, 'INFO ') !== 0) {
            throw new \RuntimeException("Invalid server response: {$info}");
        }

        $infoJson = substr($info, 5);
        $infoData = json_decode($infoJson, true);

        if ($this->debug) {
            error_log("[NATS] Direct TLS connection established");
            error_log("[NATS] Server INFO: ".json_encode($infoData));
        }

        $this->completeAuthentication();
        $this->connected = true;
        $this->useStartTls = false;
    }


    private function connectWithNatsTls(): void
    {
        if ($this->debug) {
            error_log("[NATS] Starting NATS TLS connection (TCP then implicit TLS)");
        }

        // Step 1: Connect with plain TCP
        $address = "tcp://{$this->host}:{$this->port}";
        $this->socket = @stream_socket_client($address, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            throw new \RuntimeException("TCP connection failed: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->socket, false);

        // Step 2: Read server INFO
        $info = $this->readLine(2);
        if (strpos($info, 'INFO ') !== 0) {
            throw new \RuntimeException("Invalid server response: {$info}");
        }

        $infoJson = substr($info, 5);
        $infoData = json_decode($infoJson, true);

        if ($this->debug) {
            error_log("[NATS] Server INFO received");
            error_log("[NATS] TLS required: ".($infoData['tls_required'] ?? 'false'));
        }

        // Step 3: Check if TLS is required
        if (isset($infoData['tls_required']) && $infoData['tls_required']) {
            // Server requires TLS - upgrade socket to TLS WITHOUT sending STARTTLS
            $this->upgradeToTls();
        } else {
            // Server doesn't require TLS, proceed with plain connection
            if ($this->debug) {
                error_log("[NATS] Server doesn't require TLS, proceeding with plain connection");
            }
        }

        // Step 4: Complete authentication
        $this->completeAuthentication();
        $this->connected = true;
    }

    private function upgradeToTls(): void
    {
        if ($this->debug) {
            error_log("[NATS] Upgrading socket to TLS (implicit)");
        }

        // Set blocking for crypto operation
        stream_set_blocking($this->socket, true);

        // Create a new stream context with SSL options
        $sslOptions = [
            'verify_peer'       => $this->tlsConfig['verify_peer'] ?? false,
            'verify_peer_name'  => $this->tlsConfig['verify_peer_name'] ?? false,
            'allow_self_signed' => $this->tlsConfig['allow_self_signed'] ?? true,
            'peer_name'         => $this->host,
            'SNI_enabled'       => true,
            'capture_peer_cert' => true,
        ];

        // Add certificate files if provided
        if (!empty($this->tlsConfig['cert_file']) && file_exists($this->tlsConfig['cert_file'])) {
            $sslOptions['local_cert'] = $this->tlsConfig['cert_file'];
        }
        if (!empty($this->tlsConfig['key_file']) && file_exists($this->tlsConfig['key_file'])) {
            $sslOptions['local_pk'] = $this->tlsConfig['key_file'];
        }
        if (!empty($this->tlsConfig['ca_file']) && file_exists($this->tlsConfig['ca_file'])) {
            $sslOptions['cafile'] = $this->tlsConfig['ca_file'];
        }

        if ($this->debug) {
            error_log("[NATS] SSL options: ".json_encode($sslOptions));
        }

        // IMPORTANT: For an existing socket, we need to use stream_socket_enable_crypto
        // with a custom context. We'll create a new context and pass it to the function.

        // Create a stream context
        $context = stream_context_create(['ssl' => $sslOptions]);

        // Get the current socket's context and merge options
        $currentContext = stream_context_get_options($this->socket);
        if (!empty($currentContext)) {
            $mergedOptions = array_merge_recursive($currentContext, ['ssl' => $sslOptions]);
            if (!stream_context_set_option($this->socket, $mergedOptions)) {
                error_log("[NATS] Warning: Could not set context options on existing socket");
            }
        }

        // Enable TLS - IMPORTANT: Use the right crypto method
        // NATS 2.x typically uses TLS 1.2 or 1.3
        $cryptoMethod = 0;

        // Try to detect available methods
        if (defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        // If no specific method defined, use ANY
        if ($cryptoMethod === 0) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_ANY_CLIENT;
        }

        if ($this->debug) {
            error_log("[NATS] Using crypto method: {$cryptoMethod}");
            error_log("[NATS] Enabling crypto on socket...");
        }

        $startTime = microtime(true);
        $timeout = 10; // Give more time for TLS handshake

        // Try to enable crypto with timeout
        // Note: For PHP 8.0+, we need to handle this differently
        $attempts = 0;
        $maxAttempts = 10;

        while ($attempts < $maxAttempts) {
            $result = @stream_socket_enable_crypto($this->socket, true, $cryptoMethod);

            if ($result === true) {
                // TLS handshake successful
                if ($this->debug) {
                    error_log("[NATS] TLS handshake successful on attempt ".($attempts + 1));
                }
                break;
            } elseif ($result === false) {
                // Get SSL errors for debugging
                $sslErrors = [];
                while ($sslError = openssl_error_string()) {
                    $sslErrors[] = $sslError;
                }

                $errorMsg = "Failed to enable TLS on socket";
                if (!empty($sslErrors)) {
                    $errorMsg .= ": ".implode(", ", $sslErrors);
                }

                throw new \RuntimeException($errorMsg);
            }

            // $result === 0 means need more data, wait a bit
            $attempts++;
            usleep(500000); // 500ms between attempts

            if ((microtime(true) - $startTime) > $timeout) {
                throw new \RuntimeException("TLS handshake timeout after {$timeout} seconds");
            }
        }

        if ($attempts >= $maxAttempts) {
            throw new \RuntimeException("Failed to enable TLS after {$maxAttempts} attempts");
        }

        // Set back to non-blocking
        stream_set_blocking($this->socket, false);

        if ($this->debug) {
            $meta = stream_get_meta_data($this->socket);
            error_log("[NATS] Socket upgraded to TLS");

            // Check if crypto info is available
            $cryptoInfo = @stream_socket_get_name($this->socket, true);
            if ($cryptoInfo) {
                error_log("[NATS] Crypto info: {$cryptoInfo}");
            }

            // Try to get more detailed crypto info
            if (function_exists('stream_get_meta_data')) {
                $meta = stream_get_meta_data($this->socket);
                if (isset($meta['crypto'])) {
                    error_log("[NATS] Crypto protocol: ".($meta['crypto']['protocol'] ?? 'unknown'));
                    error_log("[NATS] Cipher name: ".($meta['crypto']['cipher_name'] ?? 'unknown'));
                }
            }

            // Get peer certificate info if available
            $cert = @stream_context_get_params($this->socket);
            if ($cert && isset($cert['options']['ssl']['peer_certificate'])) {
                $certInfo = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
                error_log("[NATS] Certificate subject: ".($certInfo['name'] ?? 'unknown'));
            }
        }
    }


    private function connectWithTcp(): void
    {
        if ($this->debug) {
            error_log("[NATS] Starting plain TCP connection");
        }

        $address = "tcp://{$this->host}:{$this->port}";
        $this->socket = stream_socket_client($address, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            throw new \RuntimeException("TCP connection failed: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->socket, false);

        // Read server INFO
        $info = $this->readLine(2);
        if (strpos($info, 'INFO ') !== 0) {
            throw new \RuntimeException("Invalid server response: {$info}");
        }

        $this->completeAuthentication();
        $this->connected = true;
    }

    private function completeAuthentication(): void
    {
        // Send CONNECT command with authentication
        $connectData = [
            'verbose'  => $this->debug,
            'pedantic' => false,
            'lang'     => 'php',
            'version'  => '1.0.0',
        ];

        if ($this->token) {
            $connectData['auth_token'] = $this->token;
        } elseif ($this->user && $this->pass) {
            $connectData['user'] = $this->user;
            $connectData['pass'] = $this->pass;
        }

        $connectJson = json_encode($connectData);
        $this->write("CONNECT {$connectJson}\r\n");

        // Read response - might be +OK or -ERR
        $response = $this->readLine(5);

        if ($this->debug) {
            error_log("[NATS] CONNECT response: {$response}");
        }

        if (strpos($response, '-ERR') === 0) {
            // Parse error message
            $errorMsg = substr($response, 5);
            throw new \RuntimeException("Authentication failed: {$errorMsg}");
        }

        // Send PING to verify connection
        $this->write("PING\r\n");
        $pong = $this->readLine(2);

        if ($pong !== 'PONG') {
            if ($this->debug) {
                error_log("[NATS] Expected PONG, got: {$pong}");
            }
            // Not fatal, but log it
        } elseif ($this->debug) {
            error_log("[NATS] PING/PONG successful");
        }
    }

    private function readLine(int $timeout = 1): string
    {
        $line = '';
        $start = microtime(true);
        $bufferSize = 8192;

        while (true) {
            // Check buffer first
            if ($this->buffer !== '') {
                $pos = strpos($this->buffer, "\r\n");
                if ($pos !== false) {
                    $line = substr($this->buffer, 0, $pos);
                    $this->buffer = substr($this->buffer, $pos + 2);

                    return $line;
                }
            }

            // Try to read from socket
            $read = [$this->socket];
            $write = null;
            $except = null;

            // Wait for data to be available
            $changed = stream_select($read, $write, $except, 0, 100000); // 100ms

            if ($changed > 0) {
                // Data is available, read it
                $data = fread($this->socket, $bufferSize);

                if ($data === false || $data === '') {
                    // Connection closed or error
                    if (feof($this->socket)) {
                        throw new \RuntimeException("Connection closed by server");
                    }
                    break;
                }

                $this->buffer .= $data;
                $this->lastActivity = time();
            }

            // Check timeout
            if ((microtime(true) - $start) > $timeout) {
                break;
            }
        }

        // If we have partial data without \r\n, return what we have
        if ($this->buffer !== '') {
            $line = $this->buffer;
            $this->buffer = '';

            return $line;
        }

        return '';
    }

    private function write(string $data): void
    {
        $written = fwrite($this->socket, $data);
        if ($written === false) {
            throw new \RuntimeException("Failed to write to socket");
        }
        $this->lastActivity = time();
    }

    public function publish(string $subject, string $payload, string $replyTo = null): void
    {
        if (!$this->connected) {
            $this->connect();
        }

        $cmd = "PUB {$subject}";
        if ($replyTo) {
            $cmd .= " {$replyTo}";
        }
        $cmd .= " ".strlen($payload)."\r\n{$payload}\r\n";

        $this->write($cmd);
    }

    // ... rest of the methods (subscribe, unsubscribe, close, etc.) ...

    public function getConnectionInfo(): array
    {
        $meta = $this->socketMeta;
        if ($this->socket && is_resource($this->socket)) {
            $meta = stream_get_meta_data($this->socket);
        }

        return [
            'connected'    => $this->connected,
            'host'         => $this->host,
            'port'         => $this->port,
            'tls_enabled'  => $this->tlsConfig['enabled'] ?? false,
            'use_starttls' => $this->useStartTls,
            'socket_type'  => $meta['wrapper_type'] ?? 'unknown',
            'stream_type'  => $meta['stream_type'] ?? 'unknown',
        ];
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->socket && is_resource($this->socket);
    }

    public function close(): void
    {
        $this->shouldStop = true;
        $this->connected = false;

        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}