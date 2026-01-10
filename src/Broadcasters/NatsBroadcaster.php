<?php

namespace Mwangaben\NatsBroadcaster\Broadcasters;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Exception;

class NatsBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    protected Client $client;
    protected array $config;
    protected array $subscriptions = [];
    protected bool $debug;
    protected bool $connected = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->debug = $config['debug'] ?? false;

        // Ensure port is integer
        $port = isset($config['port']) ? (int) $config['port'] : 4222;
        
        $configuration = new Configuration([
            'host' => $config['host'] ?? 'localhost',
            'port' => $port,
            'user' => $config['user'] ?? null,
            'pass' => $config['pass'] ?? null,
            'token' => $config['token'] ?? null,
            'reconnect' => $config['reconnect'] ?? true,
            'timeout' => isset($config['timeout']) ? (int) $config['timeout'] : 5,
            'verbose' => $this->debug,
            'lang' => 'php',
        ]);

        $this->client = new Client($configuration);
    }

    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if ($this->isGuardedChannel($request->channel_name) &&
            ! $this->retrieveUser($request, $channelName)) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    public function validAuthenticationResponse($request, $result)
    {
        if (Str::startsWith($request->channel_name, 'private')) {
            return $this->privateAuthenticationResponse($request, $result);
        }

        $channelName = $this->normalizeChannelName($request->channel_name);

        return $this->presenceAuthenticationResponse($request, $result, $channelName);
    }

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

    protected function generateToken(string $channel, $userId): string
    {
        $string = $channel . ':' . $userId . ':' . time();
        return hash_hmac('sha256', $string, config('app.key'));
    }

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
                report($e);
                
                if ($this->debug) {
                    \Log::error('NATS Broadcast failed', [
                        'error' => $e->getMessage(),
                        'subject' => $subject
                    ]);
                }
                
                // Try to reconnect and resend
                try {
                    $this->reconnect();
                    $this->client->publish($subject, json_encode($message));
                } catch (Exception $retryException) {
                    report($retryException);
                }
            }
        }
    }

    protected function getSubjectFromChannel($channel): string
    {
        if (is_object($channel) && method_exists($channel, 'name')) {
            $channel = $channel->name();
        }

        // Add prefix if configured
        $prefix = $this->config['prefix'] ?? '';
        if ($prefix) {
            $channel = $prefix . '.' . $channel;
        }

        // Convert Laravel channel format to NATS subject format
        // Replace dots with hyphens for NATS subject hierarchy
        $subject = str_replace(
            ['.', 'private-', 'presence-', 'private-encrypted-'],
            ['-', 'private.', 'presence.', 'private.encrypted.'],
            $channel
        );

        return $subject;
    }

    public function connect(): void
    {
        try {
            $this->client->connect();
            $this->connected = true;
            
            if ($this->debug) {
                \Log::info('NATS Connected successfully');
            }
        } catch (Exception $e) {
            report($e);
            
            if ($this->debug) {
                \Log::error('NATS Connection failed', ['error' => $e->getMessage()]);
            }
            
            throw $e;
        }
    }

    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

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

    public function getClient(): Client
    {
        return $this->client;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function subscribe(string $subject, callable $handler): void
    {
        if (! $this->connected) {
            $this->connect();
        }

        try {
            $subscription = $this->client->subscribe($subject);
            $subscription->handler(function ($message) use ($handler) {
                try {
                    $data = json_decode($message->getBody(), true);
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

            $this->subscriptions[$subject] = $subscription;
            
            if ($this->debug) {
                \Log::info('NATS Subscribed to subject', ['subject' => $subject]);
            }
        } catch (Exception $e) {
            report($e);
            
            if ($this->debug) {
                \Log::error('NATS Subscription failed', [
                    'error' => $e->getMessage(),
                    'subject' => $subject
                ]);
            }
        }
    }

    public function unsubscribe(string $subject): void
    {
        if (isset($this->subscriptions[$subject])) {
            try {
                $this->subscriptions[$subject]->unsubscribe();
                unset($this->subscriptions[$subject]);
                
                if ($this->debug) {
                    \Log::info('NATS Unsubscribed from subject', ['subject' => $subject]);
                }
            } catch (Exception $e) {
                // Ignore unsubscribe errors
            }
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}