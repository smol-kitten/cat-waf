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

// Helper function to get valid table columns
function getValidColumns($db, $table) {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Exception $e) {
        error_log("Failed to get columns for $table: " . $e->getMessage());
        return [];
    }
}

try {
    // Get valid columns for filtering
    $validColumns = getValidColumns($db, 'sites');
    
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
                        if ($key === 'domain' || $key === 'id' || $key === 'created_at' || $key === 'updated_at') continue;
                        
                        // Skip if column doesn't exist in current schema
                        if (!in_array($key, $validColumns)) {
                            error_log("Skipping non-existent column: $key for site $domain");
                            continue;
                        }
                        
                        // Handle JSON fields
                        if (in_array($key, ['backends', 'custom_config']) && is_array($value)) {
                            $value = json_encode($value);
                        }
                        
                        $updateFields[] = "`{$key}` = ?";
                        $updateValues[] = $value;
                    }
                    
                    if (empty($updateFields)) {
                        error_log("No valid fields to update for $domain");
                        $results['skipped']++;
                        continue;
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
                    // Filter fields to only include columns that exist in schema
                    $fields = [];
                    $values = [];
                    
                    foreach ($site as $key => $value) {
                        // Skip auto-generated fields and non-existent columns
                        if (in_array($key, ['id', 'created_at', 'updated_at'])) continue;
                        if (!in_array($key, $validColumns)) {
                            error_log("Skipping non-existent column: $key for new site $domain");
                            continue;
                        }
                        
                        // Handle JSON fields
                        if (in_array($key, ['backends', 'custom_config']) && is_array($value)) {
                            $value = json_encode($value);
                        }
                        
                        $fields[] = "`$key`";
                        $values[] = $value;
                    }
                    
                    if (empty($fields)) {
                        throw new Exception("No valid fields to insert for $domain");
                    }
                    
                    $placeholders = array_fill(0, count($fields), '?');
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
