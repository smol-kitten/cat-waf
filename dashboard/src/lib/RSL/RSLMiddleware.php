<?php
/**
 * RSL Middleware - Handles CAP (Crawler Authorization Protocol)
 * Validates License authorization headers and enforces RSL policies
 */

namespace CatWAF\RSL;

class RSLMiddleware {
    private \PDO $db;
    private RSLLicenseServer $licenseServer;
    
    public function __construct(\PDO $db, RSLLicenseServer $licenseServer) {
        $this->db = $db;
        $this->licenseServer = $licenseServer;
    }
    
    /**
     * Check if request has valid license authorization
     * Returns [authorized: bool, reason: string, license: ?array]
     */
    public function checkAuthorization(string $authHeader, string $requestUri, string $userAgent): array {
        // Parse Authorization header
        if (!preg_match('/^License\s+(.+)$/i', $authHeader, $matches)) {
            return [
                'authorized' => false,
                'reason' => 'Invalid authorization scheme. Use: Authorization: License <token>',
                'error' => 'invalid_scheme'
            ];
        }
        
        $token = $matches[1];
        
        // Validate token
        $tokenData = $this->licenseServer->validateToken($token);
        
        if (!$tokenData) {
            return [
                'authorized' => false,
                'reason' => 'Invalid or expired license token',
                'error' => 'invalid_token'
            ];
        }
        
        // Check if token scope covers this request
        if ($tokenData['content_url'] && !$this->urlMatches($requestUri, $tokenData['content_url'])) {
            return [
                'authorized' => false,
                'reason' => 'Token not valid for this content URL',
                'error' => 'scope_mismatch'
            ];
        }
        
        // Get license details
        $license = null;
        if ($tokenData['license_id']) {
            $stmt = $this->db->prepare("SELECT * FROM rsl_licenses WHERE id = ?");
            $stmt->execute([$tokenData['license_id']]);
            $license = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        
        return [
            'authorized' => true,
            'token' => $tokenData,
            'license' => $license,
            'client_name' => $tokenData['client_name'] ?? 'Unknown'
        ];
    }
    
    /**
     * Check if a user agent should be allowed access without a token
     * Based on the site's RSL configuration
     */
    public function checkUserAgent(string $userAgent, string $requestUri, ?int $siteId = null): array {
        // Get RSL configuration for site
        $license = $this->getLicenseForUrl($requestUri, $siteId);
        
        if (!$license) {
            // No RSL configuration, allow by default
            return [
                'requires_license' => false,
                'allowed' => true
            ];
        }
        
        // Parse permits and prohibits
        $permits = json_decode($license['permits'] ?? '{}', true) ?: [];
        $prohibits = json_decode($license['prohibits'] ?? '{}', true) ?: [];
        
        // Detect bot type from user agent
        $botType = $this->detectBotType($userAgent);
        
        // Check if bot type is explicitly permitted
        if ($this->isPermitted($botType, $permits)) {
            return [
                'requires_license' => false,
                'allowed' => true,
                'license' => $license
            ];
        }
        
        // Check if bot type is prohibited
        if ($this->isProhibited($botType, $prohibits)) {
            return [
                'requires_license' => $license['license_server'] ? true : false,
                'allowed' => false,
                'reason' => "Usage type '$botType' is prohibited",
                'license' => $license
            ];
        }
        
        // If license server is configured, require license token
        if ($license['license_server']) {
            return [
                'requires_license' => true,
                'allowed' => false,
                'reason' => 'License token required',
                'license_server' => $license['license_server'],
                'license' => $license
            ];
        }
        
        // Default allow
        return [
            'requires_license' => false,
            'allowed' => true,
            'license' => $license
        ];
    }
    
    /**
     * Get the RSL license configuration for a URL
     */
    public function getLicenseForUrl(string $url, ?int $siteId = null): ?array {
        // First check site-specific licenses
        if ($siteId) {
            $stmt = $this->db->prepare("
                SELECT * FROM rsl_licenses 
                WHERE site_id = ? AND enabled = 1
                AND (content_url_pattern = '*' OR ? LIKE REPLACE(content_url_pattern, '*', '%'))
                ORDER BY priority DESC, content_url_pattern DESC
                LIMIT 1
            ");
            $stmt->execute([$siteId, $url]);
            $license = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if ($license) {
                return $license;
            }
        }
        
        // Fall back to global default
        $stmt = $this->db->query("
            SELECT * FROM rsl_licenses 
            WHERE site_id IS NULL AND enabled = 1 AND is_default = 1
            LIMIT 1
        ");
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Detect the type of bot from user agent
     */
    private function detectBotType(string $userAgent): string {
        $ua = strtolower($userAgent);
        
        // AI crawlers/training
        $aiPatterns = [
            'gptbot', 'chatgpt', 'openai', 'anthropic', 'claude',
            'cohere', 'ai2bot', 'ccbot', 'diffbot', 'bytespider',
            'petalbot', 'amazonbot', 'meta-externalagent'
        ];
        foreach ($aiPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return 'ai-train';
            }
        }
        
        // Search engines
        $searchPatterns = [
            'googlebot', 'bingbot', 'yandexbot', 'baiduspider',
            'duckduckbot', 'slurp', 'sogou', 'exabot'
        ];
        foreach ($searchPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return 'search';
            }
        }
        
        // Social media
        $socialPatterns = [
            'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'pinterest', 'slackbot', 'discordbot', 'telegrambot'
        ];
        foreach ($socialPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return 'social';
            }
        }
        
        // Generic bot detection
        $botPatterns = ['bot', 'crawler', 'spider', 'scraper'];
        foreach ($botPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return 'bot';
            }
        }
        
        return 'human';
    }
    
