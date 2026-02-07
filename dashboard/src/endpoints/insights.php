<?php
// Insights API - Separate from Telemetry
// GET /api/insights - Get insights configuration
// POST /api/insights/config - Update insights configuration
// GET /api/insights/basic - Get basic insights (request counts, response times)
// GET /api/insights/extended - Get extended insights (web vitals)
// POST /api/insights/vitals - Submit web vitals data

function handleInsights($method, $params, $db) {
    switch ($method) {
        case 'GET':
            $action = $params[0] ?? 'config';
            
            switch ($action) {
                case 'config':
                    getInsightsConfig($db);
                    break;
                case 'basic':
                    getBasicInsights($db);
                    break;
                case 'extended':
                    getExtendedInsights($db);
                    break;
                default:
                    sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        case 'POST':
            $action = $params[0] ?? '';
            
            switch ($action) {
                case 'config':
                    updateInsightsConfig($db);
                    break;
                case 'vitals':
                    submitWebVitals($db);
                    break;
                default:
                    sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

function getInsightsConfig($db) {
    try {
        $siteId = $_GET['site_id'] ?? null;
        
        if ($siteId) {
            $stmt = $db->prepare("SELECT * FROM insights_config WHERE site_id = ?");
            $stmt->execute([$siteId]);
        } else {
            $stmt = $db->query("SELECT * FROM insights_config WHERE site_id IS NULL");
        }
        
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            // Return default config if none exists
            $config = [
                'level' => 'basic',
                'enabled' => true,
                'collect_web_vitals' => false,
                'collect_user_agent' => true,
                'collect_referrer' => true,
                'retention_days' => 30
            ];
        }
        
        sendResponse(['config' => $config]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch insights config: ' . $e->getMessage()], 500);
    }
}

function updateInsightsConfig($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $siteId = $data['site_id'] ?? null;
        
        // Check if config exists
        if ($siteId) {
            $stmt = $db->prepare("SELECT id FROM insights_config WHERE site_id = ?");
            $stmt->execute([$siteId]);
        } else {
            $stmt = $db->query("SELECT id FROM insights_config WHERE site_id IS NULL");
        }
        
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing config
            $updates = [];
            $values = [];
            
            if (isset($data['level'])) {
                $updates[] = 'level = ?';
                $values[] = $data['level'];
            }
            
            if (isset($data['enabled'])) {
                $updates[] = 'enabled = ?';
                $values[] = $data['enabled'] ? 1 : 0;
            }
            
            if (isset($data['collect_web_vitals'])) {
                $updates[] = 'collect_web_vitals = ?';
                $values[] = $data['collect_web_vitals'] ? 1 : 0;
            }
            
            if (isset($data['collect_user_agent'])) {
                $updates[] = 'collect_user_agent = ?';
                $values[] = $data['collect_user_agent'] ? 1 : 0;
            }
            
            if (isset($data['collect_referrer'])) {
                $updates[] = 'collect_referrer = ?';
                $values[] = $data['collect_referrer'] ? 1 : 0;
            }
            
            if (isset($data['retention_days'])) {
                $updates[] = 'retention_days = ?';
                $values[] = $data['retention_days'];
            }
            
            if (!empty($updates)) {
                $values[] = $exists['id'];
                $sql = "UPDATE insights_config SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($values);
            }
        } else {
            // Insert new config
            $stmt = $db->prepare("
                INSERT INTO insights_config (site_id, level, enabled, collect_web_vitals, collect_user_agent, collect_referrer, retention_days)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $siteId,
                $data['level'] ?? 'basic',
                isset($data['enabled']) ? ($data['enabled'] ? 1 : 0) : 1,
                isset($data['collect_web_vitals']) ? ($data['collect_web_vitals'] ? 1 : 0) : 0,
                isset($data['collect_user_agent']) ? ($data['collect_user_agent'] ? 1 : 0) : 1,
                isset($data['collect_referrer']) ? ($data['collect_referrer'] ? 1 : 0) : 1,
                $data['retention_days'] ?? 30
            ]);
        }
        
        sendResponse(['success' => true, 'message' => 'Insights configuration updated']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to update insights config: ' . $e->getMessage()], 500);
    }
}

function getBasicInsights($db) {
    try {
        $range = $_GET['range'] ?? '24h';
        $siteId = $_GET['site_id'] ?? null;
        
        // Convert range to hours
        $hours = 24;
        if ($range === '1h') $hours = 1;
        elseif ($range === '7d') $hours = 168;
        elseif ($range === '30d') $hours = 720;
        
        $params = [$hours];
        $whereClause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)";
        if ($siteId) {
            $whereClause .= " AND site_id = ?";
            $params[] = (int)$siteId;
        }
        
        // Basic metrics: request count, avg response time, status codes
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                AVG(response_time) as avg_response_time,
                COUNT(DISTINCT ip_address) as unique_visitors,
                COUNT(DISTINCT domain) as unique_domains
            FROM request_telemetry
            $whereClause
        ");
        $stmt->execute($params);
        $basicStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Status code distribution
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN status_code >= 200 AND status_code < 300 THEN 1 ELSE 0 END) as status_2xx,
                SUM(CASE WHEN status_code >= 300 AND status_code < 400 THEN 1 ELSE 0 END) as status_3xx,
                SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) as status_4xx,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as status_5xx
            FROM request_telemetry
            $whereClause
        ");
        $stmt->execute($params);
        $statusCodes = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Top paths
        $stmt = $db->prepare("
            SELECT 
                uri as request_uri,
                COUNT(*) as count,
                AVG(response_time) as avg_time
            FROM request_telemetry
            $whereClause
            GROUP BY uri
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $topPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'basic_stats' => $basicStats,
            'status_codes' => $statusCodes,
            'top_paths' => $topPaths,
            'range' => $range
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch basic insights: ' . $e->getMessage()], 500);
    }
}

