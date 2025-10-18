<?php
// Backup & Import Management API
// GET /api/backup/export - Export full backup as ZIP
// POST /api/backup/import - Import backup from ZIP
// GET /api/backup/info - Get backup metadata without downloading

require_once __DIR__ . '/../config.php';

// Check if backup access is restricted to local IPs
function isBackupAllowed() {
    $db = getDB();
    
    // Check if local-only restriction is enabled
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'backup_local_only'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $localOnly = $result && $result['setting_value'] === '1';
    
    if (!$localOnly) {
        return true; // No restriction
    }
    
    // Get client IP
    $clientIP = $_SERVER['REMOTE_ADDR'];
    
    // Allow local/private IPs
    $allowedPatterns = [
        '/^127\./',           // 127.0.0.0/8
        '/^10\./',            // 10.0.0.0/8
        '/^172\.(1[6-9]|2[0-9]|3[01])\./', // 172.16.0.0/12
        '/^192\.168\./',      // 192.168.0.0/16
        '/^::1$/',            // IPv6 localhost
        '/^fe80:/',           // IPv6 link-local
    ];
    
    foreach ($allowedPatterns as $pattern) {
        if (preg_match($pattern, $clientIP)) {
            return true;
        }
    }
    
    return false;
}

// Check access before processing
if (!isBackupAllowed()) {
    http_response_code(403);
    echo json_encode(['error' => 'Backup access restricted to local network only']);
    exit;
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
// Parse URI without query string
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Parse action from URI
if (preg_match('#/backup/(export|import|info)$#', $requestUri, $matches)) {
    $action = $matches[1];
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint. Use /backup/export, /backup/import, or /backup/info']);
    exit;
}

switch ($method) {
    case 'GET':
        if ($action === 'export') {
            exportBackup();
        } elseif ($action === 'info') {
            getBackupInfo();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed for this endpoint']);
        }
        break;
    
    case 'POST':
        if ($action === 'import') {
            importBackup();
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed for this endpoint']);
        }
        break;
    
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

function exportBackup() {
    $db = getDB();
    
    try {
        // Create temporary directory for backup
        $backupId = date('Y-m-d_His') . '_' . substr(md5(uniqid()), 0, 8);
        $tmpDir = sys_get_temp_dir() . '/catwaf_backup_' . $backupId;
        mkdir($tmpDir, 0755, true);
        
        // Export sites
        $sites = $db->query("SELECT * FROM sites ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($tmpDir . '/sites.json', json_encode($sites, JSON_PRETTY_PRINT));
        
        // Export settings
        $settings = $db->query("SELECT * FROM settings ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($tmpDir . '/settings.json', json_encode($settings, JSON_PRETTY_PRINT));
        
        // Export telemetry (last 30 days only to keep size manageable)
        $cutoffDate = date('Y-m-d H:i:s', strtotime('-30 days'));
        $telemetry = $db->prepare("SELECT * FROM request_telemetry WHERE timestamp >= ? ORDER BY timestamp");
        $telemetry->execute([$cutoffDate]);
        file_put_contents($tmpDir . '/telemetry.json', json_encode($telemetry->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT));
        
        // Export bot detections (last 30 days)
        $botDetections = $db->prepare("SELECT * FROM bot_detections WHERE timestamp >= ? ORDER BY timestamp");
        $botDetections->execute([$cutoffDate]);
        file_put_contents($tmpDir . '/bot_detections.json', json_encode($botDetections->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT));
        
        // Export modsec events (last 30 days)
        $modsecEvents = $db->prepare("SELECT * FROM modsec_events WHERE timestamp >= ? ORDER BY timestamp");
        $modsecEvents->execute([$cutoffDate]);
        file_put_contents($tmpDir . '/modsec_events.json', json_encode($modsecEvents->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT));
        
        // Export access logs (last 7 days only - can be large)
        $cutoffDate7d = date('Y-m-d H:i:s', strtotime('-7 days'));
        $accessLogs = $db->prepare("SELECT * FROM access_logs WHERE timestamp >= ? ORDER BY timestamp LIMIT 100000");
        $accessLogs->execute([$cutoffDate7d]);
        file_put_contents($tmpDir . '/access_logs.json', json_encode($accessLogs->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT));
        
        // Export custom block rules
        $blockRules = $db->query("SELECT * FROM custom_block_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents($tmpDir . '/custom_block_rules.json', json_encode($blockRules, JSON_PRETTY_PRINT));
        
        // Export rate limit rules (if table exists)
        try {
            $rateLimits = $db->query("SELECT * FROM rate_limit_rules ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            file_put_contents($tmpDir . '/rate_limit_rules.json', json_encode($rateLimits, JSON_PRETTY_PRINT));
        } catch (PDOException $e) {
            // Table doesn't exist, skip
            file_put_contents($tmpDir . '/rate_limit_rules.json', json_encode([], JSON_PRETTY_PRINT));
        }
        
        // Create metadata file
        $metadata = [
            'version' => '1.0',
            'export_date' => date('Y-m-d H:i:s'),
            'catwaf_version' => 'v1.0',
            'total_sites' => count($sites),
            'total_settings' => count($settings),
            'telemetry_days' => 30,
            'access_logs_days' => 7,
            'tables_exported' => [
                'sites', 'settings', 'request_telemetry', 'bot_detections', 
                'security_events', 'access_logs', 'custom_block_rules', 'rate_limit_presets'
            ],
            'note' => 'Certificates and cache files are not included in backup'
        ];
        file_put_contents($tmpDir . '/metadata.json', json_encode($metadata, JSON_PRETTY_PRINT));
        
        // Create ZIP archive
        $zipFile = sys_get_temp_dir() . '/catwaf_backup_' . $backupId . '.zip';
        $zip = new ZipArchive();
        
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Failed to create ZIP archive');
        }
        
        // Add all JSON files to ZIP
        $files = glob($tmpDir . '/*.json');
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        
        $zip->close();
        
        // Clean up temporary directory
        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
        
        // Send ZIP file
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="catwaf_backup_' . $backupId . '.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        
        // Clean up ZIP file
        unlink($zipFile);
        exit;
        
    } catch (Exception $e) {
        error_log("Backup export error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to create backup',
            'message' => $e->getMessage()
        ]);
    }
}

function importBackup() {
    $db = getDB();
    
    // Check if file was uploaded
    if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No backup file uploaded']);
        return;
    }
    
    // Get import options from POST data
    $options = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($options)) {
        // Try form data
        $options = [
            'import_sites' => isset($_POST['import_sites']) && $_POST['import_sites'] === 'true',
            'import_settings' => isset($_POST['import_settings']) && $_POST['import_settings'] === 'true',
            'import_telemetry' => isset($_POST['import_telemetry']) && $_POST['import_telemetry'] === 'true',
            'import_bot_detections' => isset($_POST['import_bot_detections']) && $_POST['import_bot_detections'] === 'true',
            'import_modsec_events' => isset($_POST['import_modsec_events']) && $_POST['import_modsec_events'] === 'true',
            'import_access_logs' => isset($_POST['import_access_logs']) && $_POST['import_access_logs'] === 'true',
            'import_block_rules' => isset($_POST['import_block_rules']) && $_POST['import_block_rules'] === 'true',
            'import_rate_limits' => isset($_POST['import_rate_limits']) && $_POST['import_rate_limits'] === 'true',
            'merge_mode' => $_POST['merge_mode'] ?? 'skip' // skip, replace, merge
        ];
    }
    
    // Default: import everything except logs
    if (empty(array_filter($options))) {
        $options = [
            'import_sites' => true,
            'import_settings' => true,
            'import_telemetry' => false,
            'import_bot_detections' => false,
            'import_security_events' => false,
            'import_access_logs' => false,
            'import_block_rules' => true,
            'import_rate_limits' => true,
            'merge_mode' => 'skip'
        ];
    }
    
    try {
        // Extract ZIP to temporary directory
        $tmpDir = sys_get_temp_dir() . '/catwaf_import_' . uniqid();
        mkdir($tmpDir, 0755, true);
        
        $zip = new ZipArchive();
        if ($zip->open($_FILES['backup']['tmp_name']) !== true) {
            throw new Exception('Failed to open backup ZIP file');
        }
        
        $zip->extractTo($tmpDir);
        $zip->close();
        
        // Read metadata
        $metadataFile = $tmpDir . '/metadata.json';
        if (!file_exists($metadataFile)) {
            throw new Exception('Invalid backup file: missing metadata.json');
        }
        
        $metadata = json_decode(file_get_contents($metadataFile), true);
        
        $results = [
            'metadata' => $metadata,
            'imported' => []
        ];
        
        // Import sites
        if ($options['import_sites'] ?? false) {
            $sitesFile = $tmpDir . '/sites.json';
            if (file_exists($sitesFile)) {
                $sitesContent = file_get_contents($sitesFile);
                $sites = json_decode($sitesContent, true);
                
                error_log("Import: Found sites file, size: " . strlen($sitesContent) . " bytes");
                error_log("Import: Decoded " . count($sites) . " sites");
                
                $imported = 0;
                $skipped = 0;
                $errors = [];
                
                // Get valid columns for sites table
                $validColumns = getTableColumns($db, 'sites');
                
                foreach ($sites as $site) {
                    try {
                        // Check if site already exists
                        $existing = $db->prepare("SELECT id FROM sites WHERE domain = ?");
                        $existing->execute([$site['domain']]);
                        
                        if ($existing->fetch()) {
                            if ($options['merge_mode'] === 'skip') {
                                $skipped++;
                                error_log("Import: Skipped existing site: " . $site['domain']);
                                continue;
                            } elseif ($options['merge_mode'] === 'replace') {
                                // Update existing site - filter fields by schema
                                $updateFields = array_keys($site);
                                $updateFields = array_filter($updateFields, fn($f) => $f !== 'id' && $f !== 'created_at' && $f !== 'updated_at');
                                $updateFields = array_intersect($updateFields, $validColumns);
                                
                                if (empty($updateFields)) {
                                    $errors[] = "No valid fields to update for {$site['domain']}";
                                    continue;
                                }
                                
                                $setClause = implode(', ', array_map(fn($f) => "`$f` = ?", $updateFields));
                                $values = array_map(fn($f) => $site[$f], $updateFields);
                                $values[] = $site['domain'];
                                
                                $stmt = $db->prepare("UPDATE sites SET $setClause WHERE domain = ?");
                                $stmt->execute($values);
                                $imported++;
                                error_log("Import: Updated site: " . $site['domain']);
                            }
                        } else {
                            // Insert new site - filter fields by schema
                            unset($site['id']);
                            unset($site['created_at']);
                            unset($site['updated_at']);
                            
                            $fields = array_keys($site);
                            $fields = array_intersect($fields, $validColumns);
                            
                            if (empty($fields)) {
                                $errors[] = "No valid fields to insert for {$site['domain']}";
                                continue;
                            }
                            
                            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                            $fieldsList = implode(', ', array_map(fn($f) => "`$f`", $fields));
                            $values = array_map(fn($f) => $site[$f], $fields);
                            
                            $stmt = $db->prepare("INSERT INTO sites ($fieldsList) VALUES ($placeholders)");
                            $stmt->execute($values);
                            $imported++;
                            error_log("Import: Inserted new site: " . $site['domain']);
                        }
                    } catch (Exception $e) {
                        $errors[] = "Error importing {$site['domain']}: " . $e->getMessage();
                        error_log("Import error for site {$site['domain']}: " . $e->getMessage());
                    }
                }
                
                $results['imported']['sites'] = [
                    'imported' => $imported, 
                    'skipped' => $skipped,
                    'errors' => $errors
                ];
                error_log("Import: Sites summary - imported: $imported, skipped: $skipped, errors: " . count($errors));
            } else {
                error_log("Import: sites.json file not found at: $sitesFile");
                $results['imported']['sites'] = ['error' => 'sites.json not found in backup'];
            }
        } else {
            error_log("Import: Skipping sites (import_sites = false)");
        }
        
        // Import settings
        if ($options['import_settings'] ?? false) {
            $settingsFile = $tmpDir . '/settings.json';
            if (file_exists($settingsFile)) {
                $settings = json_decode(file_get_contents($settingsFile), true);
                $imported = 0;
                
                foreach ($settings as $setting) {
                    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$setting['setting_key'], $setting['setting_value'], $setting['setting_value']]);
                    $imported++;
                }
                
                $results['imported']['settings'] = $imported;
            }
        }
        
        // Import telemetry (if requested - can be large)
        if ($options['import_telemetry'] ?? false) {
            $telemetryFile = $tmpDir . '/telemetry.json';
            if (file_exists($telemetryFile)) {
                $telemetry = json_decode(file_get_contents($telemetryFile), true);
                
                if (!empty($telemetry)) {
                    // Get valid columns and filter
                    $allFields = array_keys($telemetry[0]);
                    $imported = importTable($db, 'request_telemetry', $telemetry, $allFields);
                    $results['imported']['telemetry'] = $imported;
                } else {
                    $results['imported']['telemetry'] = 0;
                }
            }
        }
        
        // Import other tables similarly...
        if ($options['import_bot_detections'] ?? false) {
            $file = $tmpDir . '/bot_detections.json';
            if (file_exists($file)) {
                $records = json_decode(file_get_contents($file), true);
                $imported = importTable($db, 'bot_detections', $records, ['timestamp', 'ip_address', 'user_agent', 'bot_type', 'action', 'domain']);
                $results['imported']['bot_detections'] = $imported;
            }
        }
        
        if ($options['import_security_events'] ?? false) {
            $file = $tmpDir . '/security_events.json';
            if (file_exists($file)) {
                $records = json_decode(file_get_contents($file), true);
                $imported = importTable($db, 'security_events', $records, ['timestamp', 'domain', 'client_ip', 'rule_id', 'rule_message', 'severity']);
                $results['imported']['security_events'] = $imported;
            }
        }
        
        if ($options['import_block_rules'] ?? false) {
            $file = $tmpDir . '/custom_block_rules.json';
            if (file_exists($file)) {
                $records = json_decode(file_get_contents($file), true);
                
                if (!empty($records)) {
                    // Get all fields from first record and use schema-aware import
                    $record = $records[0];
                    unset($record['id']);
                    $allFields = array_keys($record);
                    $imported = importTable($db, 'custom_block_rules', $records, $allFields);
                    $results['imported']['custom_block_rules'] = $imported;
                } else {
                    $results['imported']['custom_block_rules'] = 0;
                }
            }
        }
        
        // Clean up
        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup imported successfully',
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        error_log("Backup import error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to import backup',
            'message' => $e->getMessage()
        ]);
    }
}

