<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NATS Broadcasting Default Connection
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcasting connection that gets used
    | when broadcasting events. You may set this to any of the connections
    | defined in the "connections" array below.
    |
    */

    'default' => env('NATS_BROADCAST_CONNECTION', 'nats'),

    /*
    |--------------------------------------------------------------------------
    | NATS Broadcasting Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the NATS broadcasting connections that will
    | be used to broadcast events to other systems or over websockets. Each
    | connection has its own configuration and authentication options.
    |
    | Available Drivers: "nats"
    |
    */

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
            'tls' => env('NATS_TLS', false),
            'tls_cert' => env('NATS_TLS_CERT'),
            'tls_key' => env('NATS_TLS_KEY'),
            'tls_ca' => env('NATS_TLS_CA'),

            // JetStream Configuration
            'jetstream' => env('NATS_JETSTREAM', false),
            'stream' => env('NATS_STREAM', 'broadcast'),
            'consumer' => env('NATS_CONSUMER', 'broadcast-consumer'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NATS Broadcasting Options
    |--------------------------------------------------------------------------
    |
    | Here you may configure additional broadcasting options for NATS.
    |
    */

    'options' => [
        'queue' => env('NATS_QUEUE', 'default'),
        'retry_limit' => env('NATS_RETRY_LIMIT', 3),
        'retry_delay' => env('NATS_RETRY_DELAY', 100),
        'batch_size' => env('NATS_BATCH_SIZE', 100),
        'max_payload' => env('NATS_MAX_PAYLOAD', 1048576), // 1MB
    ],

    /*
    |--------------------------------------------------------------------------
    | NATS Authentication
    |--------------------------------------------------------------------------
    |
    | Configure authentication options for NATS.
    |
    */

    'auth' => [
        'jwt' => env('NATS_JWT'),
        'nkey' => env('NATS_NKEY'),
        'seed' => env('NATS_SEED'),
        'credentials' => env('NATS_CREDENTIALS_FILE'),
    ],
];