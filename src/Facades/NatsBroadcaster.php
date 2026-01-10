<?php

namespace Mwangaben\NatsBroadcaster\Facades;

use Illuminate\Support\Facades\Facade;

class NatsBroadcaster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'nats.broadcaster';
    }
}