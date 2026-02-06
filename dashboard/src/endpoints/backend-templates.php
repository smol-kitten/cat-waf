<?php
// Backend Templates API
// GET /api/backend-templates - Get all templates
// GET /api/backend-templates/:id - Get specific template
// POST /api/backend-templates - Create new template
// PUT /api/backend-templates/:id - Update template
// DELETE /api/backend-templates/:id - Delete template

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Built-in backend templates
$builtInTemplates = [
    [
        'id' => 'default',
        'name' => 'Default Backend',
        'description' => 'Single backend server',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://backend:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => false,
            'health_check_interval' => 30,
            'health_check_path' => '/'
        ]
    ],
    [
        'id' => 'ha-dual',
        'name' => 'High Availability (2 servers)',
        'description' => 'Two backend servers with health checks',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://backend1:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ],
                [
                    'address' => 'http://backend2:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'least_conn',
            'health_check_enabled' => true,
            'health_check_interval' => 15,
            'health_check_path' => '/health'
        ]
    ],
    [
        'id' => 'ha-triple',
        'name' => 'High Availability (3 servers)',
        'description' => 'Three backend servers with round-robin',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://backend1:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ],
                [
                    'address' => 'http://backend2:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ],
                [
                    'address' => 'http://backend3:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => true,
            'health_check_interval' => 20,
            'health_check_path' => '/health'
        ]
    ],
    [
        'id' => 'weighted',
        'name' => 'Weighted Load Balancing',
        'description' => 'Primary + secondary with different weights',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://primary:8080',
                    'weight' => 3,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ],
                [
                    'address' => 'http://secondary:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => true,
            'health_check_interval' => 30,
            'health_check_path' => '/health'
        ]
    ],
    [
        'id' => 'backup',
        'name' => 'Primary + Backup',
        'description' => 'Main server with backup failover',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://primary:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ],
                [
                    'address' => 'http://backup:8080',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => true,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => true,
            'health_check_interval' => 20,
            'health_check_path' => '/health'
        ]
    ],
    [
        'id' => 'docker-compose',
        'name' => 'Docker Compose Service',
        'description' => 'Backend service in same Docker network',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://app:3000',
                    'weight' => 1,
                    'max_fails' => 3,
                    'fail_timeout' => 30,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => false,
            'health_check_interval' => 30,
            'health_check_path' => '/'
        ]
    ],
    [
        'id' => 'static-files',
        'name' => 'Static File Server',
        'description' => 'Serve static files from nginx',
        'config' => [
            'backends' => [
                [
                    'address' => 'http://fileserver:80',
                    'weight' => 1,
                    'max_fails' => 1,
                    'fail_timeout' => 10,
                    'backup' => false,
                    'down' => false
                ]
            ],
            'lb_method' => 'round_robin',
            'health_check_enabled' => false,
            'health_check_interval' => 60,
            'health_check_path' => '/'
        ]
    ]
];

// List all templates
if ($method === 'GET' && preg_match('#/backend-templates/?$#', $requestUri)) {
    echo json_encode([
        'success' => true,
        'templates' => $builtInTemplates
    ]);
    exit;
}

// Get specific template
if ($method === 'GET' && preg_match('#/backend-templates/([^/]+)$#', $requestUri, $matches)) {
    $templateId = $matches[1];
    
    foreach ($builtInTemplates as $template) {
        if ($template['id'] === $templateId) {
            echo json_encode([
                'success' => true,
                'template' => $template
            ]);
            exit;
        }
    }
    
    http_response_code(404);
    echo json_encode(['error' => 'Template not found']);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
