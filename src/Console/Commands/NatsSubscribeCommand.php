<?php

namespace Mwangaben\NatsBroadcaster\Console\Commands;

use Illuminate\Console\Command;
use Mwangaben\NatsBroadcaster\Broadcasters\NatsBroadcaster;

class NatsSubscribeCommand extends Command
{
    protected $signature = 'nats:subscribe
                            {subject : Subject to subscribe to}
                            {--queue= : Queue group name}
                            {--limit=0 : Maximum messages to receive (0 for unlimited)}';

    protected $description = 'Subscribe to NATS subjects and listen for messages';

    public function handle(NatsBroadcaster $broadcaster): void
    {
        $subject = $this->argument('subject');
        $queue = $this->option('queue');
        $limit = (int) $this->option('limit');

        $this->info("Subscribing to subject: {$subject}");
        if ($queue) {
            $this->info("Queue group: {$queue}");
        }

        $messageCount = 0;

        $broadcaster->subscribe($subject, function ($message) use (&$messageCount, $limit) {
            $this->handleMessage($message);
            $messageCount++;

            if ($limit > 0 && $messageCount >= $limit) {
                $this->info("Received {$limit} messages. Exiting...");
                exit(0);
            }
        });

        $this->info('Listening for messages. Press Ctrl+C to stop.');

        // Keep the script running
        while (true) {
            sleep(1);
        }
    }

    protected function handleMessage(array $message): void
    {
        $headers = [];

        if (isset($message['event'])) {
            $headers[] = "<fg=blue>Event:</> {$message['event']}";
        }

        if (isset($message['channel'])) {
            $headers[] = "<fg=blue>Channel:</> {$message['channel']}";
        }

        if (isset($message['socket'])) {
            $headers[] = "<fg=blue>Socket:</> {$message['socket']}";
        }

        if (isset($message['timestamp'])) {
            $headers[] = "<fg=blue>Timestamp:</> {$message['timestamp']}";
        }

        $this->newLine();
        $this->line(implode(' | ', $headers));

        if (isset($message['data'])) {
            $data = is_array($message['data']) ? json_encode($message['data'], JSON_PRETTY_PRINT) : $message['data'];
            $this->line('<fg=green>Data:</>');
            $this->line($data);
        }

        $this->newLine();
        $this->line(str_repeat('-', 80));
    }
}