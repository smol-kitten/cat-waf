<?php
// Environment defaults endpoint - provides default configuration values from .env
header('Content-Type: application/json');

// Get environment variables with defaults
$defaults = [
    'cloudflare' => [
        'api_key' => getenv('CF_API_KEY') ?: '',
        'email' => getenv('CF_EMAIL') ?: '',
        'has_credentials' => !empty(getenv('CF_API_KEY'))
    ],
    'acme' => [
        'email' => getenv('ACME_EMAIL') ?: 'admin@example.com'
    ]
];

echo json_encode([
    'success' => true,
    'data' => $defaults
]);
