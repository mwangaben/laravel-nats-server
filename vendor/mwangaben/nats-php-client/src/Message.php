<?php

namespace Nats;

use Nats\Encoders\EncoderInterface;
use Nats\Encoders\RawEncoder;

class Message
{
    private string $subject;
    private $body;
    private ?string $replyTo;
    private ?string $sid;
    private EncoderInterface $encoder;

    public function __construct(
        string $subject,
        $body,
        ?string $replyTo = null,
        ?string $sid = null,
        ?EncoderInterface $encoder = null
    ) {
        $this->subject = $subject;
        $this->body = $body;
        $this->replyTo = $replyTo;
        $this->sid = $sid;
        $this->encoder = $encoder ?? new RawEncoder();
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function getReplyTo(): ?string
    {
        return $this->replyTo;
    }

    public function getSid(): ?string
    {
        return $this->sid;
    }

    public function getRawBody(): string
    {
        return $this->encoder->encode($this->body);
    }

    public function setEncoder(EncoderInterface $encoder): void
    {
        $this->encoder = $encoder;
    }

    public function __toString(): string
    {
        return $this->getRawBody();
    }
}
