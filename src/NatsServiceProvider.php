<?php
namespace Mwangaben\NatsBroadcaster;

use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Mwangaben\NatsBroadcaster\Console\Commands\InstallCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsDebugCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsInfoCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsSubscribeCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsTestTlsCommand;
use Mwangaben\NatsBroadcaster\Console\Commands\NatsTlsCommand;

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
            if (!$app->bound('nats.broadcaster')) {
                $app->singleton('nats.broadcaster', fn() => $broadcaster);
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
                NatsTlsCommand::class,
                NatsDebugCommand::class,
                NatsTestTlsCommand::class// Add this
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/nats-broadcasting.php', 'broadcasting.connections.nats'
        );

        // Register the broadcaster for dependency injection
        $this->app->bind(NatsBroadcaster::class, function ($app) {
            $config = $app['config']['broadcasting.connections.nats'] ?? [];
            return new NatsBroadcaster($config);
        });

        // Also register the facade accessor
        $this->app->singleton('nats.broadcaster', function ($app) {
            $config = $app['config']['broadcasting.connections.nats'] ?? [];
            return new NatsBroadcaster($config);
        });
    }
}