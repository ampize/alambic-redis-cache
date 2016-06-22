<?php

namespace RedisCache;

use \Exception;

class RedisCache

{

    protected $_client;

    public function __invoke($payload=[])
    {
        $this->_client = new \Predis\Client();
        return $payload["isMutation"] ? $this->execute($payload) : $this->resolve($payload);
    }

    private function resolve($payload=[]) {
      if (!isset($payload["response"])) {
        $cacheKey = $payload["pipelineParams"]["currentRequestString"].":response";
        $value = $this->_client->get($cacheKey);
        $type = explode("/",$cacheKey)[0];
        if (isset($value)) {
          // read from cache
          $payload["response"] = json_decode($value,true);
        } else {
          // set cache key for further stages
          $payload["cacheKey"] = $cacheKey;
        }
        // set cache for parent query
        if (isset($payload["pipelineParams"]["parentRequestString"])) {
          $parentCacheKey = $payload["pipelineParams"]["currentRequestString"].":parentQueries";
          $this->_client->sAdd($parentCacheKey, $payload["pipelineParams"]["parentRequestString"]);
        }
        // set cache for object requests
        $typeCacheKey = $type.":queries";
        $this->_client->sAdd($typeCacheKey, $payload["pipelineParams"]["currentRequestString"]);
      } else {
        if (isset($payload["cacheKey"])) {
          // write to cache
          $key = $payload["cacheKey"];
          $value = $payload["response"];
          $this->_client->set($key,json_encode($value));
        }
      }
      return $payload;
    }

    private function execute($payload=[]) {
      if (isset($payload["response"])) {
        $cacheKey = $payload["type"].":queries";
        $queries = $this->_client->smembers($cacheKey);
        $keysToDelete = $this->getKeysToDelete($queries);
        $resultKeys = $keysToDelete;
        array_walk($resultKeys, function(&$value, $key) { $value .= ':response'; });
        $parentKeys = $keysToDelete;
        array_walk($parentKeys, function(&$value, $key) { $value .= ':parentQueries'; });
        $numberOfDeletedKeys = $this->_client->del(array_values(array_merge($resultKeys,$parentKeys)));
      }
      return $payload;
    }

    private function getKeysToDelete($queries) {
      $result = [];
      foreach($queries as $query) {
        $result[] = $query;
        $cacheKey=$query.":parentQueries";
        $parentList = $this->_client->smembers($cacheKey);
        if (isset($parentList)) {
          array_merge($result,$this->getKeysToDelete($parentList));
        }
      }
      return $result;
    }

}
