<?php
/**
 * Export Sites Configuration
 * 
 * Exports all site configurations to JSON format
 * Useful for backup, migration between environments, or version control
 */

require_once '../config.php';

header('Content-Type: application/json');

// Verify API key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$expectedKey = getenv('DASHBOARD_API_KEY') ?: 'your_secure_api_key_here';
if ($apiKey !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();

try {
    // Get request parameters
    $includeDisabled = $_GET['include_disabled'] ?? false;
    $format = $_GET['format'] ?? 'json'; // json or yaml
    $excludeSecrets = $_GET['exclude_secrets'] ?? false;
    
    // Build query
    $query = "SELECT * FROM sites";
    if (!$includeDisabled) {
        $query .= " WHERE enabled = 1";
    }
    $query .= " ORDER BY domain ASC";
    
    $stmt = $db->query($query);
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process sites
    $exportData = [];
    foreach ($sites as $site) {
        // Parse JSON fields
        if (!empty($site['backends'])) {
            $site['backends'] = json_decode($site['backends'], true);
        }
        if (!empty($site['custom_config'])) {
            $site['custom_config'] = json_decode($site['custom_config'], true);
        }
        
        // Exclude secrets if requested
        if ($excludeSecrets) {
            unset($site['basic_auth_password']);
            unset($site['cf_api_token']);
            $site['cf_api_token'] = '[REDACTED]';
            $site['basic_auth_password'] = '[REDACTED]';
        }
        
        // Remove auto-generated fields
        unset($site['id']);
        unset($site['created_at']);
        unset($site['updated_at']);
        
        $exportData[] = $site;
    }
    
    // Build export object
    $export = [
        'version' => '1.0',
        'exported_at' => date('Y-m-d H:i:s'),
        'count' => count($exportData),
        'sites' => $exportData
    ];
    
    // Handle different formats
    if ($format === 'yaml') {
        // Simple YAML output (can be improved with yaml extension)
        http_response_code(200);
        header('Content-Type: text/yaml');
        header('Content-Disposition: attachment; filename="waf-sites-export-' . date('Y-m-d-His') . '.yaml"');
        
        echo "# WAF Sites Export\n";
        echo "# Generated: " . date('Y-m-d H:i:s') . "\n\n";
        echo "version: '1.0'\n";
        echo "exported_at: " . date('Y-m-d H:i:s') . "\n";
        echo "count: " . count($exportData) . "\n\n";
        echo "sites:\n";
        foreach ($exportData as $site) {
            echo "  - domain: " . $site['domain'] . "\n";
            foreach ($site as $key => $value) {
                if ($key !== 'domain') {
                    if (is_array($value)) {
                        echo "    {$key}: " . json_encode($value) . "\n";
                    } else {
                        echo "    {$key}: " . var_export($value, true) . "\n";
                    }
                }
            }
            echo "\n";
        }
    } else {
        // JSON output (default)
        http_response_code(200);
        
        // If download parameter is set, send as file
        if (isset($_GET['download'])) {
            header('Content-Disposition: attachment; filename="waf-sites-export-' . date('Y-m-d-His') . '.json"');
        }
        
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Export failed',
        'message' => $e->getMessage()
    ]);
}
