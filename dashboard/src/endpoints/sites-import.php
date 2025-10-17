<?php
/**
 * Import Sites Configuration
 * 
 * Imports site configurations from JSON export
 * Supports: insert new, update existing, or replace all
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
    // Get JSON data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['sites'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid import format. Expected JSON with "sites" array']);
        exit;
    }
    
    // Get import mode
    $mode = $_GET['mode'] ?? 'merge'; // merge, replace, skip_existing
    $dryRun = isset($_GET['dry_run']); // Validate without making changes
    
    $results = [
        'mode' => $mode,
        'dry_run' => $dryRun,
        'imported' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
        'sites' => []
    ];
    
    // If replace mode, backup and clear existing
    if ($mode === 'replace' && !$dryRun) {
        // Backup existing sites
        $stmt = $db->query("SELECT * FROM sites");
        $backup = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents('/tmp/sites-backup-' . date('Y-m-d-His') . '.json', json_encode($backup, JSON_PRETTY_PRINT));
        
        // Clear existing (except default "_")
        $db->exec("DELETE FROM sites WHERE domain != '_'");
        $results['backup_created'] = true;
    }
    
    // Process each site
    foreach ($data['sites'] as $site) {
        $domain = $site['domain'] ?? null;
        
        if (!$domain) {
            $results['errors'][] = 'Site missing domain field';
            continue;
        }
        
        try {
            // Check if site exists
            $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ?");
            $stmt->execute([$domain]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                if ($mode === 'skip_existing') {
                    $results['skipped']++;
                    $results['sites'][] = ['domain' => $domain, 'action' => 'skipped'];
                    continue;
                }
                
                // Update existing site
                if (!$dryRun) {
                    $updateFields = [];
                    $updateValues = [];
                    
                    foreach ($site as $key => $value) {
                        if ($key === 'domain') continue;
                        
                        // Handle JSON fields
                        if (in_array($key, ['backends', 'custom_config']) && is_array($value)) {
                            $value = json_encode($value);
                        }
                        
                        $updateFields[] = "{$key} = ?";
                        $updateValues[] = $value;
                    }
                    
                    $updateValues[] = $domain;
                    $sql = "UPDATE sites SET " . implode(', ', $updateFields) . " WHERE domain = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($updateValues);
                }
                
                $results['updated']++;
                $results['sites'][] = ['domain' => $domain, 'action' => 'updated'];
                
            } else {
                // Insert new site
                if (!$dryRun) {
                    $fields = array_keys($site);
                    $placeholders = array_fill(0, count($fields), '?');
                    $values = [];
                    
                    foreach ($site as $key => $value) {
                        // Handle JSON fields
                        if (in_array($key, ['backends', 'custom_config']) && is_array($value)) {
                            $value = json_encode($value);
                        }
                        $values[] = $value;
                    }
                    
                    $sql = "INSERT INTO sites (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($values);
                }
                
                $results['imported']++;
                $results['sites'][] = ['domain' => $domain, 'action' => 'created'];
            }
            
            // Regenerate NGINX config if not dry run
            if (!$dryRun) {
                require_once 'sites.php';
                $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ?");
                $stmt->execute([$domain]);
                $siteId = $stmt->fetchColumn();
                generateSiteConfig($siteId, []);
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Error importing {$domain}: " . $e->getMessage();
            error_log("Import error for {$domain}: " . $e->getMessage());
        }
    }
    
    // Trigger NGINX reload if any changes were made
    if (!$dryRun && ($results['imported'] > 0 || $results['updated'] > 0)) {
        touch("/etc/nginx/sites-enabled/.reload_needed");
    }
    
    http_response_code(200);
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Import error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Import failed',
        'message' => $e->getMessage()
    ]);
}
