<?php

namespace RedisCache;

class RedisCache
{
    /**
     * Redis default host
     *
     * @var string
     */
    protected $_defaultHost = "127.0.0.1";

    /**
     * Redis default port
     *
     * @var string
     */
    protected $_defaultPort = "6379";

    /**
     * Redis client.
     *
     * @var Client[]
     */
    protected $_client;

    /**
     * Default cache Time To Live.
     *
     * @var int
     */
    protected $_defaultCacheTTL = 3600;

    /**
     * Default call of RedisCache.
     *
     * @param array $payload
     *
     * @return array
     */
    public function __invoke($payload = [])
    {
        // Get Redis configuration
        $host = isset($payload["connectorBaseConfig"]["redisCacheHost"]) ? $payload["connectorBaseConfig"]["redisCacheHost"] : $this->_defaultHost;
        $port = isset($payload["connectorBaseConfig"]["redisCachePort"]) ? $payload["connectorBaseConfig"]["redisCachePort"] : $this->_defaultPort;
        $password = isset($payload["connectorBaseConfig"]["redisCachePassword"]) ? $payload["connectorBaseConfig"]["redisCachePassword"] : null;

        // Init Redis client
        try {
            $this->_client = new \Predis\Client([
                'host' => $host,
                'port' => $port
            ]);
        } catch (Exception $exception) {
            throw new Exception($exception->getMessage());
        }

        if ($password) {
            $this->_client->auth($password);
        }

        // Set default cache TTL
        if (isset($payload['connectorBaseConfig']['defaultCacheTTL'])) {
            $this->_defaultCacheTTL = $payload['connectorBaseConfig']['defaultCacheTTL'];
        }

        // Route to resolve or execute
        return $payload['isMutation'] ? $this->execute($payload) : $this->resolve($payload);
    }

    /**
     * Resolver.
     *
     * @param array $payload
     *
     * @return array
     */
    private function resolve($payload = [])
    {
        if (!isset($payload['response'])) {
            $cacheKey = $payload['pipelineParams']['currentRequestString'].':response';
            $value = $this->_client->get($cacheKey);
            $type = explode('/', $cacheKey)[0];
            if (isset($value)) {
                // read from cache
                $payload['response'] = json_decode($value, true);
            } else {
                // set cache key for further stages
                $payload['cacheKey'] = $cacheKey;
            }
            // set cache for parent query
            if (isset($payload['pipelineParams']['parentRequestString'])) {
                $parentCacheKey = $payload['pipelineParams']['currentRequestString'].':parentQueries';
                $this->_client->sAdd($parentCacheKey, $payload['pipelineParams']['parentRequestString']);
            }
            // set cache for object requests
            $typeCacheKey = $type.':queries';
            $this->_client->sAdd($typeCacheKey, $payload['pipelineParams']['currentRequestString']);
        } else {
            if (isset($payload['cacheKey'])) {
                // write to cache
                $key = $payload['cacheKey'];
                $value = $payload['response'];
                $this->_client->set($key, json_encode($value));
                // set ttl
                $ttl = isset($payload['configs']['cacheTTL']) ? $payload['configs']['cacheTTL'] : $this->_defaultCacheTTL;
                $this->_client->expire($key, $ttl);
            }
        }

        return $payload;
    }

    /**
     * Handle mutations.
     *
     * @param array $payload
     *
     * @return array $payload
     */
    private function execute($payload = [])
    {
        if (isset($payload['response'])) {
            $cacheKey = $payload['type'].':queries';
            $queries = $this->_client->smembers($cacheKey);
            $keysToDelete = $this->getKeysToDelete($queries);
            $resultKeys = $keysToDelete;
            array_walk($resultKeys, function (&$value, $key) { $value .= ':response'; });
            $parentKeys = $keysToDelete;
            array_walk($parentKeys, function (&$value, $key) { $value .= ':parentQueries'; });
            $numberOfDeletedKeys = $this->_client->del(array_values(array_merge($resultKeys, $parentKeys)));
        }

        return $payload;
    }

    /**
     * Iterative construct of queries keys to delete from cache.
     *
     * @param array $queries
     *
     * @return array
     */
    private function getKeysToDelete($queries)
    {
        $result = [];
        foreach ($queries as $query) {
            $result[] = $query;
            $cacheKey = $query.':parentQueries';
            $parentList = $this->_client->smembers($cacheKey);
            if (isset($parentList)) {
                array_merge($result, $this->getKeysToDelete($parentList));
            }
        }

        return $result;
    }
}
