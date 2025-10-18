<?php
/**
 * Simple GeoIP Implementation using ip-api.com service
 * Free tier: 45 requests/minute
 * For production, consider caching or using MaxMind GeoLite2
 */

class GeoIP {
    private static $cache = [];
    private static $cacheFile = '/tmp/geoip_cache.json';
    private static $cacheExpiry = 86400; // 24 hours
    
    /**
     * Get location info for an IP address
     * @param string $ip IP address to look up
     * @return array|null Location data or null on failure
     */
    public static function lookup($ip) {
        // Skip private/local IPs
        if (self::isPrivateIP($ip)) {
            return [
                'country' => 'Private',
                'countryCode' => 'XX',
                'city' => 'Local Network',
                'region' => '',
                'lat' => 0,
                'lon' => 0,
                'timezone' => 'UTC',
                'isp' => 'Private Network'
            ];
        }
        
        // Check memory cache
        if (isset(self::$cache[$ip])) {
            return self::$cache[$ip];
        }
        
        // Load persistent cache
        self::loadCache();
        
        // Check persistent cache
        if (isset(self::$cache[$ip])) {
            $cached = self::$cache[$ip];
            if (time() - ($cached['cached_at'] ?? 0) < self::$cacheExpiry) {
                return $cached;
            }
        }
        
        // Fetch from API
        $data = self::fetchFromAPI($ip);
        if ($data) {
            $data['cached_at'] = time();
            self::$cache[$ip] = $data;
            self::saveCache();
        }
        
        return $data;
    }
    
    /**
     * Fetch location data from ip-api.com
     */
    private static function fetchFromAPI($ip) {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,message,country,countryCode,region,city,lat,lon,timezone,isp";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_ENCODING, 'UTF-8');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Ensure UTF-8 encoding
            if ($httpCode === 200 && $response) {
                $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
                $data = json_decode($response, true);
                if ($data && $data['status'] === 'success') {
                    return [
                        'country' => $data['country'] ?? 'Unknown',
                        'countryCode' => $data['countryCode'] ?? 'XX',
                        'city' => $data['city'] ?? '',
                        'region' => $data['region'] ?? '',
                        'lat' => $data['lat'] ?? 0,
                        'lon' => $data['lon'] ?? 0,
                        'timezone' => $data['timezone'] ?? 'UTC',
                        'isp' => $data['isp'] ?? 'Unknown'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("GeoIP lookup failed for {$ip}: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Check if IP is private/local
     */
    private static function isPrivateIP($ip) {
        // Handle IPv6 localhost
        if ($ip === '::1' || $ip === 'localhost') {
            return true;
        }
        
        // Convert to long for IPv4
        $longIp = ip2long($ip);
        if ($longIp === false) {
            return false; // Invalid or IPv6
        }
        
        // Check private ranges
        $privateRanges = [
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['127.0.0.0', '127.255.255.255'],
        ];
        
        foreach ($privateRanges as $range) {
            $start = ip2long($range[0]);
            $end = ip2long($range[1]);
            if ($longIp >= $start && $longIp <= $end) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Load cache from file
     */
    private static function loadCache() {
        if (empty(self::$cache) && file_exists(self::$cacheFile)) {
            $data = @file_get_contents(self::$cacheFile);
            if ($data) {
                self::$cache = json_decode($data, true) ?: [];
            }
        }
    }
    
    /**
     * Save cache to file
     */
    private static function saveCache() {
        // Only keep cache entries less than 30 days old
        $cutoff = time() - (86400 * 30);
        self::$cache = array_filter(self::$cache, function($entry) use ($cutoff) {
            return ($entry['cached_at'] ?? 0) > $cutoff;
        });
        
        // Limit cache size to 10000 entries
        if (count(self::$cache) > 10000) {
            self::$cache = array_slice(self::$cache, -10000, 10000, true);
        }
        
        @file_put_contents(self::$cacheFile, json_encode(self::$cache));
    }
    
    /**
     * Get country flag emoji
     */
    public static function getFlag($countryCode) {
        if ($countryCode === 'XX' || strlen($countryCode) !== 2) {
            return '🏴';
        }
        
        // Convert country code to flag emoji
        $code = strtoupper($countryCode);
        $flag = '';
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($code[$i]) + 127397);
        }
        return $flag;
    }
    
    /**
     * Bulk lookup multiple IPs (with rate limiting)
     */
    public static function lookupBulk($ips) {
        $results = [];
        $toBeFetched = [];
        
        // First check cache for all IPs
        foreach ($ips as $ip) {
            if (isset(self::$cache[$ip])) {
                $results[$ip] = self::$cache[$ip];
            } else {
                $toBeFetched[] = $ip;
            }
        }
        
        // Fetch missing ones (respecting rate limit of 45/min = ~1 per second)
        foreach ($toBeFetched as $index => $ip) {
            if ($index > 0) {
                usleep(1100000); // 1.1 second delay
            }
            $results[$ip] = self::lookup($ip);
        }
        
        return $results;
    }
}
