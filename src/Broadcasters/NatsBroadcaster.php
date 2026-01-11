<?php

namespace Mwangaben\NatsBroadcaster\Broadcasters;

use Mwangaben\NatsBroadcaster\Nats\PatchedClient;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Exception;

class NatsBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    protected PatchedClient $client;
    protected array $config;
    protected array $subscriptions = [];
    protected bool $debug;
    protected bool $connected = false;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->debug = $config['debug'] ?? false;

        // Create patched client
        $clientConfig = [
            'host' => $config['host'] ?? 'localhost',
            'port' => $config['port'] ?? 4222,
            'user' => $config['user'] ?? null,
            'pass' => $config['pass'] ?? null,
            'token' => $config['token'] ?? null,
            'timeout' => $config['timeout'] ?? 5,
            'debug' => $this->debug,
        ];

        $this->client = new PatchedClient($clientConfig);
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

            if ($this->debug && class_exists('Log')) {
                \Log::info('NATS Broadcasting', [
                    'subject' => $subject,
                    'message' => $message
                ]);
            }

            try {
                $this->client->publish($subject, json_encode($message));
            } catch (Exception $e) {
                // Try to report if available
                if (function_exists('report')) {
                    report($e);
                }

                if ($this->debug && class_exists('Log')) {
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
                    if (function_exists('report')) {
                        report($retryException);
                    }
                }
            }
        }
    }

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

//    protected function getSubjectFromChannel($channel): string
//    {
//        if (is_object($channel) && method_exists($channel, 'name')) {
//            $channel = $channel->name();
//        }
//
//        $subject = $channel;
//        $channelType = null;
//
//        // Determine channel type and strip prefix
//        if (str_starts_with($subject, 'private-encrypted-')) {
//            $channelType = 'private.encrypted.';
//            $subject = substr($subject, strlen('private-encrypted-'));
//        } elseif (str_starts_with($subject, 'private-')) {
//            $channelType = 'private.';
//            $subject = substr($subject, strlen('private-'));
//        } elseif (str_starts_with($subject, 'presence-')) {
//            $channelType = 'presence.';
//            $subject = substr($subject, strlen('presence-'));
//        }
//        // Note: No handling for 'public-' because public channels have no prefix in Laravel
//
//        // Convert dots to hyphens for NATS subject hierarchy
//        $subject = str_replace('.', '-', $subject);
//
//        // Add channel type back if it was a special channel
//        if ($channelType !== null) {
//            $subject = $channelType . $subject;
//        }
//
//        // Add application prefix for non-special channels
//        $prefix = $this->config['prefix'] ?? '';
//        if ($prefix && $channelType === null) {
//            // Skip prefix if the first part of the subject already matches the prefix
//            $firstPart = explode('-', $subject)[0];
//            if (strtolower($firstPart) !== strtolower($prefix) && !str_starts_with($subject, $prefix . '.')) {
//                $subject = $prefix . '.' . $subject;
//            }
//        }
//
//        return $subject;
//    }

//    protected function getSubjectFromChannel($channel): string
//    {
//        if (is_object($channel) && method_exists($channel, 'name')) {
//            $channel = $channel->name();
//        }
//
//        // First, handle the special channel prefixes
//        $subject = $channel;
//
//        // Check for private-encrypted- first (longest match)
//        if (str_starts_with($subject, 'private-encrypted-')) {
//            $subject = 'private.encrypted.' . substr($subject, strlen('private-encrypted-'));
//        }
//        // Then check for private-
//        elseif (str_starts_with($subject, 'private-')) {
//            $subject = 'private.' . substr($subject, strlen('private-'));
//        }
//        // Then check for presence-
//        elseif (str_starts_with($subject, 'presence-')) {
//            $subject = 'presence.' . substr($subject, strlen('presence-'));
//        }
//
//        // Now convert dots to hyphens (for NATS subject hierarchy)
//        // But only if we haven't already processed it as a special channel
//        if ($subject === $channel) {
//            // No special prefix was found, just convert dots to hyphens
//            $subject = str_replace('.', '-', $subject);
//        } else {
//            // Special channel found - convert dots to hyphens in the rest of the subject
//            // Find where the channel type ends (after 'private.', 'presence.', or 'private.encrypted.')
//            if (str_starts_with($subject, 'private.encrypted.')) {
//                $prefix = 'private.encrypted.';
//            } elseif (str_starts_with($subject, 'private.')) {
//                $prefix = 'private.';
//            } else { // presence.
//                $prefix = 'presence.';
//            }
//
//            $rest = substr($subject, strlen($prefix));
//            $rest = str_replace('.', '-', $rest);
//            $subject = $prefix . $rest;
//        }
//
//        // Add prefix if configured (AFTER all conversions)
//        $prefix = $this->config['prefix'] ?? '';
//        if ($prefix && !empty($subject)) {
//            // Check if it's already a special channel type
//            $specialTypes = ['private.', 'presence.', 'private.encrypted.'];
//            $isSpecialChannel = false;
//            foreach ($specialTypes as $type) {
//                if (str_starts_with($subject, $type)) {
//                    $isSpecialChannel = true;
//                    break;
//                }
//            }
//
//            // Only add prefix to non-special channels
//            if (!$isSpecialChannel && !str_starts_with($subject, $prefix . '.')) {
//                $subject = $prefix . '.' . $subject;
//            }
//        }
//
//        return $subject;
//    }

