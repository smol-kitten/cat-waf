<?php
// Update error page templates with actual NGINX error pages
require_once __DIR__ . '/config.php';

// Error pages content from NGINX
$errorPages = [
    '403' => file_get_contents('/etc/nginx/error-pages/403.html'),
    '404' => file_get_contents('/etc/nginx/error-pages/404.html'),
    '429' => file_get_contents('/etc/nginx/error-pages/429.html'),
    '500' => file_get_contents('/etc/nginx/error-pages/500.html'),
    '502' => file_get_contents('/etc/nginx/error-pages/502-no-backend.html'),
    '503' => file_get_contents('/etc/nginx/error-pages/503.html'),
];

try {
    $db = getDbConnection();
    
    // Update the default template
    $stmt = $db->prepare("
        UPDATE error_page_templates 
        SET 
            html_403 = :html_403,
            html_404 = :html_404,
            html_429 = :html_429,
            html_500 = :html_500,
            html_502 = :html_502,
            html_503 = :html_503,
            updated_at = CURRENT_TIMESTAMP
        WHERE name = 'default'
    ");
    
    $stmt->execute([
        'html_403' => $errorPages['403'],
        'html_404' => $errorPages['404'],
        'html_429' => $errorPages['429'],
        'html_500' => $errorPages['500'],
        'html_502' => $errorPages['502'],
        'html_503' => $errorPages['503'],
    ]);
    
    echo "✅ Updated error page templates with actual NGINX error pages\n";
    echo "Rows affected: " . $stmt->rowCount() . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
