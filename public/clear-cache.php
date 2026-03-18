<?php
/**
 * Cache Clear Script
 * Access this file via browser to clear all Laravel caches
 * URL: http://yourdomain.com/clear-cache.php
 *
 * IMPORTANT: Delete this file after use for security!
 */

// Define base path
define('LARAVEL_START', microtime(true));

// Load Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "<!DOCTYPE html>";
echo "<html><head><title>Cache Clear</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;} .error{color:red;} .info{color:blue;}</style>";
echo "</head><body>";
echo "<h1>Laravel Cache Clear</h1>";

try {
    // Clear application cache
    echo "<p class='info'>Clearing application cache...</p>";
    Illuminate\Support\Facades\Artisan::call('cache:clear');
    echo "<p class='success'>✓ Application cache cleared!</p>";

    // Clear config cache
    echo "<p class='info'>Clearing config cache...</p>";
    Illuminate\Support\Facades\Artisan::call('config:clear');
    echo "<p class='success'>✓ Config cache cleared!</p>";

    // Clear route cache
    echo "<p class='info'>Clearing route cache...</p>";
    Illuminate\Support\Facades\Artisan::call('route:clear');
    echo "<p class='success'>✓ Route cache cleared!</p>";

    // Clear view cache
    echo "<p class='info'>Clearing view cache...</p>";
    Illuminate\Support\Facades\Artisan::call('view:clear');
    echo "<p class='success'>✓ View cache cleared!</p>";

    // Clear compiled classes
    echo "<p class='info'>Clearing compiled classes...</p>";
    Illuminate\Support\Facades\Artisan::call('clear-compiled');
    echo "<p class='success'>✓ Compiled classes cleared!</p>";

    // Optimize
    echo "<p class='info'>Optimizing application...</p>";
    Illuminate\Support\Facades\Artisan::call('optimize:clear');
    echo "<p class='success'>✓ Application optimized!</p>";

    echo "<hr>";
    echo "<h2 class='success'>All caches cleared successfully!</h2>";
    echo "<p><strong>IMPORTANT:</strong> Please delete this file (clear-cache.php) for security reasons.</p>";
    echo "<p>You can now test your campaign email functionality.</p>";

} catch (Exception $e) {
    echo "<p class='error'>Error: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
