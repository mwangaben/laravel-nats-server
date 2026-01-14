<?php
namespace Mwangaben\NatsBroadcaster\Broadcasters;

use Exception;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mwangaben\NatsBroadcaster\Nats\PatchedClient;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class NatsBroadcaster extends Broadcaster
{

    use UsePusherChannelConventions;

    protected PatchedClient $client;
    protected array $config;
    protected array $subscriptions = [];
    protected bool $debug;
    protected bool $connected = false;



    public function __construct(array $config = [])
    {
        // DEBUG: Log what we receive
        \Log::debug('NatsBroadcaster - Raw config received:', $config);

        // If empty config, try to get it from broadcasting config
        if (empty($config)) {
            $config = config('broadcasting.connections.nats', []);
            \Log::debug('NatsBroadcaster - Config from broadcasting:', $config);
        }

        // CRITICAL FIX: Check for tls boolean first
        if (isset($config['tls']) && is_bool($config['tls'])) {
            $config['tls'] = ['enabled' => $config['tls']];
        }

        // Set defaults for missing configuration
        $this->config = $this->mergeConfigWithDefaults($config);
        $this->debug = $this->config['debug'] ?? false;

        // DEBUG
        \Log::debug('NatsBroadcaster - Merged config:', $this->config);

        // FORCE DEBUG: Log TLS configuration specifically
        \Log::debug('NatsBroadcaster - TLS config check:', [
            'tls_in_config' => isset($config['tls']),
            'tls_is_array' => isset($config['tls']) && is_array($config['tls']),
            'tls_enabled_raw' => $config['tls']['enabled'] ?? 'not set',
            'tls_enabled_merged' => $this->config['tls']['enabled'] ?? 'not set',
        ]);

        // Create patched client
        $clientConfig = [
            'host' => $this->config['host'] ?? 'localhost',
            'port' => $this->config['port'] ?? 4222,
            'user' => $this->config['user'] ?? null,
            'pass' => $this->config['pass'] ?? null,
            'token' => $this->config['token'] ?? null,
            'timeout' => $this->config['timeout'] ?? 5,
            'debug' => $this->debug,
        ];

        // FIX: Always check TLS from original config, not merged
        $tlsEnabled = false;
        if (isset($config['tls'])) {
            if (is_bool($config['tls'])) {
                $tlsEnabled = $config['tls'];
            } elseif (is_array($config['tls']) && isset($config['tls']['enabled'])) {
                $tlsEnabled = filter_var($config['tls']['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($tlsEnabled) {
            \Log::debug('NatsBroadcaster - TLS ENABLED from config');
            $clientConfig['tls'] = [
                'enabled' => true,
                'verify_peer' => $config['tls']['verify_peer'] ?? false,
                'verify_peer_name' => $config['tls']['verify_peer_name'] ?? false,
                'allow_self_signed' => $config['tls']['allow_self_signed'] ?? true,
            ];

            // Add certificate files if specified
            if (!empty($config['tls']['cert_file'])) {
                $clientConfig['tls']['cert_file'] = $config['tls']['cert_file'];
            }
            if (!empty($config['tls']['key_file'])) {
                $clientConfig['tls']['key_file'] = $config['tls']['key_file'];
            }
            if (!empty($config['tls']['ca_file'])) {
                $clientConfig['tls']['ca_file'] = $config['tls']['ca_file'];
            }
        } else {
            \Log::debug('NatsBroadcaster - TLS DISABLED');
            $clientConfig['tls'] = ['enabled' => false];
        }

        // Add tls_context
        if (isset($this->config['tls_context']) && is_array($this->config['tls_context'])) {
            $clientConfig['tls_context'] = $this->config['tls_context'];
        }

        \Log::debug('NatsBroadcaster - Final client config:', [
            'host' => $clientConfig['host'],
            'port' => $clientConfig['port'],
            'tls_enabled' => $clientConfig['tls']['enabled'] ?? false,
            'tls_config' => $clientConfig['tls'],
        ]);

        $this->client = new PatchedClient($clientConfig);
    }


    private function mergeConfigWithDefaults(array $config): array
    {
        $defaults = [
            'host' => 'localhost',
            'port' => 4222,
            'user' => null,
            'pass' => null,
            'token' => null,
            'timeout' => 5,
            'reconnect' => true,
            'reconnect_time_wait' => 2,
            'prefix' => '',
            'debug' => false,
            'verbose' => false,
            'pedantic' => false,
            'tls' => [
                'enabled' => false,
                'cert_file' => null,
                'key_file' => null,
                'ca_file' => null,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
            'tls_context' => [
                'ciphers' => 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES128-GCM-SHA256',
                'verify_depth' => 5,
                'peer_fingerprint' => null,
            ],
            'jetstream' => false,
            'stream' => 'broadcast',
            'consumer' => 'broadcast-consumer',
        ];

        $merged = array_merge($defaults, $config);

        // CRITICAL FIX: Don't merge TLS config array if it exists in $config
        // This preserves the enabled: true from the config
        if (isset($config['tls']) && is_array($config['tls'])) {
            // Keep the TLS config from $config, only fill in missing values from defaults
            $merged['tls'] = array_merge($defaults['tls'], $config['tls']);

            // Ensure boolean value for enabled
            if (isset($merged['tls']['enabled'])) {
                $merged['tls']['enabled'] = filter_var($merged['tls']['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Merge TLS context if present
        if (isset($config['tls_context']) && is_array($config['tls_context'])) {
            $merged['tls_context'] = array_merge($defaults['tls_context'], $config['tls_context']);
        }

        return $merged;
    }

    public function getConnectionInfo(): array
    {
        if (method_exists($this->client, 'getConnectionInfo')) {
            return $this->client->getConnectionInfo();
        }

        return [
            'connected' => $this->connected,
            'config' => [
                'host' => $this->config['host'] ?? 'localhost',
                'port' => $this->config['port'] ?? 4222,
                'tls_enabled' => $this->config['tls']['enabled'] ?? false,
            ],
        ];
    }







    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     *
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if ($this->isGuardedChannel($request->channel_name) &&
            ! $this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel(
            $request, $channelName
        );
    }

    /**
     * Return the valid authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        if (Str::startsWith($request->channel_name, 'private')) {
            return $this->privateAuthenticationResponse($request, $result);
        }

        $channelName = $this->normalizeChannelName($request->channel_name);

        return $this->presenceAuthenticationResponse(
            $request, $result, $channelName
        );
    }

    /**
     * Return the valid private authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    protected function privateAuthenticationResponse($request, $result)
    {
        $user = $request->user();

        if ($user && is_bool($result)) {
            return json_encode([
                'auth' => $this->generateToken($request->channel_name, $user->getAuthIdentifier())
            ]);
        }

        return json_encode([
            'auth' => $this->generateToken($request->channel_name, $user->getAuthIdentifier()),
            'channel_data' => [
                'user_id' => $user->getAuthIdentifier(),
                'user_info' => $result,
            ]
        ]);
    }

    /**
     * Return the valid presence authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @param  string  $channelName
     * @return mixed
     */
    protected function presenceAuthenticationResponse($request, $result, $channelName)
    {
        $user = $request->user();

        if (is_bool($result)) {
            return json_encode([
                'auth' => $this->generateToken($channelName, $user->getAuthIdentifier()),
                'channel_data' => [
                    'user_id' => $user->getAuthIdentifier(),
                    'user_info' => [],
                ]
            ]);
        }

        return json_encode([
            'auth' => $this->generateToken($channelName, $user->getAuthIdentifier()),
            'channel_data' => [
                'user_id' => $user->getAuthIdentifier(),
                'user_info' => $result,
            ]
        ]);
    }

    /**
     * Generate a token for the given channel and user ID.
     *
     * @param  string  $channel
     * @param  mixed  $userId
     * @return string
     */
    protected function generateToken(string $channel, $userId): string
    {
        $string = $channel . ':' . $userId . ':' . time();
        return hash_hmac('sha256', $string, config('app.key'));
    }

    /**
     * Broadcast the given event.
     *
     * @param  array  $channels
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function broadcast(array $channels, $event, array $payload = [])
    {
        if (! $this->connected) {
            $this->connect();
        }

        $socket = Arr::pull($payload, 'socket');

        foreach ($channels as $channel) {
            $subject = $this->getSubjectFromChannel($channel);

            $message = [
                'event' => $event,
                'data' => $payload,
                'socket' => $socket,
                'channel' => $channel,
                'timestamp' => now()->toISOString(),
            ];

            if ($this->debug) {
                \Log::info('NATS Broadcasting', [
                    'subject' => $subject,
                    'message' => $message
                ]);
            }

            try {
                $this->client->publish($subject, json_encode($message));
            } catch (Exception $e) {
                \Log::error('NATS Broadcast failed', [
                    'error' => $e->getMessage(),
                    'subject' => $subject,
                    'event' => $event,
                    'channel' => $channel,
                ]);

                // Try to reconnect and resend
                try {
                    $this->reconnect();
                    $this->client->publish($subject, json_encode($message));
                } catch (Exception $retryException) {
                    \Log::error('NATS Broadcast retry failed', [
                        'error' => $retryException->getMessage(),
                        'subject' => $subject,
                    ]);
                }
            }
        }
    }

    /**
     * Get the NATS subject from the Laravel channel name.
     *
     * @param  string  $channel
     * @return string
     */
    protected function getSubjectFromChannel($channel): string
    {
        if (is_object($channel) && method_exists($channel, 'name')) {
            $channel = $channel->name();
        }

        $subject = $channel;
        $channelType = null;

        // Determine channel type and strip Laravel prefix
        if (str_starts_with($subject, 'private-encrypted-')) {
            $channelType = 'private.encrypted.';
            $subject = substr($subject, strlen('private-encrypted-'));
        } elseif (str_starts_with($subject, 'private-')) {
            $channelType = 'private.';
            $subject = substr($subject, strlen('private-'));
        } elseif (str_starts_with($subject, 'presence-')) {
            $channelType = 'presence.';
            $subject = substr($subject, strlen('presence-'));
        }

        // Convert dots to hyphens for NATS subject hierarchy
        $subject = str_replace('.', '-', $subject);

        // Add channel type back if it was a special channel
        if ($channelType !== null) {
            $subject = $channelType . $subject;
        }

        return $subject;
    }

    /**
     * Connect to the NATS server.
     *
     * @return void
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        try {
            $this->client->connect();
            $this->connected = true;

            if ($this->debug) {
                \Log::info('NATS Connected successfully');
            }
        } catch (Exception $e) {
            \Log::error('NATS Connection failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Reconnect to the NATS server.
     *
     * @return void
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Disconnect from the NATS server.
     *
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->connected) {
            try {
                $this->client->close();
                $this->connected = false;

                if ($this->debug) {
                    \Log::info('NATS Disconnected');
                }
            } catch (Exception $e) {
                // Ignore disconnect errors
            }
        }
    }

    /**
     * Get the NATS client instance.
     *
     * @return PatchedClient
     */
    public function getClient(): PatchedClient
    {
        return $this->client;
    }

    /**
     * Check if connected to NATS server.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Subscribe to a NATS subject.
     *
     * @param  string  $subject
     * @param  callable  $handler
     * @return void
     */
    public function subscribe(string $subject, callable $handler): void
    {
        if (! $this->connected) {
            $this->connect();
        }

        try {
            $this->client->subscribe($subject, function ($message) use ($handler, $subject) {
                try {
                    $data = json_decode($message['payload'] ?? $message, true);
                    $handler($data);
                } catch (Exception $e) {
                    if ($this->debug) {
                        \Log::error('NATS Message handler error', [
                            'error' => $e->getMessage(),
                            'subject' => $subject
                        ]);
                    }
                }
            });

            $this->subscriptions[$subject] = true;

            if ($this->debug) {
                \Log::info('NATS Subscribed to subject', ['subject' => $subject]);
            }
        } catch (Exception $e) {
            \Log::error('NATS Subscription failed', [
                'error' => $e->getMessage(),
                'subject' => $subject
            ]);
        }
    }

    /**
     * Unsubscribe from a NATS subject.
     *
     * @param  string  $subject
     * @return void
     */
    public function unsubscribe(string $subject): void
    {
        if (isset($this->subscriptions[$subject])) {
            try {
                unset($this->subscriptions[$subject]);

                if ($this->debug) {
                    \Log::info('NATS Unsubscribed from subject', ['subject' => $subject]);
                }
            } catch (Exception $e) {
                // Ignore unsubscribe errors
            }
        }
    }

    /**
     * Destructor to ensure clean disconnect.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}