//    protected function getSubjectFromChannel($channel): string
//    {
//        if (is_object($channel) && method_exists($channel, 'name')) {
//            $channel = $channel->name();
//        }
//
//        // Convert Laravel channel format to NATS subject format
//        // Replace dots with hyphens for NATS subject hierarchy
//        $subject = str_replace(
//            ['.', 'private-', 'presence-', 'private-encrypted-'],
//            ['-', 'private.', 'presence.', 'private.encrypted.'],
//            $channel
//        );
//
//        // Add prefix if configured (AFTER conversion)
//        $prefix = $this->config['prefix'] ?? '';
//        if ($prefix && !str_starts_with($subject, $prefix . '.')) {
//            // Check if it's already a special channel type
//            $specialTypes = ['private.', 'presence.', 'private.encrypted.'];
//            $isSpecialChannel = false;
//            foreach ($specialTypes as $type) {
//                if (str_starts_with($subject, $type)) {
//                    $isSpecialChannel = true;
//                    break;
//                }
//            }
//
//            if (!$isSpecialChannel) {
//                $subject = $prefix . '.' . $subject;
//            }
//        }
//
//        return $subject;
//    }

//    protected function getSubjectFromChannel($channel): string
//    {
//        if (is_object($channel) && method_exists($channel, 'name')) {
//            $channel = $channel->name();
//        }
//
//        // Add prefix if configured
//        $prefix = $this->config['prefix'] ?? '';
//        if ($prefix) {
//            $channel = $prefix . '.' . $channel;
//        }
//
//        // Convert Laravel channel format to NATS subject format
//        // Replace dots with hyphens for NATS subject hierarchy
//        $subject = str_replace(
//            ['.', 'private-', 'presence-', 'private-encrypted-'],
//            ['-', 'private.', 'presence.', 'private.encrypted.'],
//            $channel
//        );
//
//        return $subject;
//    }
//
    public function connect(): void
    {
        try {
            $this->client->connect();
            $this->connected = true;

            if ($this->debug && class_exists('Log')) {
                \Log::info('NATS Connected successfully');
            }
        } catch (Exception $e) {
            if (function_exists('report')) {
                report($e);
            }

            if ($this->debug && class_exists('Log')) {
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

                if ($this->debug && class_exists('Log')) {
                    \Log::info('NATS Disconnected');
                }
            } catch (Exception $e) {
                // Ignore disconnect errors
            }
        }
    }

    public function getClient(): PatchedClient
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
            // Note: The patched client handles subscriptions differently
            // You might need to update the PatchedClient to support this interface
            $this->client->subscribe($subject, function ($message) use ($handler, $subject) {
                try {
                    $data = json_decode($message['payload'] ?? $message, true);
                    $handler($data);
                } catch (Exception $e) {
                    if ($this->debug && class_exists('Log')) {
                        \Log::error('NATS Message handler error', [
                            'error' => $e->getMessage(),
                            'subject' => $subject
                        ]);
                    }
                }
            });

            $this->subscriptions[$subject] = true;

            if ($this->debug && class_exists('Log')) {
                \Log::info('NATS Subscribed to subject', ['subject' => $subject]);
            }
        } catch (Exception $e) {
            if (function_exists('report')) {
                report($e);
            }

            if ($this->debug && class_exists('Log')) {
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
                // The patched client might need an unsubscribe method
                unset($this->subscriptions[$subject]);

                if ($this->debug && class_exists('Log')) {
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