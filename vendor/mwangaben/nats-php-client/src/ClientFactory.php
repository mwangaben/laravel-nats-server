<?php

namespace Nats;

class ClientFactory
{
    public static function createFromUrl(string $url): Client
    {
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new \InvalidArgumentException("Invalid URL: $url");
        }

        $options = ConnectionOptions::create();

        // Set host and port
        $options->setHost($parsed['host'] ?? 'localhost');
        $options->setPort($parsed['port'] ?? 4222);

        // Handle authentication
        if (isset($parsed['user']) && isset($parsed['pass'])) {
            $options->setUser($parsed['user']);
            $options->setPassword($parsed['pass']);
        }

        // Handle query parameters as options
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);

            foreach ($queryParams as $key => $value) {
                $method = 'set' . ucfirst($key);
                if (method_exists($options, $method)) {
                    // Convert string values to appropriate types
                    if (in_array($key, ['port', 'maxReconnectAttempts', 'reconnectWait'])) {
                        $value = (int) $value;
                    } elseif (in_array($key, ['timeout'])) {
                        $value = (float) $value;
                    } elseif (in_array($key, ['verbose', 'pedantic', 'reconnect', 'debug', 'tlsVerify'])) {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }

                    $options->$method($value);
                }
            }
        }

        return new Client($options);
    }

    public static function createFromArray(array $config): Client
    {
        $options = ConnectionOptions::create();

        foreach ($config as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($options, $method)) {
                $options->$method($value);
            }
        }

        return new Client($options);
    }
}
