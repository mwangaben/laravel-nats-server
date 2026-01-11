<?php

namespace Nats\Encoders;

class RawEncoder implements EncoderInterface
{
    public function encode($data): string
    {
        return (string) $data;
    }

    public function decode(string $data): string
    {
        return $data;
    }
}
