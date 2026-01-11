<?php

namespace Mwangaben\NatsBroadcaster;

use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;
use Nats\Client;
use Nats\ConnectionOptions;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Mwangaben\NatsBroadcaster\Console\Commands\InstallCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsInfoCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsSubscribeCommand;

class NatsServiceProvider extends BaseServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nats-broadcasting.php' => config_path('nats-broadcasting.php'),
            ], 'nats-config');

            $this->publishes([
                __DIR__.'/../config/nats-broadcasting.php' => config_path('nats-broadcasting.php'),
            ], 'config');
        }

        $this->bootBroadcaster();
        $this->bootCommands();
    }

    protected function bootBroadcaster(): void
    {
        $this->app->make(BroadcastManager::class)->extend('nats', function ($app, array $config) {
            $broadcaster = new NatsBroadcaster($config);

            // Register as singleton for facade access
            if (! $this->app->bound('nats.broadcaster')) {
                $this->app->singleton('nats.broadcaster', fn() => $broadcaster);
            }

            return $broadcaster;
        });
    }

    protected function bootCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                NatsInfoCommand::class,
                NatsSubscribeCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/nats-broadcasting.php', 'broadcasting.connections.nats'
        );

        $this->app->singleton('nats.connection', function ($app) {
            $config = $app['config']['broadcasting.connections.nats'];

            $options = new ConnectionOptions();
            $options->setHost($config['host'] ?? 'localhost');
            $options->setPort($config['port'] ?? 4222);

            if (isset($config['user']) && isset($config['pass'])) {
                $options->setUser($config['user']);
                $options->setPass($config['pass']);
            }

            if (isset($config['token'])) {
                $options->setToken($config['token']);
            }

            $options->setReconnect($config['reconnect'] ?? true);
            $options->setTimeout($config['timeout'] ?? 5);
            $options->setVerbose($config['verbose'] ?? false);

            // TLS Configuration
            if ($config['tls'] ?? false) {
                $options->setSecure(true);
                if (isset($config['tls_cert'])) {
                    $options->setCertFile($config['tls_cert']);
                }
                if (isset($config['tls_key'])) {
                    $options->setKeyFile($config['tls_key']);
                }
                if (isset($config['tls_ca'])) {
                    $options->setCaFile($config['tls_ca']);
                }
            }

            return new Client($options);
        });
    }
}