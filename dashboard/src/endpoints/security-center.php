<?php
/**
 * Security Center API - Unified Security Events
 * 
 * Combines events from multiple sources into a single feed:
 * - ModSecurity events (modsec_events)
 * - Access logs (access_logs) with suspicious activity
 * - Bot detections (bot_detections)
 * - Banned IPs (banned_ips)
 * 
 * Routes:
 * GET  /api/security-center/events     - Get unified security events
 * GET  /api/security-center/summary    - Get security summary stats
 * GET  /api/security-center/threats    - Get top threats analysis
 * GET  /api/security-center/timeline   - Get timeline of events
 */

function handleSecurityCenter($method, $params, $db) {
    $action = $params[0] ?? 'events';
    
    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'events':
                    getUnifiedSecurityEvents($db);
                    break;
                case 'summary':
                    getSecuritySummary($db);
                    break;
                case 'threats':
                    getTopThreats($db);
                    break;
                case 'timeline':
                    getEventTimeline($db);
                    break;
                case 'ip':
                    // Get all events for a specific IP
                    if (!empty($params[1])) {
                        getIPInvestigation($db, $params[1]);
                    } else {
                        sendResponse(['error' => 'IP address required'], 400);
                    }
                    break;
                default:
                    sendResponse(['error' => 'Unknown action'], 400);
            }
            break;
            
        default:
            sendResponse(['error' => 'Method not allowed'], 405);
    }
}

/**
 * Get unified security events from all sources
 */