function getTableColumns($db, $table) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table`");
        $columns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns[] = $row['Field'];
        }
        return $columns;
    } catch (Exception $e) {
        error_log("Failed to get columns for table $table: " . $e->getMessage());
        return [];
    }
}

function filterFieldsBySchema($db, $table, $fields) {
    $validColumns = getTableColumns($db, $table);
    if (empty($validColumns)) {
        return $fields; // Fallback to original if we can't get schema
    }
    
    // Filter out fields that don't exist in current schema
    $filtered = array_intersect($fields, $validColumns);
    
    $removed = array_diff($fields, $validColumns);
    if (!empty($removed)) {
        error_log("Import: Filtered out non-existent columns for $table: " . implode(', ', $removed));
    }
    
    return array_values($filtered);
}

function importTable($db, $table, $records, $fields) {
    if (empty($records)) return 0;
    
    // Remove auto-generated fields
    $fields = array_filter($fields, fn($f) => !in_array($f, ['id', 'created_at', 'updated_at']));
    
    // Filter fields to only include columns that exist in current schema
    $fields = filterFieldsBySchema($db, $table, $fields);
    
    if (empty($fields)) {
        error_log("Import: No valid fields to import for table $table");
        return 0;
    }
    
    $db->beginTransaction();
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $fieldsList = implode(', ', array_map(fn($f) => "`$f`", $fields));
    $stmt = $db->prepare("INSERT IGNORE INTO $table ($fieldsList) VALUES ($placeholders)");
    
    $imported = 0;
    foreach ($records as $record) {
        try {
            $values = array_map(fn($f) => $record[$f] ?? null, $fields);
            $stmt->execute($values);
            if ($stmt->rowCount() > 0) {
                $imported++;
            }
        } catch (Exception $e) {
            error_log("Import error for $table record: " . $e->getMessage());
        }
    }
    
    $db->commit();
    return $imported;
}

function getBackupInfo() {
    $db = getDB();
    
    try {
        // Get counts for all tables
        $info = [
            'sites' => $db->query("SELECT COUNT(*) FROM sites")->fetchColumn(),
            'settings' => $db->query("SELECT COUNT(*) FROM settings")->fetchColumn(),
            'telemetry_30d' => $db->query("SELECT COUNT(*) FROM request_telemetry WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn(),
            'bot_detections_30d' => $db->query("SELECT COUNT(*) FROM bot_detections WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn(),
            'modsec_events_30d' => $db->query("SELECT COUNT(*) FROM modsec_events WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn(),
            'access_logs_7d' => $db->query("SELECT COUNT(*) FROM access_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
            'custom_block_rules' => $db->query("SELECT COUNT(*) FROM custom_block_rules")->fetchColumn(),
            'rate_limit_rules' => 0, // Table may not exist yet
            'estimated_size_mb' => estimateBackupSize($db)
        ];
        
        echo json_encode([
            'success' => true,
            'info' => $info,
            'note' => 'Backup will include last 30 days of telemetry and 7 days of access logs'
        ]);
        
    } catch (Exception $e) {
        error_log("Backup info error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to get backup info',
            'message' => $e->getMessage()
        ]);
    }
}

function estimateBackupSize($db) {
    // Rough estimation based on table sizes
    $totalSize = 0;
    
    $tables = [
        'sites', 'settings', 'request_telemetry', 'bot_detections',
        'modsec_events', 'access_logs', 'custom_block_rules'
    ];
    
    foreach ($tables as $table) {
        try {
            $result = $db->query("SELECT 
                SUM(data_length + index_length) / 1024 / 1024 AS size_mb
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE() 
                AND table_name = '$table'")->fetch();
            
            if ($result && isset($result['size_mb'])) {
                $totalSize += $result['size_mb'];
            }
        } catch (Exception $e) {
            // Table might not exist, skip
        }
    }
    
    return round($totalSize, 2);
}
