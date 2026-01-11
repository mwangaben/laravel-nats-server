<?php

namespace Nats;

class Subscription
{
    private string $sid;
    private string $subject;
    private ?string $queueGroup;
    private \Closure $callback;
    private int $received = 0;
    private int $maxMessages;
    private bool $autoUnsubscribe = false;

    public function __construct(
        string $sid,
        string $subject,
        \Closure $callback,
        ?string $queueGroup = null,
        ?int $maxMessages = null
    ) {
        $this->sid = $sid;
        $this->subject = $subject;
        $this->queueGroup = $queueGroup;
        $this->callback = $callback;
        $this->maxMessages = $maxMessages ?? 0;

        if ($maxMessages > 0) {
            $this->autoUnsubscribe = true;
        }
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getQueueGroup(): ?string
    {
        return $this->queueGroup;
    }

    public function getCallback(): \Closure
    {
        return $this->callback;
    }

    public function getReceived(): int
    {
        return $this->received;
    }

    public function incrementReceived(): void
    {
        $this->received++;
    }

    public function shouldAutoUnsubscribe(): bool
    {
        return $this->autoUnsubscribe && $this->maxMessages > 0 && $this->received >= $this->maxMessages;
    }

    public function getMaxMessages(): int
    {
        return $this->maxMessages;
    }
}
