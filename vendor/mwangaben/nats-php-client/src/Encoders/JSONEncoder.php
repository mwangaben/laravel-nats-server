<?php

namespace Nats\Encoders;

class JSONEncoder implements EncoderInterface
{
    private int $options;
    private int $depth;

    public function __construct(int $options = 0, int $depth = 512)
    {
        $this->options = $options;
        $this->depth = $depth;
    }

    public function encode($data): string
    {
        return json_encode($data, $this->options, $this->depth);
    }

    public function decode(string $data)
    {
        return json_decode($data, true, $this->depth, $this->options);
    }
}