function getExtendedInsights($db) {
    try {
        $range = $_GET['range'] ?? '24h';
        $siteId = $_GET['site_id'] ?? null;
        
        // Convert range to hours
        $hours = 24;
        if ($range === '1h') $hours = 1;
        elseif ($range === '7d') $hours = 168;
        elseif ($range === '30d') $hours = 720;
        
        $params = [$hours];
        $whereClause = "WHERE timestamp > DATE_SUB(NOW(), INTERVAL ? HOUR)";
        if ($siteId) {
            $whereClause .= " AND site_id = ?";
            $params[] = (int)$siteId;
        }
        
        // Web Vitals metrics
        $stmt = $db->prepare("
            SELECT 
                AVG(lcp) as avg_lcp,
                AVG(fcp) as avg_fcp,
                AVG(ttfb) as avg_ttfb,
                AVG(cls) as avg_cls,
                AVG(fid) as avg_fid,
                COUNT(*) as sample_count
            FROM web_vitals
            $whereClause
        ");
        $stmt->execute($params);
        $vitals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Device type breakdown
        $stmt = $db->prepare("
            SELECT 
                device_type,
                COUNT(*) as count,
                AVG(lcp) as avg_lcp
            FROM web_vitals
            $whereClause
            GROUP BY device_type
        ");
        $stmt->execute($params);
        $deviceBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top slowest pages by LCP
        $stmt = $db->prepare("
            SELECT 
                domain,
                path,
                AVG(lcp) as avg_lcp,
                COUNT(*) as count
            FROM web_vitals
            $whereClause
            GROUP BY domain, path
            HAVING count >= 3
            ORDER BY avg_lcp DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        $slowestPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'vitals' => $vitals,
            'device_breakdown' => $deviceBreakdown,
            'slowest_pages' => $slowestPages,
            'range' => $range
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch extended insights: ' . $e->getMessage()], 500);
    }
}

function submitWebVitals($db) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['domain'])) {
            sendResponse(['error' => 'Domain is required'], 400);
        }
        
        // Check if web vitals collection is enabled
        $stmt = $db->query("SELECT collect_web_vitals FROM insights_config WHERE site_id IS NULL");
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config || !$config['collect_web_vitals']) {
            sendResponse(['error' => 'Web vitals collection is not enabled'], 403);
        }
        
        // Get site_id from domain
        $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ?");
        $stmt->execute([$data['domain']]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $db->prepare("
            INSERT INTO web_vitals (site_id, domain, path, lcp, fcp, ttfb, cls, fid, user_agent, device_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $site['id'] ?? null,
            $data['domain'],
            $data['path'] ?? '/',
            $data['lcp'] ?? null,
            $data['fcp'] ?? null,
            $data['ttfb'] ?? null,
            $data['cls'] ?? null,
            $data['fid'] ?? null,
            $data['user_agent'] ?? null,
            $data['device_type'] ?? null
        ]);
        
        sendResponse(['success' => true, 'message' => 'Web vitals recorded']);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to submit web vitals: ' . $e->getMessage()], 500);
    }
}
