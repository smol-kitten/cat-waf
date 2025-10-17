<?php
// Environment defaults endpoint - provides default configuration values from .env
header('Content-Type: application/json');

// Get environment variables with defaults
$cfToken = getenv('CLOUDFLARE_API_TOKEN') ?: getenv('CF_API_KEY') ?: '';
$defaults = [
    'cloudflare' => [
        'api_key' => $cfToken,
        'email' => getenv('CF_EMAIL') ?: '',
        'has_credentials' => !empty($cfToken)
    ],
    'acme' => [
        'email' => getenv('ACME_EMAIL') ?: 'admin@example.com'
    ]
];

echo json_encode([
    'success' => true,
    'data' => $defaults
]);