    /**
     * Check if a bot type is permitted
     */
    private function isPermitted(string $botType, array $permits): bool {
        $usagePermits = $permits['usage'] ?? [];
        
        // 'all' permits everything
        if (in_array('all', $usagePermits)) {
            return true;
        }
        
        // 'ai-all' permits all AI usage
        if ($botType === 'ai-train' && in_array('ai-all', $usagePermits)) {
            return true;
        }
        
        // Direct match
        return in_array($botType, $usagePermits);
    }
    
    /**
     * Check if a bot type is prohibited
     */
    private function isProhibited(string $botType, array $prohibits): bool {
        $usageProhibits = $prohibits['usage'] ?? [];
        
        // 'all' prohibits everything
        if (in_array('all', $usageProhibits)) {
            return true;
        }
        
        // 'ai-all' prohibits all AI usage
        if ($botType === 'ai-train' && in_array('ai-all', $usageProhibits)) {
            return true;
        }
        
        // Direct match
        return in_array($botType, $usageProhibits);
    }
    
    /**
     * Check if URL matches a pattern
     */
    private function urlMatches(string $url, string $pattern): bool {
        if ($pattern === '*') {
            return true;
        }
        
        // Convert glob pattern to regex
        $regex = '/^' . str_replace(
            ['*', '?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/i';
        
        return preg_match($regex, $url) === 1;
    }
    
    /**
     * Generate WWW-Authenticate header for 401 responses
     */
    public function getWWWAuthenticateHeader(string $error, string $description, ?string $licenseServer = null): string {
        $header = 'License';
        
        if ($licenseServer) {
            $header .= ' realm="' . $licenseServer . '"';
        }
        
        $header .= ', error="' . $error . '"';
        $header .= ', error_description="' . addslashes($description) . '"';
        
        return $header;
    }
    
    /**
     * Get discovery information for a site
     */
    public function getDiscoveryConfig(?int $siteId = null): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM rsl_discovery WHERE site_id = ? OR (site_id IS NULL AND ? IS NULL)
        ");
        $stmt->execute([$siteId, $siteId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
