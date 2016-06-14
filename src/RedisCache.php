<?php

namespace RedisCache;

use \Exception;

class RedisCache
{
    public function __invoke($payload=[])
    {
        $client = new \Predis\Client();
        if (!isset($payload["response"])) {
          $key = $this->array_md5($payload).":response";
          $value = $client->get($key);
          if (isset($value)) {
            // read from cache
            $payload["response"] = json_decode($value,true);
          } else {
            // set cache key for further stages
            $payload["cacheKey"] = $key;
          }
        } else {
          if (isset($payload["cacheKey"])) {
            // write to cache
            $key = $payload["cacheKey"];
            $value = $payload["response"];
            $client->set($key,json_encode($value));
          }
        }

      return $payload;

    }

    private function array_md5(Array $array) {
        array_multisort($array);
        return md5(json_encode($array));
    }

}
