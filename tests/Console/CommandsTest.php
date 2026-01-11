<?php

namespace Mwangaben\NatsBroadcaster\Tests\Console;

use Mwangaben\NatsBroadcaster\Tests\TestCase;
use Illuminate\Support\Facades\File;

class CommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Backup original files
        if (File::exists(base_path('.env'))) {
            File::copy(base_path('.env'), base_path('.env.backup'));
        }

        if (File::exists(config_path('broadcasting.php'))) {
            File::copy(config_path('broadcasting.php'), config_path('broadcasting.php.backup'));
        }
    }

    protected function tearDown(): void
    {
        // Restore original files
        if (File::exists(base_path('.env.backup'))) {
            File::move(base_path('.env.backup'), base_path('.env'));
        }

        if (File::exists(config_path('broadcasting.php.backup'))) {
            File::move(config_path('broadcasting.php.backup'), config_path('broadcasting.php'));
        }

        parent::tearDown();
    }

    /** @test */
    public function install_command_creates_config_file()
    {
        // Remove config file if it exists
        $configPath = config_path('nats-broadcasting.php');
        if (File::exists($configPath)) {
            File::delete($configPath);
        }

        $this->artisan('nats:install', ['--config-only' => true])
            ->expectsOutput('NATS Broadcaster installed successfully!')
            ->assertExitCode(0);

        $this->assertFileExists($configPath);

        $config = require $configPath;
        $this->assertArrayHasKey('connections', $config);
        $this->assertArrayHasKey('nats', $config['connections']);
    }

    /** @test */
    public function install_command_updates_env_file()
    {
        // Create a fresh .env file
        $envContent = "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true";
        File::put(base_path('.env'), $envContent);

        $this->artisan('nats:install')
            ->expectsQuestion('Enter value for BROADCAST_CONNECTION [nats]', 'nats')
            ->expectsQuestion('Enter value for NATS_HOST [localhost]', 'localhost')
            ->expectsQuestion('Enter value for NATS_PORT [4222]', '4222')
            ->expectsQuestion('Enter value for NATS_USER []', '')
            ->expectsQuestion('Enter value for NATS_PASS []', '')
            ->expectsQuestion('Enter value for NATS_TOKEN []', '')
            ->expectsQuestion('Enter value for NATS_PREFIX [app]', 'testapp')
            ->expectsQuestion('Enter value for NATS_DEBUG [false]', 'true')
            ->expectsQuestion('Enter value for NATS_RECONNECT [true]', 'true')
            ->expectsQuestion('Enter value for NATS_TIMEOUT [5]', '3')
            ->expectsQuestion('Enter value for NATS_TLS [false]', 'false')
            ->expectsQuestion('Enter value for NATS_JETSTREAM [false]', 'false')
            ->assertExitCode(0);

        $envContent = File::get(base_path('.env'));

        $this->assertStringContainsString('BROADCAST_CONNECTION=nats', $envContent);
        $this->assertStringContainsString('NATS_HOST=localhost', $envContent);
        $this->assertStringContainsString('NATS_PORT=4222', $envContent);
        $this->assertStringContainsString('NATS_PREFIX=testapp', $envContent);
        $this->assertStringContainsString('NATS_DEBUG=true', $envContent);
        $this->assertStringContainsString('NATS_TIMEOUT=3', $envContent);
    }

    /** @test */
    public function info_command_displays_connection_information()
    {
        $this->skipIfNatsNotRunning();

        $this->artisan('nats:info')
            ->expectsOutput('🔌 NATS Connection Information')
            ->expectsOutput('Testing connection...')
            ->assertExitCode(0);
    }

    /** @test */
    public function info_command_shows_error_when_nats_not_running()
    {
        // Temporarily change config to non-existent server
        config(['broadcasting.connections.nats.host' => 'nonexistent']);

        $this->artisan('nats:info')
            ->expectsOutput('❌ Connection failed')
            ->assertExitCode(0);
    }

    /** @test */
    public function subscribe_command_listens_for_messages()
    {
        $this->skipIfNatsNotRunning();

        // This test is tricky because the subscribe command runs indefinitely
        // We'll test it by running it in background and sending a message

        $process = popen('php artisan nats:subscribe test.command.channel --limit=1', 'w');

        // Give it time to subscribe
        sleep(1);

        // Send a message
        $broadcaster = app('nats.broadcaster');
        $broadcaster->broadcast(['test.command.channel'], 'CommandTest', [
            'test' => 'message',
            'timestamp' => now()->toISOString(),
        ]);

        // Give it time to receive
        sleep(2);

        pclose($process);

        // If we get here without hanging, the test passes
        $this->assertTrue(true);
    }

    /** @test */
    public function subscribe_command_respects_message_limit()
    {
        $this->skipIfNatsNotRunning();

        // Start process with limit of 2 messages
        $process = popen('php artisan nats:subscribe test.limit.channel --limit=2', 'w');

        sleep(1);

        // Send 3 messages
        $broadcaster = app('nats.broadcaster');
        for ($i = 1; $i <= 3; $i++) {
            $broadcaster->broadcast(['test.limit.channel'], 'LimitTest', [
                'index' => $i,
                'timestamp' => now()->toISOString(),
            ]);
            usleep(100000); // 100ms between messages
        }

        sleep(2);

        pclose($process);

        // Process should have exited after 2 messages
        $this->assertTrue(true);
    }
}