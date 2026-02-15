<?php
/**
 * GeoIP Database Update Task
 * Updates MaxMind GeoIP database
 */

require_once __DIR__ . '/../config.php';

echo "GeoIP Database Update Task\n";

// Check for MaxMind license key
$pdo = getDB();
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'maxmind_license_key'");
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result || empty($result['setting_value'])) {
    echo "No MaxMind license key configured. Skipping update.\n";
    echo "To enable GeoIP updates, add your license key in Settings.\n";
    return true; // Not a failure, just skipped
}

$licenseKey = $result['setting_value'];
$accountId = ''; // Account ID if needed

// GeoIP database paths
$geoipDir = '/usr/share/GeoIP';
$tempDir = '/tmp/geoip-update';
$databases = [
    'GeoLite2-Country' => "{$geoipDir}/GeoLite2-Country.mmdb",
    'GeoLite2-City' => "{$geoipDir}/GeoLite2-City.mmdb",
];

// Create temp directory
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$updated = 0;
$errors = [];

foreach ($databases as $edition => $targetPath) {
    echo "Downloading {$edition}...\n";
    
    // MaxMind download URL
    $url = "https://download.maxmind.com/app/geoip_download?edition_id={$edition}&license_key={$licenseKey}&suffix=tar.gz";
    
    $tempFile = "{$tempDir}/{$edition}.tar.gz";
    
    // Download using curl
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'CatWAF GeoIP Updater'
    ]);
    
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($data)) {
        $errors[] = "{$edition}: HTTP {$httpCode}";
        echo "Failed to download {$edition} (HTTP {$httpCode})\n";
        continue;
    }
    
    // Save to temp file
    file_put_contents($tempFile, $data);
    
    // Extract
    echo "Extracting {$edition}...\n";
    $extractDir = "{$tempDir}/{$edition}";
    @mkdir($extractDir, 0755, true);
    
    $output = shell_exec("tar -xzf {$tempFile} -C {$extractDir} --strip-components=1 2>&1");
    
    $mmdbFile = "{$extractDir}/{$edition}.mmdb";
    if (!file_exists($mmdbFile)) {
        $errors[] = "{$edition}: MMDB file not found after extraction";
        echo "Failed to extract {$edition}\n";
        continue;
    }
    
    // Move to target location
    if (copy($mmdbFile, $targetPath)) {
        echo "Updated {$edition} successfully\n";
        $updated++;
    } else {
        $errors[] = "{$edition}: Failed to copy to target";
        echo "Failed to copy {$edition} to {$targetPath}\n";
    }
    
    // Cleanup temp files
    @unlink($tempFile);
    @unlink($mmdbFile);
    @rmdir($extractDir);
}

// Cleanup temp directory
@rmdir($tempDir);

// Update last refresh timestamp
$pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('geoip_last_updated', NOW()) ON DUPLICATE KEY UPDATE setting_value = NOW()");

echo "\nGeoIP update complete. Updated: {$updated}/" . count($databases) . " databases\n";

if (!empty($errors)) {
    echo "Errors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    return false;
}

return true;
