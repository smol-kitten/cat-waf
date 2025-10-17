<?php
// JavaScript Challenge Verification API
// POST /api/challenge/verify - Verify proof-of-work solution
// GET /api/challenge/check - Check if user is bypassed (CF IP, whitelist, etc.)

function handleChallenge($method, $params, $db) {
    $action = $params[0] ?? 'verify';
    
    switch ($action) {
        case 'verify':
            if ($method !== 'POST') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            verifyChallenge($db);
            break;
            
        case 'check':
            if ($method !== 'GET') {
                sendResponse(['error' => 'Method not allowed'], 405);
            }
            checkBypass($db);
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}

function verifyChallenge($db) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $challengeId = $input['challenge_id'] ?? '';
    $nonce = $input['nonce'] ?? 0;
    $clientHash = $input['hash'] ?? '';
    
    if (empty($challengeId) || empty($clientHash)) {
        sendResponse(['error' => 'Missing parameters'], 400);
    }
    
    // Verify the hash
    $expectedInput = $challengeId . ':' . $nonce;
    $serverHash = hash('sha256', $expectedInput);
    
    if ($serverHash !== $clientHash) {
        sendResponse([
            'verified' => false,
            'error' => 'Hash mismatch'
        ], 400);
    }
    
    // Check difficulty (at least 16 leading zeros = difficulty 4)
    $leadingZeros = strlen($serverHash) - strlen(ltrim($serverHash, '0'));
    $minDifficulty = 16; // Configurable per site
    
    if ($leadingZeros < $minDifficulty) {
        sendResponse([
            'verified' => false,
            'error' => 'Insufficient difficulty'
        ], 400);
    }
    
    // Generate verification token
    $token = bin2hex(random_bytes(32));
    $duration = 3600; // 1 hour
    $expiresAt = time() + $duration;
    
    // Store challenge solution (optional, for rate limiting)
    try {
        $stmt = $db->prepare("
            INSERT INTO challenge_verifications (
                challenge_id, nonce, hash, token, ip_address, user_agent, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
        ");
        
        $stmt->execute([
            $challengeId,
            $nonce,
            $clientHash,
            $token,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $expiresAt
        ]);
    } catch (PDOException $e) {
        // Table doesn't exist yet, continue anyway
        error_log("Challenge verification storage failed: " . $e->getMessage());
    }
    
    sendResponse([
        'verified' => true,
        'token' => $token,
        'duration' => $duration,
        'difficulty_met' => $leadingZeros
    ]);
}

function checkBypass($db) {
    $challengeId = $_SERVER['HTTP_X_CHALLENGE_ID'] ?? '';
    
    // Check for Cloudflare Connecting IP (bypassed)
    $cfConnectingIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
    if ($cfConnectingIP) {
        sendResponse([
            'bypass' => true,
            'reason' => 'Cloudflare origin'
        ]);
    }
    
    // Check for existing valid token in cookie
    $token = $_COOKIE['challenge_pass'] ?? null;
    if ($token) {
        try {
            $stmt = $db->prepare("
                SELECT * FROM challenge_verifications 
                WHERE token = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $verification = $stmt->fetch();
            
            if ($verification) {
                sendResponse([
                    'bypass' => true,
                    'reason' => 'Valid token'
                ]);
            }
        } catch (PDOException $e) {
            // Continue
        }
    }
    
    // Check IP whitelist
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (isWhitelistedIP($ip, $db)) {
        sendResponse([
            'bypass' => true,
            'reason' => 'Whitelisted IP'
        ]);
    }
    
    // No bypass
    sendResponse([
        'bypass' => false
    ]);
}

function isWhitelistedIP($ip, $db) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count FROM ip_whitelist 
            WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([$ip]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}
