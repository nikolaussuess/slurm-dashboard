<?php

namespace cache;

/**
 * Backend interface — implement this to add a new cache driver (e.g. Redis, Memcached).
 */
interface CacheBackend {
    /**
     * Returns the cached value, or FALSE on a cache miss.
     * @param string $key Cache key.
     * @return mixed The cached value, or FALSE if the key does not exist.
     */
    public function get(string $key): mixed;

    /**
     * Stores a value. $ttl = 0 means no expiry.
     * @param string $key   Cache key.
     * @param mixed  $value Value to store.
     * @param int    $ttl   Time-to-live in seconds. 0 = no expiry.
     * @return bool TRUE on success.
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * Removes a cached entry.
     * @param string $key Cache key.
     * @return bool TRUE on success.
     */
    public function delete(string $key): bool;

    /**
     * Returns TRUE if the key exists in the cache.
     * @param string $key Cache key.
     * @return bool TRUE if the key exists, FALSE otherwise.
     */
    public function exists(string $key): bool;

    /**
     * Stores a value only if the key does not already exist (preserving any existing TTL).
     * @param string $key   Cache key.
     * @param mixed  $value Value to store.
     * @param int    $ttl   Time-to-live in seconds. 0 = no expiry.
     * @return bool TRUE if the value was stored, FALSE if the key already existed.
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool;

    /**
     * Atomically increments an existing integer value by 1.
     * @param string $key Cache key.
     * @return int|bool The new value on success, or FALSE on failure.
     */
    public function increment(string $key): int|bool;
}


class ApcuCacheBackend implements CacheBackend {
    /** @inheritDoc */
    public function get(string $key): mixed       {
        return apcu_fetch($key);
    }
    /** @inheritDoc */
    public function set(string $key, mixed $value, int $ttl = 0): bool {
        return apcu_store($key, $value, $ttl);
    }
    /** @inheritDoc */
    public function delete(string $key): bool     {
        return apcu_delete($key);
    }
    /** @inheritDoc */
    public function exists(string $key): bool     {
        return apcu_exists($key);
    }
    /** @inheritDoc */
    public function add(string $key, mixed $value, int $ttl = 0): bool {
        return apcu_add($key, $value, $ttl);
    }
    /** @inheritDoc */
    public function increment(string $key): int|bool {
        return apcu_inc($key);
    }
}


/**
 * No-op backend used when no cache extension is available.
 */
class NullCacheBackend implements CacheBackend {
    /** @inheritDoc */
    public function get(string $key): mixed {
        return FALSE;
    }
    /** @inheritDoc */
    public function set(string $key, mixed $value, int $ttl = 0): bool {
        return TRUE;
    }
    /** @inheritDoc */
    public function delete(string $key): bool {
        return TRUE;
    }
    /** @inheritDoc */
    public function exists(string $key): bool {
        return FALSE;
    }
    /** @inheritDoc */
    public function add(string $key, mixed $value, int $ttl = 0): bool {
        return TRUE;
    }
    /** @inheritDoc */
    public function increment(string $key): int|bool {
        return FALSE;
    }
}


/**
 * Singleton facade over a CacheBackend.
 * Automatically selects APCu when available, otherwise falls back to the NullCacheBackend.
 * Logs a warning on start-up when running without a real backend so that the operator is
 * aware that response caching and rate limiting are inactive.
 *
 * TTL=FALSE is a special sentinel meaning "do not cache". set() becomes a no-op and
 * exists() naturally returns FALSE because nothing was ever stored — callers do not need
 * to guard against this case themselves.
 */
class CacheWrapper {
    private static ?self $instance = NULL;
    private CacheBackend $backend;

    /**
     * @param CacheBackend $backend The backend to delegate all cache operations to.
     */
    private function __construct(CacheBackend $backend) {
        $this->backend = $backend;
    }

    /**
     * Returns the singleton instance, creating it on first call.
     * Selects the backend based on the USE_CACHE config value and extension availability.
     * @return self
     */
    public static function getInstance(): self {
        if (self::$instance === NULL) {
            if (config('USE_CACHE') === 'apcu') {
                if (extension_loaded('apcu')) {
                    self::$instance = new self(new ApcuCacheBackend());
                } else {
                    self::$instance = new self(new NullCacheBackend());
                    self::logMissingApcuWarning();
                }
            }
            else {
                self::$instance = new self(new NullCacheBackend());
            }
        }
        return self::$instance;
    }

    /**
     * Logs a warning that APCu is configured but unavailable — at most once per session.
     */
    private static function logMissingApcuWarning(): void {
        if (!empty($_SESSION['apcu_warning_logged']))
            return;
        $_SESSION['apcu_warning_logged'] = TRUE;
        log_msg(
            'USE_CACHE=apcu is configured but the APCu extension is not available. '
            . 'Response caching and rate limiting are disabled.',
            LOG_WARNING,
            LOG_MODE_PHP | LOG_MODE_SYSLOG
        );
    }

    /**
     * @param string $key Cache key.
     * @return mixed The cached value, or FALSE on a cache miss.
     */
    public function get(string $key): mixed {
        return $this->backend->get($key);
    }

    /**
     * TTL=FALSE means "do not cache"; the call is a no-op and backends need not handle it.
     * @param string   $key   Cache key.
     * @param mixed    $value Value to store.
     * @param int|bool $ttl   Time-to-live in seconds, or FALSE to skip caching entirely.
     * @return bool TRUE on success (or when skipped due to TTL=FALSE).
     */
    public function set(string $key, mixed $value, int|bool $ttl = 0): bool {
        if ($ttl === FALSE)
            return TRUE;
        return $this->backend->set($key, $value, $ttl);
    }

    /**
     * @param string $key Cache key.
     * @return bool TRUE on success.
     */
    public function delete(string $key): bool {
        return $this->backend->delete($key);
    }

    /**
     * @param string $key Cache key.
     * @return bool TRUE if the key exists, FALSE otherwise.
     */
    public function exists(string $key): bool {
        return $this->backend->exists($key);
    }

    /**
     * @param string $key   Cache key.
     * @param mixed  $value Value to store.
     * @param int    $ttl   Time-to-live in seconds. 0 = no expiry.
     * @return bool TRUE if the value was stored, FALSE if the key already existed.
     */
    public function add(string $key, mixed $value, int $ttl = 0): bool {
        return $this->backend->add($key, $value, $ttl);
    }

    /**
     * @param string $key Cache key.
     * @return int|bool The new value on success, or FALSE on failure.
     */
    public function increment(string $key): int|bool {
        return $this->backend->increment($key);
    }
}