function getUnifiedSecurityEvents($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $type = $_GET['type'] ?? null; // modsec, access, bot, ban
        $severity = $_GET['severity'] ?? null;
        $since = $_GET['since'] ?? '24h';
        
        // Convert since to datetime
        $sinceMap = [
            '1h' => 'DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            '24h' => 'DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            '7d' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
            '30d' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ];
        $sinceClause = $sinceMap[$since] ?? 'DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        
        $events = [];
        
        // 1. ModSecurity Events
        if (!$type || $type === 'modsec' || $type === 'all') {
            $severityFilter = '';
            $params = [];
            if ($severity) {
                $severityFilter = 'AND severity = ?';
                $params[] = $severity;
            }
            
            $stmt = $db->prepare("
                SELECT 
                    'modsec' as event_type,
                    id,
                    timestamp,
                    ip_address,
                    domain,
                    uri as path,
                    method,
                    rule_id,
                    severity,
                    message,
                    action,
                    CASE 
                        WHEN severity = 'CRITICAL' OR action = 'blocked' THEN 'critical'
                        WHEN severity = 'WARNING' THEN 'warning'
                        ELSE 'info'
                    END as level
                FROM modsec_events
                WHERE timestamp > $sinceClause
                $severityFilter
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $params[] = $limit;
            $stmt->execute($params);
            $modsecEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = array_merge($events, $modsecEvents);
        }
        
        // 2. Suspicious Access Logs (4xx/5xx status codes)
        if (!$type || $type === 'access' || $type === 'all') {
            $stmt = $db->prepare("
                SELECT 
                    'access' as event_type,
                    id,
                    timestamp,
                    ip_address,
                    domain,
                    request_uri as path,
                    method,
                    status_code,
                    blocked_reason,
                    CASE 
                        WHEN status_code >= 500 THEN 'critical'
                        WHEN status_code >= 400 THEN 'warning'
                        WHEN blocked = 1 THEN 'warning'
                        ELSE 'info'
                    END as level,
                    CONCAT(method, ' ', COALESCE(request_uri, '/'), ' (', status_code, ')') as message
                FROM access_logs
                WHERE timestamp > $sinceClause
                AND (status_code >= 400 OR blocked = 1)
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $accessEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = array_merge($events, $accessEvents);
        }
        
        // 3. Bot Detections
        if (!$type || $type === 'bot' || $type === 'all') {
            $stmt = $db->prepare("
                SELECT 
                    'bot' as event_type,
                    id,
                    timestamp,
                    ip_address,
                    domain,
                    bot_name,
                    bot_type,
                    confidence,
                    action,
                    CASE 
                        WHEN action = 'blocked' THEN 'warning'
                        WHEN bot_type = 'bad' THEN 'warning'
                        ELSE 'info'
                    END as level,
                    CONCAT('Bot detected: ', COALESCE(bot_name, 'unknown'), ' (', COALESCE(bot_type, 'unknown'), ')') as message
                FROM bot_detections
                WHERE timestamp > $sinceClause
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $botEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = array_merge($events, $botEvents);
        }
        
        // 4. Recent Bans
        if (!$type || $type === 'ban' || $type === 'all') {
            $stmt = $db->prepare("
                SELECT 
                    'ban' as event_type,
                    id,
                    created_at as timestamp,
                    ip_address,
                    reason,
                    jail,
                    banned_by,
                    ban_duration,
                    is_permanent,
                    'critical' as level,
                    CONCAT('IP Banned: ', reason) as message
                FROM banned_ips
                WHERE created_at > $sinceClause
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $banEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $events = array_merge($events, $banEvents);
        }
        
        // Sort all events by timestamp
        usort($events, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Limit total results
        $events = array_slice($events, 0, $limit);
        
        // Add GeoIP data if available
        if (file_exists(__DIR__ . '/../lib/GeoIP.php')) {
            require_once __DIR__ . '/../lib/GeoIP.php';
            foreach ($events as &$event) {
                if (!empty($event['ip_address'])) {
                    $location = GeoIP::lookup($event['ip_address']);
                    if ($location) {
                        $event['country'] = $location['country'] ?? null;
                        $event['countryCode'] = $location['countryCode'] ?? null;
                        $event['city'] = $location['city'] ?? null;
                        $event['flag'] = GeoIP::getFlag($location['countryCode'] ?? '');
                    }
                }
            }
        }
        
        sendResponse([
            'events' => $events,
            'total' => count($events),
            'filters' => [
                'type' => $type,
                'severity' => $severity,
                'since' => $since
            ]
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch security events: ' . $e->getMessage()], 500);
    }
}

/**
 * Get aggregated security summary
 */
function getSecuritySummary($db) {
    try {
        $period = $_GET['period'] ?? '24h';
        $periodMap = [
            '1h' => 'DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            '24h' => 'DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            '7d' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
            '30d' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ];
        $since = $periodMap[$period] ?? 'DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        
        // ModSec stats
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM modsec_events WHERE timestamp > $since");
        $stmt->execute();
        $modsecTotal = $stmt->fetch()['count'] ?? 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM modsec_events WHERE timestamp > $since AND action = 'blocked'");
        $stmt->execute();
        $modsecBlocked = $stmt->fetch()['count'] ?? 0;
        
        // Bot stats
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bot_detections WHERE timestamp > $since");
        $stmt->execute();
        $botTotal = $stmt->fetch()['count'] ?? 0;
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bot_detections WHERE timestamp > $since AND action = 'blocked'");
        $stmt->execute();
        $botBlocked = $stmt->fetch()['count'] ?? 0;
        
        // Access log errors
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM access_logs WHERE timestamp > $since AND status_code >= 400");
        $stmt->execute();
        $accessErrors = $stmt->fetch()['count'] ?? 0;
        
        // Active bans
        $stmt = $db->query("SELECT COUNT(*) as count FROM banned_ips WHERE expires_at IS NULL OR expires_at > NOW()");
        $activeBans = $stmt->fetch()['count'] ?? 0;
        
        // Auto bans today
        $stmt = $db->query("SELECT COUNT(*) as count FROM banned_ips WHERE banned_by = 'auto-ban' AND created_at > DATE(NOW())");
        $autoBansToday = $stmt->fetch()['count'] ?? 0;
        
        // Unique attacking IPs
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT ip_address) as count 
            FROM modsec_events 
            WHERE timestamp > $since AND action = 'blocked'
        ");
        $stmt->execute();
        $uniqueAttackers = $stmt->fetch()['count'] ?? 0;
        
        sendResponse([
            'summary' => [
                'modsec_events' => $modsecTotal,
                'modsec_blocked' => $modsecBlocked,
                'bot_detections' => $botTotal,
                'bot_blocked' => $botBlocked,
                'access_errors' => $accessErrors,
                'active_bans' => $activeBans,
                'auto_bans_today' => $autoBansToday,
                'unique_attackers' => $uniqueAttackers
            ],
            'period' => $period
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch security summary: ' . $e->getMessage()], 500);
    }
}

/**
 * Get top threats analysis
 */
function getTopThreats($db) {
    try {
        $limit = min((int)($_GET['limit'] ?? 10), 50);
        
        // Top attacking IPs
        $stmt = $db->prepare("
            SELECT 
                ip_address,
                COUNT(*) as attack_count,
                MAX(timestamp) as last_seen
            FROM modsec_events
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND action = 'blocked'
            GROUP BY ip_address
            ORDER BY attack_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $topIPs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top triggered rules
        $stmt = $db->prepare("
            SELECT 
                rule_id,
                message as rule_message,
                severity,
                COUNT(*) as trigger_count
            FROM modsec_events
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY rule_id, message, severity
            ORDER BY trigger_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $topRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top targeted domains
        $stmt = $db->prepare("
            SELECT 
                domain,
                COUNT(*) as attack_count
            FROM modsec_events
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND action = 'blocked'
            GROUP BY domain
            ORDER BY attack_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $topDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top bad bots
        $stmt = $db->prepare("
            SELECT 
                bot_name,
                bot_type,
                COUNT(*) as detection_count
            FROM bot_detections
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND action = 'blocked'
            GROUP BY bot_name, bot_type
            ORDER BY detection_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $topBots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'threats' => [
                'top_ips' => $topIPs,
                'top_rules' => $topRules,
                'top_domains' => $topDomains,
                'top_bots' => $topBots
            ]
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch threat analysis: ' . $e->getMessage()], 500);
    }
}

/**
 * Get event timeline for charts
 */
function getEventTimeline($db) {
    try {
        $period = $_GET['period'] ?? '24h';
        
        if ($period === '24h') {
            $interval = 'HOUR';
            $format = '%Y-%m-%d %H:00';
            $points = 24;
        } elseif ($period === '7d') {
            $interval = 'DAY';
            $format = '%Y-%m-%d';
            $points = 7;
        } else {
            $interval = 'DAY';
            $format = '%Y-%m-%d';
            $points = 30;
        }
        
        $periodMap = [
            '24h' => 'DATE_SUB(NOW(), INTERVAL 24 HOUR)',
            '7d' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
            '30d' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)'
        ];
        $since = $periodMap[$period] ?? 'DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        
        // ModSec events timeline
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(timestamp, '$format') as time_bucket,
                COUNT(*) as count,
                SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) as blocked
            FROM modsec_events
            WHERE timestamp > $since
            GROUP BY time_bucket
            ORDER BY time_bucket ASC
        ");
        $stmt->execute();
        $modsecTimeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Bot detections timeline
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(timestamp, '$format') as time_bucket,
                COUNT(*) as count,
                SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) as blocked
            FROM bot_detections
            WHERE timestamp > $since
            GROUP BY time_bucket
            ORDER BY time_bucket ASC
        ");
        $stmt->execute();
        $botTimeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendResponse([
            'timeline' => [
                'modsec' => $modsecTimeline,
                'bots' => $botTimeline
            ],
            'period' => $period
        ]);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to fetch timeline: ' . $e->getMessage()], 500);
    }
}

/**
 * Investigate all activity for a specific IP
 */
function getIPInvestigation($db, $ip) {
    try {
        // Validate IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            sendResponse(['error' => 'Invalid IP address'], 400);
        }
        
        $investigation = [
            'ip' => $ip,
            'is_banned' => false,
            'ban_info' => null,
            'geoip' => null,
            'modsec_events' => [],
            'bot_detections' => [],
            'access_history' => [],
            'summary' => []
        ];
        
        // Check if banned
        $stmt = $db->prepare("
            SELECT * FROM banned_ips 
            WHERE ip_address = ? 
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$ip]);
        $ban = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ban) {
            $investigation['is_banned'] = true;
            $investigation['ban_info'] = $ban;
        }
        
        // Get GeoIP
        if (file_exists(__DIR__ . '/../lib/GeoIP.php')) {
            require_once __DIR__ . '/../lib/GeoIP.php';
            $location = GeoIP::lookup($ip);
            if ($location) {
                $investigation['geoip'] = $location;
            }
        }
        
        // Recent ModSec events
        $stmt = $db->prepare("
            SELECT * FROM modsec_events 
            WHERE ip_address = ? 
            ORDER BY timestamp DESC 
            LIMIT 50
        ");
        $stmt->execute([$ip]);
        $investigation['modsec_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Bot detections
        $stmt = $db->prepare("
            SELECT * FROM bot_detections 
            WHERE ip_address = ? 
            ORDER BY timestamp DESC 
            LIMIT 20
        ");
        $stmt->execute([$ip]);
        $investigation['bot_detections'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent access history
        $stmt = $db->prepare("
            SELECT * FROM access_logs 
            WHERE ip_address = ? 
            ORDER BY timestamp DESC 
            LIMIT 50
        ");
        $stmt->execute([$ip]);
        $investigation['access_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Summary stats
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM modsec_events WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $investigation['summary']['total_modsec_events'] = $stmt->fetch()['count'];
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bot_detections WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $investigation['summary']['total_bot_detections'] = $stmt->fetch()['count'];
        
        $stmt = $db->prepare("SELECT MIN(timestamp) as first_seen, MAX(timestamp) as last_seen FROM modsec_events WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $investigation['summary']['first_seen'] = $row['first_seen'];
        $investigation['summary']['last_seen'] = $row['last_seen'];
        
        sendResponse($investigation);
    } catch (Exception $e) {
        sendResponse(['error' => 'Failed to investigate IP: ' . $e->getMessage()], 500);
    }
}
