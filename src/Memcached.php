<?php

namespace WildWolf\Cache;

use WildWolf\Cache\Utils;

class Memcached implements \Psr\SimpleCache\CacheInterface
{
    /**
     * @var \Memcached
     */
    private $mc;

    public function __construct(array $config = [])
    {
        $this->mc = new \Memcached();
        $prefix   = $config['prefix']  ?? null;
        $servers  = $config['servers'] ?? null;
        $options  = $config['options'] ?? null;

        is_array($servers) && $this->addServers($servers);
        is_array($options) && $this->setOptions($options);
        !is_null($prefix) && !isset($options[\Memcached::OPT_PREFIX_KEY]) && $this->setOption(\Memcached::OPT_PREFIX_KEY, $prefix);
    }

    public function handle() : \Memcached
    {
        return $this->mc;
    }

    public function addServer(string $host, int $port, int $weight = 0) : bool
    {
        return $this->mc->addServer($host, $port, $weight);
    }

    public function addServers(array $servers) : bool
    {
        return $this->mc->addServers($servers);
    }

    public function clearServers() : bool
    {
        return $this->mc->resetServerList();
    }

    public function getServerList() : array
    {
        return $this->mc->getServerList();
    }

    public function getOption(int $option)
    {
        return $this->mc->getOption($option);
    }

    public function setOption(int $option, $value) : bool
    {
        return $this->mc->setOption($option, $value);
    }

    public function setOptions(array $options) : bool
    {
        return $this->mc->setOptions($options);
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache.
     * @param mixed  $default Default value to return if the key does not exist.
     *
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    public function get($key, $default = null)
    {
        Validator::validateKey($key);

        $value = $this->mc->get($key);
        if (false === $value && $this->mc->getResultCode() == \Memcached::RES_NOTFOUND) {
            return $default;
        }

        return $value;
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                $key   The key of the item to store.
     * @param mixed                 $value The value of the item to store, must be serializable.
     * @param null|int|\DateInterval $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                     the driver supports TTL then the library may set a default value
     *                                     for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    public function set($key, $value, $ttl = null)
    {
        Validator::validateKey($key);

        $ttl = Utils::relativeTtl($ttl);
        if ($ttl !== null && $ttl <= 0) {
            return $this->okIfNotFound($this->mc->delete($key));
        }

        return $this->mc->set($key, $value, $ttl);
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    public function delete($key)
    {
        Validator::validateKey($key);
        return $this->okIfNotFound($this->mc->delete($key));
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear()
    {
        return $this->mc->flush();
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys    A list of keys that can obtained in a single operation.
     * @param mixed    $default Default value to return for keys that do not exist.
     *
     * @return iterable A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    public function getMultiple($keys, $default = null)
    {
        $keys = Utils::keysToArray($keys);

        $retval = [];
        $result = $this->mc->getMulti($keys);

        if (false === $result) {
            $result = [];
        }

        foreach ($keys as $key) {
            $retval[$key] = array_key_exists($key, $result) ? $result[$key] : $default;
        }

        return $retval;
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable              $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @return bool True on success and false on failure.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $values is neither an array nor a Traversable,
     *   or if any of the $values are not a legal value.
     */
    public function setMultiple($values, $ttl = null)
    {
        $ttl    = Utils::relativeTtl($ttl);
        $values = Utils::iterableToArray($values);

        if ($ttl !== null && $ttl <= 0) {
            return $this->deleteMultiple(array_keys($values));
        }

        return $this->mc->setMulti($values, $ttl);
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys A list of string-based keys to be deleted.
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if $keys is neither an array nor a Traversable,
     *   or if any of the $keys are not a legal value.
     */
    public function deleteMultiple($keys)
    {
        $keys = Utils::keysToArray($keys);
        return empty($keys) ? true : $this->okIfNotFound($this->mc->deleteMulti($keys));
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * NOTE: It is recommended that has() is only to be used for cache warming type purposes
     * and not to be used within your live applications operations for get/set, as this method
     * is subject to a race condition where your has() will return true and immediately after,
     * another script can remove it making the state of your app out of date.
     *
     * @param string $key The cache item key.
     *
     * @return bool
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException
     *   MUST be thrown if the $key string is not a legal value.
     */
    public function has($key)
    {
        Validator::validateKey($key);
        $res = $this->mc->get($key);

        return (false !== $res) ? true : (\Memcached::RES_SUCCESS == $this->mc->getResultCode());
    }

    private function okIfNotFound($res) : bool
    {
        return false !== $res ? true : $this->mc->getResultCode() == \Memcached::RES_NOTFOUND;
    }
}
