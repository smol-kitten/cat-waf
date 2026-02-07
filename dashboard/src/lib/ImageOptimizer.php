<?php
/**
 * Image Optimizer
 * Handles WebP/AVIF conversion and image optimization
 */

class ImageOptimizer
{
    private PDO $pdo;
    private string $processor;
    private string $cacheDir = '/var/cache/nginx/images';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->processor = $this->getSetting('image_processor') ?: 'libvips';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get image optimization config for domain
     */
    public function getConfig(int $domainId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM image_optimization_configs WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Set image optimization config for domain
     */
    public function setConfig(int $domainId, array $config): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO image_optimization_configs 
            (domain_id, enabled, webp_enabled, avif_enabled, lazy_loading,
             quality_jpeg, quality_webp, quality_avif, max_width, max_height,
             strip_metadata, preserve_animation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                webp_enabled = VALUES(webp_enabled),
                avif_enabled = VALUES(avif_enabled),
                lazy_loading = VALUES(lazy_loading),
                quality_jpeg = VALUES(quality_jpeg),
                quality_webp = VALUES(quality_webp),
                quality_avif = VALUES(quality_avif),
                max_width = VALUES(max_width),
                max_height = VALUES(max_height),
                strip_metadata = VALUES(strip_metadata),
                preserve_animation = VALUES(preserve_animation)
        ");

        return $stmt->execute([
            $domainId,
            $config['enabled'] ?? false,
            $config['webp_enabled'] ?? true,
            $config['avif_enabled'] ?? false,
            $config['lazy_loading'] ?? true,
            $config['quality_jpeg'] ?? 80,
            $config['quality_webp'] ?? 80,
            $config['quality_avif'] ?? 70,
            $config['max_width'] ?? 2048,
            $config['max_height'] ?? 2048,
            $config['strip_metadata'] ?? true,
            $config['preserve_animation'] ?? true
        ]);
    }

    /**
     * Optimize an image
     */
    public function optimize(string $inputPath, array $options = []): array
    {
        $format = $options['format'] ?? 'webp';
        $quality = $options['quality'] ?? 80;
        $maxWidth = $options['max_width'] ?? 2048;
        $maxHeight = $options['max_height'] ?? 2048;
        $stripMetadata = $options['strip_metadata'] ?? true;

        if (!file_exists($inputPath)) {
            return ['success' => false, 'error' => 'Input file not found'];
        }

        // Generate output path
        $inputHash = md5_file($inputPath);
        $outputFilename = "{$inputHash}_{$maxWidth}x{$maxHeight}_q{$quality}.{$format}";
        $outputPath = "{$this->cacheDir}/{$outputFilename}";

        // Check if already optimized
        if (file_exists($outputPath)) {
            return [
                'success' => true,
                'cached' => true,
                'path' => $outputPath,
                'filename' => $outputFilename
            ];
        }

        // Choose optimization method
        if ($this->processor === 'libvips' && $this->hasVips()) {
            $result = $this->optimizeWithVips($inputPath, $outputPath, $format, $quality, $maxWidth, $maxHeight, $stripMetadata);
        } elseif ($this->hasImageMagick()) {
            $result = $this->optimizeWithImageMagick($inputPath, $outputPath, $format, $quality, $maxWidth, $maxHeight, $stripMetadata);
        } else {
            return ['success' => false, 'error' => 'No image processor available'];
        }

        if ($result['success']) {
            $result['filename'] = $outputFilename;
            $result['original_size'] = filesize($inputPath);
            $result['optimized_size'] = filesize($outputPath);
            $result['savings_percent'] = round((1 - $result['optimized_size'] / $result['original_size']) * 100, 2);
        }

        return $result;
    }

    /**
     * Optimize with libvips (vipsthumbnail)
     */
    private function optimizeWithVips(string $input, string $output, string $format, int $quality, int $maxWidth, int $maxHeight, bool $stripMetadata): array
    {
        $size = "{$maxWidth}x{$maxHeight}";
        $stripFlag = $stripMetadata ? '--strip' : '';

        switch ($format) {
            case 'webp':
                $cmd = "vipsthumbnail '{$input}' -s {$size} {$stripFlag} -o '{$output}[Q={$quality}]' 2>&1";
                break;
            case 'avif':
                $cmd = "vipsthumbnail '{$input}' -s {$size} {$stripFlag} -o '{$output}[Q={$quality}]' 2>&1";
                break;
            case 'jpg':
            case 'jpeg':
                $cmd = "vipsthumbnail '{$input}' -s {$size} {$stripFlag} -o '{$output}[Q={$quality},optimize_coding]' 2>&1";
                break;
            default:
                $cmd = "vipsthumbnail '{$input}' -s {$size} {$stripFlag} -o '{$output}' 2>&1";
        }

        exec($cmd, $output_lines, $exitCode);

        if ($exitCode !== 0 || !file_exists($output)) {
            return ['success' => false, 'error' => implode("\n", $output_lines)];
        }

        return ['success' => true, 'path' => $output, 'method' => 'libvips'];
    }

    /**
     * Optimize with ImageMagick
     */
    private function optimizeWithImageMagick(string $input, string $output, string $format, int $quality, int $maxWidth, int $maxHeight, bool $stripMetadata): array
    {
        $resize = "-resize {$maxWidth}x{$maxHeight}\\>";
        $stripFlag = $stripMetadata ? '-strip' : '';
        $qualityFlag = "-quality {$quality}";

        switch ($format) {
            case 'webp':
                $cmd = "convert '{$input}' {$resize} {$stripFlag} {$qualityFlag} 'webp:{$output}' 2>&1";
                break;
            case 'avif':
                $cmd = "convert '{$input}' {$resize} {$stripFlag} {$qualityFlag} 'avif:{$output}' 2>&1";
                break;
            default:
                $cmd = "convert '{$input}' {$resize} {$stripFlag} {$qualityFlag} '{$output}' 2>&1";
        }

        exec($cmd, $output_lines, $exitCode);

        if ($exitCode !== 0 || !file_exists($output)) {
            return ['success' => false, 'error' => implode("\n", $output_lines)];
        }

        return ['success' => true, 'path' => $output, 'method' => 'imagemagick'];
    }

    /**
     * Convert image to WebP
     */
    public function toWebP(string $inputPath, int $quality = 80): array
    {
        return $this->optimize($inputPath, ['format' => 'webp', 'quality' => $quality]);
    }

    /**
     * Convert image to AVIF
     */
    public function toAVIF(string $inputPath, int $quality = 70): array
    {
        return $this->optimize($inputPath, ['format' => 'avif', 'quality' => $quality]);
    }

    /**
     * Get optimized image for request (with Accept header negotiation)
     */
    public function getOptimizedForRequest(string $inputPath, string $acceptHeader, array $config): array
    {
        $supportsWebP = strpos($acceptHeader, 'image/webp') !== false;
        $supportsAVIF = strpos($acceptHeader, 'image/avif') !== false;

        $format = 'jpeg';
        $quality = $config['quality_jpeg'] ?? 80;

        if ($supportsAVIF && ($config['avif_enabled'] ?? false)) {
            $format = 'avif';
            $quality = $config['quality_avif'] ?? 70;
        } elseif ($supportsWebP && ($config['webp_enabled'] ?? true)) {
            $format = 'webp';
            $quality = $config['quality_webp'] ?? 80;
        }

        return $this->optimize($inputPath, [
            'format' => $format,
            'quality' => $quality,
            'max_width' => $config['max_width'] ?? 2048,
            'max_height' => $config['max_height'] ?? 2048,
            'strip_metadata' => $config['strip_metadata'] ?? true
        ]);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $stats = [
            'cache_dir' => $this->cacheDir,
            'total_size' => 0,
            'file_count' => 0,
            'by_format' => []
        ];

        if (!is_dir($this->cacheDir)) {
            return $stats;
        }

        $files = glob("{$this->cacheDir}/*");
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $stats['file_count']++;
                $size = filesize($file);
                $stats['total_size'] += $size;

                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (!isset($stats['by_format'][$ext])) {
                    $stats['by_format'][$ext] = ['count' => 0, 'size' => 0];
                }
                $stats['by_format'][$ext]['count']++;
                $stats['by_format'][$ext]['size'] += $size;
            }
        }

