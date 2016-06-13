<?php

namespace RedisCache;

use \Exception;

class RedisCache
{
    public function __invoke($payload=[])
    {
        $configs=isset($payload["configs"]) ? $payload["configs"] : [];

        client = new Predis\Client();
        $client->set('foo', 'bar');
        $value = $client->get('foo');

        return $value;

    }

}
