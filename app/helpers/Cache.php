<?php
/**
 * Simple Cache Helper
 * Provides basic caching functionality for API responses
 */

class Cache {
    private static $cacheDir = __DIR__ . '/../../cache/';
    private static $defaultTTL = 300; // 5 minutes
    
    /**
     * Initialize cache directory
     */
    private static function init() {
        if (!file_exists(self::$cacheDir)) {
            mkdir(self::$cacheDir, 0755, true);
        }
    }
    
    /**
     * Generate cache key
     * @param string $key The cache key
     * @return string Hashed cache key
     */
    private static function getCacheKey($key) {
        return md5($key);
    }
    
    /**
     * Get cache file path
     * @param string $key The cache key
     * @return string Cache file path
     */
    private static function getCacheFile($key) {
        return self::$cacheDir . self::getCacheKey($key) . '.cache';
    }
    
    /**
     * Set cache value
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (default: 5 minutes)
     * @return bool True on success
     */
    public static function set($key, $value, $ttl = null) {
        self::init();
        
        if ($ttl === null) {
            $ttl = self::$defaultTTL;
        }
        
        $cacheData = [
            'expires' => time() + $ttl,
            'data' => $value
        ];
        
        $cacheFile = self::getCacheFile($key);
        return file_put_contents($cacheFile, serialize($cacheData)) !== false;
    }
    
    /**
     * Get cache value
     * @param string $key Cache key
     * @param mixed $default Default value if not found or expired
     * @return mixed Cached value or default
     */
    public static function get($key, $default = null) {
        self::init();
        
        $cacheFile = self::getCacheFile($key);
        
        if (!file_exists($cacheFile)) {
            return $default;
        }
        
        $cacheData = unserialize(file_get_contents($cacheFile));
        
        // Check if expired
        if ($cacheData['expires'] < time()) {
            unlink($cacheFile);
            return $default;
        }
        
        return $cacheData['data'];
    }
    
    /**
     * Check if cache exists and is valid
     * @param string $key Cache key
     * @return bool True if exists and valid
     */
    public static function has($key) {
        self::init();
        
        $cacheFile = self::getCacheFile($key);
        
        if (!file_exists($cacheFile)) {
            return false;
        }
        
        $cacheData = unserialize(file_get_contents($cacheFile));
        
        if ($cacheData['expires'] < time()) {
            unlink($cacheFile);
            return false;
        }
        
        return true;
    }
    
    /**
     * Delete cache entry
     * @param string $key Cache key
     * @return bool True on success
     */
    public static function delete($key) {
        self::init();
        
        $cacheFile = self::getCacheFile($key);
        
        if (file_exists($cacheFile)) {
            return unlink($cacheFile);
        }
        
        return true;
    }
    
    /**
     * Clear all cache
     * @return bool True on success
     */
    public static function clear() {
        self::init();
        
        $files = glob(self::$cacheDir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        
        return true;
    }
    
    /**
     * Remember - Get from cache or execute callback and cache result
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or fresh value
     */
    public static function remember($key, $callback, $ttl = null) {
        if (self::has($key)) {
            return self::get($key);
        }
        
        $value = $callback();
        self::set($key, $value, $ttl);
        
        return $value;
    }
}