        $stats['total_size_mb'] = round($stats['total_size'] / 1024 / 1024, 2);

        return $stats;
    }

    /**
     * Clear image cache
     */
    public function clearCache(?string $format = null): int
    {
        $cleared = 0;
        $pattern = $format ? "{$this->cacheDir}/*.{$format}" : "{$this->cacheDir}/*";
        
        foreach (glob($pattern) as $file) {
            if (is_file($file)) {
                unlink($file);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * Generate nginx image optimization config
     */
    public function generateNginxConfig(int $domainId): string
    {
        $config = $this->getConfig($domainId);
        
        if (!$config || !$config['enabled']) {
            return "# Image optimization disabled\n";
        }

        $nginxConfig = "
# Image optimization configuration
location ~* \\.(jpg|jpeg|png|gif)$ {
    # Check if WebP/AVIF version exists
    set \$webp_suffix \"\";
    set \$avif_suffix \"\";
";

        if ($config['avif_enabled']) {
            $nginxConfig .= "
    if (\$http_accept ~* \"image/avif\") {
        set \$avif_suffix \".avif\";
    }
";
        }

        if ($config['webp_enabled']) {
            $nginxConfig .= "
    if (\$http_accept ~* \"image/webp\") {
        set \$webp_suffix \".webp\";
    }
";
        }

        if ($config['lazy_loading']) {
            $nginxConfig .= "
    # Add lazy loading hint
    add_header Link \"<\$uri>; rel=preload; as=image\";
";
        }

        $nginxConfig .= "
    # Try optimized version first
    try_files \$uri\$avif_suffix \$uri\$webp_suffix \$uri =404;
    
    # Cache optimized images
    expires 30d;
    add_header Cache-Control \"public, immutable\";
    add_header Vary Accept;
}
";

        return $nginxConfig;
    }

    /**
     * Check if libvips is available
     */
    private function hasVips(): bool
    {
        exec('which vipsthumbnail 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Check if ImageMagick is available
     */
    private function hasImageMagick(): bool
    {
        exec('which convert 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Get available processors
     */
    public function getAvailableProcessors(): array
    {
        $processors = [];
        
        if ($this->hasVips()) {
            exec('vips --version 2>&1', $output);
            $processors['libvips'] = [
                'available' => true,
                'version' => $output[0] ?? 'unknown'
            ];
        } else {
            $processors['libvips'] = ['available' => false];
        }

        if ($this->hasImageMagick()) {
            exec('convert --version 2>&1', $output);
            $processors['imagemagick'] = [
                'available' => true,
                'version' => $output[0] ?? 'unknown'
            ];
        } else {
            $processors['imagemagick'] = ['available' => false];
        }

        return $processors;
    }

    private function getSetting(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: null;
    }
}
