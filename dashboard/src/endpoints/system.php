<?php
// System management endpoint - reset, restart, etc.

function handleSystem($method, $params, $db) {
    if ($method !== 'POST') {
        sendResponse(['error' => 'Method not allowed'], 405);
    }
    
    $action = $params[0] ?? '';
    
    switch ($action) {
        case 'reset':
            $data = json_decode(file_get_contents('php://input'), true);
            $command = $data['command'] ?? 'reset';
            $autoRestore = $data['auto_restore'] ?? false;
            
            try {
                //check if updater container is running, if not start it
                $checkCmd = "docker ps --filter 'name=waf-updater' --filter 'status=running' --format '{{.Names}}'";
                exec($checkCmd, $checkOutput, $checkReturnCode);
                if ($checkReturnCode !== 0 || empty($checkOutput)) {
                    // Check if updater.yml exists in parent directory
                    $updaterFile = '/compose-files/docker-compose.updater.yml';
                    if (!file_exists($updaterFile)) {
                        throw new Exception('docker-compose.updater.yml not found. Please ensure it is mounted to the dashboard container.');
                                }

                                //find out if system uses docker-compose or docker compose
                                $dcc = 'docker-compose';
                                exec('docker compose version 2>&1', $output, $returnCode);
                                if ($returnCode === 0) {
                                    $dcc = 'docker compose';
                                }

                                // Start updater container to ensure it's running and not destroyed by reset
                                $startCmd = "$dcc -f " . escapeshellarg($updaterFile) . " up -d 2>&1";
                                exec($startCmd, $startOutput, $startReturnCode);
                                
                                // Check if start was successful
                                if ($startReturnCode !== 0) {
                                    throw new Exception('Failed to start updater container: ' . implode("\n", $startOutput));
                                }
                            }

                // Trigger reset via updater container
                $cmd = "docker exec waf-updater /usr/local/bin/updater.sh " . escapeshellarg($command);
                
                // Execute in background so we don't wait for it to complete
                $cmd .= " > /dev/null 2>&1 &";
                
                exec($cmd, $output, $returnCode);
                
                sendResponse([
                    'success' => true,
                    'message' => 'System reset initiated',
                    'command' => $command,
                    'auto_restore' => $autoRestore
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                sendResponse([
                    'success' => false,
                    'error' => 'Failed to trigger reset',
                    'message' => $e->getMessage()
                ]);
            }
            break;
            
        default:
            sendResponse(['error' => 'Unknown action'], 404);
    }
}
