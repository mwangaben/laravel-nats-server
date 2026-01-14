<?php
// config/nats-broadcasting.php
return [
    'default' => env('NATS_BROADCAST_CONNECTION', 'nats'),

    'connections' => [
        'nats' => [
            'driver' => 'nats',
            'host' => env('NATS_HOST', 'localhost'),
            'port' => env('NATS_PORT', 4222),
            'user' => env('NATS_USER'),
            'pass' => env('NATS_PASS'),
            'token' => env('NATS_TOKEN'),
            'timeout' => env('NATS_TIMEOUT', 5),
            'reconnect' => env('NATS_RECONNECT', true),
            'reconnect_time_wait' => env('NATS_RECONNECT_TIME_WAIT', 2),
            'prefix' => env('NATS_PREFIX', ''),
            'debug' => env('NATS_DEBUG', false),
            'verbose' => env('NATS_VERBOSE', false),
            'pedantic' => env('NATS_PEDANTIC', false),

            // TLS/SSL Configuration
            'tls' => [
                'enabled' => env('NATS_TLS_ENABLED', false),
                'cert_file' => env('NATS_TLS_CERT_FILE'),
                'key_file' => env('NATS_TLS_KEY_FILE'),
                'ca_file' => env('NATS_TLS_CA_FILE'),
                'verify_peer' => env('NATS_TLS_VERIFY_PEER', true),
                'verify_peer_name' => env('NATS_TLS_VERIFY_PEER_NAME', true),
                'allow_self_signed' => env('NATS_TLS_ALLOW_SELF_SIGNED', false),
            ],

            // Advanced TLS Configuration
            'tls_context' => [
                'ciphers' => env('NATS_TLS_CIPHERS', 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256'),
                'verify_depth' => env('NATS_TLS_VERIFY_DEPTH', 5),
                'peer_fingerprint' => env('NATS_TLS_PEER_FINGERPRINT'),
            ],

            // JetStream Configuration
            'jetstream' => env('NATS_JETSTREAM', false),
            'stream' => env('NATS_STREAM', 'broadcast'),
            'consumer' => env('NATS_CONSUMER', 'broadcast-consumer'),
        ],
    ],

    'options' => [
        'queue' => env('NATS_QUEUE', 'default'),
        'retry_limit' => env('NATS_RETRY_LIMIT', 3),
        'retry_delay' => env('NATS_RETRY_DELAY', 100),
        'batch_size' => env('NATS_BATCH_SIZE', 100),
        'max_payload' => env('NATS_MAX_PAYLOAD', 1048576),
    ],

    'auth' => [
        'jwt' => env('NATS_JWT'),
        'nkey' => env('NATS_NKEY'),
        'seed' => env('NATS_SEED'),
        'credentials' => env('NATS_CREDENTIALS_FILE'),
    ],
];