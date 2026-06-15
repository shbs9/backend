<?php
$file = '/srv/htdocs/wp-content/muplugins/zeptix-env-guards.php'; 
$status = opcache_get_status(true);
$found = false;

foreach ($status['scripts'] ?? [] as $path => $info) {
    if ($path === $file) {
        $found = true;
        echo "Cached: YES\n";
        echo "Cached timestamp: " . date('Y-m-d H:i:s', $info['timestamp']) . "\n";
        break;
    }
}

if (!$found) {
    echo "Cached: NO (not in OPcache)\n";
}

echo "Disk mtime: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "OPcache enabled: " . (ini_get('opcache.enable') ? 'yes' : 'no') . "\n";
echo "validate_timestamps: " . ini_get('opcache.validate_timestamps') . "\n";
echo "Total cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . "\n";
