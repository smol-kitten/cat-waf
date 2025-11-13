<?php
/**
 * Local GeoIP Implementation using MaxMind GeoLite2 database
 * Much faster than external API calls
 * 
 * Installation:
 * 1. Download GeoLite2-City.mmdb from MaxMind
 * 2. Place in /usr/share/GeoIP/ or configure path
 * 3. Install maxminddb PHP extension: pecl install maxminddb
 *    OR use composer: composer require geoip2/geoip2
 */

// Load composer autoloader if available
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

class GeoIPLocal {
    private static $reader = null;
    private static $cache = [];
    private static $dbPath = '/usr/share/GeoIP/GeoLite2-City.mmdb';
    private static $fallbackToAPI = true;
    
    /**
     * Set database path
     */
    public static function setDatabasePath($path) {
        self::$dbPath = $path;
    }
    
    /**
     * Enable/disable fallback to external API
     */
    public static function setFallback($enable) {
        self::$fallbackToAPI = $enable;
    }
    
    /**
     * Initialize MaxMind reader
     */
    private static function initReader() {
        if (self::$reader !== null) {
            return true;
        }
        
        // Check if database file exists
        if (!file_exists(self::$dbPath)) {
            error_log("GeoIP database not found at: " . self::$dbPath);
            return false;
        }
        
        // Try using GeoIP2 Reader (composer package)
        if (class_exists('GeoIp2\Database\Reader')) {
            try {
                self::$reader = new \GeoIp2\Database\Reader(self::$dbPath);
                return true;
            } catch (Exception $e) {
                error_log("GeoIP2 Reader initialization failed: " . $e->getMessage());
                return false;
            }
        }
        
        // Try using MaxMindDB extension
        if (class_exists('MaxMindDB\Reader')) {
            try {
                self::$reader = new \MaxMindDB\Reader(self::$dbPath);
                return true;
            } catch (Exception $e) {
                error_log("MaxMindDB Reader initialization failed: " . $e->getMessage());
                return false;
            }
        }
        
        error_log("No GeoIP library found. Install geoip2/geoip2 via composer or maxminddb PECL extension");
        return false;
    }
    
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
                'isp' => 'Private Network',
                'source' => 'local'
            ];
        }
        
        // Check memory cache
        if (isset(self::$cache[$ip])) {
            return self::$cache[$ip];
        }
        
        // Try local database first
        $data = self::lookupLocal($ip);
        
        // Fallback to external API if enabled and local failed
        if (!$data && self::$fallbackToAPI) {
            require_once __DIR__ . '/GeoIP.php';
            $data = GeoIP::lookup($ip);
            if ($data) {
                $data['source'] = 'api-fallback';
            }
        }
        
        if ($data) {
            self::$cache[$ip] = $data;
        }
        
        return $data;
    }
    
    /**
     * Lookup using local database
     */
    private static function lookupLocal($ip) {
        if (!self::initReader()) {
            return null;
        }
        
        try {
            // GeoIP2 Reader (composer package)
            if (self::$reader instanceof \GeoIp2\Database\Reader) {
                $record = self::$reader->city($ip);
                return [
                    'country' => $record->country->name ?? 'Unknown',
                    'countryCode' => $record->country->isoCode ?? 'XX',
                    'city' => $record->city->name ?? '',
                    'region' => $record->mostSpecificSubdivision->name ?? '',
                    'lat' => $record->location->latitude ?? 0,
                    'lon' => $record->location->longitude ?? 0,
                    'timezone' => $record->location->timeZone ?? 'UTC',
                    'isp' => '', // Not in free City database
                    'source' => 'local-db'
                ];
            }
            
            // MaxMindDB Reader (PECL extension)
            if (self::$reader instanceof \MaxMindDB\Reader) {
                $record = self::$reader->get($ip);
                if ($record) {
                    return [
                        'country' => $record['country']['names']['en'] ?? 'Unknown',
                        'countryCode' => $record['country']['iso_code'] ?? 'XX',
                        'city' => $record['city']['names']['en'] ?? '',
                        'region' => $record['subdivisions'][0]['names']['en'] ?? '',
                        'lat' => $record['location']['latitude'] ?? 0,
                        'lon' => $record['location']['longitude'] ?? 0,
                        'timezone' => $record['location']['time_zone'] ?? 'UTC',
                        'isp' => '', // Not in free City database
                        'source' => 'local-db'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Local GeoIP lookup failed for {$ip}: " . $e->getMessage());
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
        
        // Handle IPv4 localhost and private ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get flag emoji for country code
     */
    public static function getFlag($countryCode) {
        if (strlen($countryCode) !== 2) {
            return '🌍';
        }
        
        $code = strtoupper($countryCode);
        $firstLetter = mb_chr(ord($code[0]) + 127397);
        $secondLetter = mb_chr(ord($code[1]) + 127397);
        
        return $firstLetter . $secondLetter;
    }
    
    /**
     * Test database availability
     */
    public static function isDatabaseAvailable() {
        return file_exists(self::$dbPath) && self::initReader();
    }
    
    /**
     * Get database info
     */
    public static function getDatabaseInfo() {
        if (!file_exists(self::$dbPath)) {
            return [
                'available' => false,
                'path' => self::$dbPath,
                'error' => 'Database file not found'
            ];
        }
        
        $info = [
            'available' => self::initReader(),
            'path' => self::$dbPath,
            'size' => filesize(self::$dbPath),
            'modified' => date('Y-m-d H:i:s', filemtime(self::$dbPath))
        ];
        
        if (self::$reader instanceof \GeoIp2\Database\Reader) {
            $info['library'] = 'geoip2/geoip2 (Composer)';
        } elseif (self::$reader instanceof \MaxMindDB\Reader) {
            $info['library'] = 'maxminddb (PECL Extension)';
        } else {
            $info['library'] = 'none';
            $info['error'] = 'No GeoIP library available';
        }
        
        return $info;
    }
}
