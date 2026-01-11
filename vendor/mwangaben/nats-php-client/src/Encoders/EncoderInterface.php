<?php

namespace Nats\Encoders;

interface EncoderInterface
{
    public function encode($data): string;
    public function decode(string $data);
}
