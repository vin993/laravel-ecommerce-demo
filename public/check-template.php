<?php
/**
 * Template Content Checker
 * Access this file via browser to check template content
 * URL: http://yourdomain.com/check-template.php
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
echo "<html><head><title>Template Content Check</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
echo "table{border-collapse:collapse;width:100%;background:white;}";
echo "th,td{border:1px solid #ddd;padding:12px;text-align:left;}";
echo "th{background:#333;color:white;}";
echo ".content{max-height:200px;overflow:auto;white-space:pre-wrap;}</style>";
echo "</head><body>";
echo "<h1>Email Templates Content</h1>";

try {
    $templates = \Webkul\Marketing\Models\Template::all();

    if ($templates->count() > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Status</th><th>Content Length</th><th>Content Preview</th></tr>";

        foreach ($templates as $template) {
            echo "<tr>";
            echo "<td>{$template->id}</td>";
            echo "<td>{$template->name}</td>";
            echo "<td>{$template->status}</td>";
            echo "<td>" . strlen($template->content) . " chars</td>";
            echo "<td class='content'>" . htmlspecialchars(substr($template->content, 0, 500)) . "...</td>";
            echo "</tr>";
        }

        echo "</table>";
    } else {
        echo "<p>No templates found.</p>";
    }

    echo "<hr>";
    echo "<h2>Campaigns</h2>";

    $campaigns = \Webkul\Marketing\Models\Campaign::with('email_template')->get();

    if ($campaigns->count() > 0) {
        echo "<table>";
        echo "<tr><th>Campaign ID</th><th>Name</th><th>Template ID</th><th>Template Name</th><th>Status</th></tr>";

        foreach ($campaigns as $campaign) {
            echo "<tr>";
            echo "<td>{$campaign->id}</td>";
            echo "<td>{$campaign->name}</td>";
            echo "<td>" . ($campaign->email_template->id ?? 'N/A') . "</td>";
            echo "<td>" . ($campaign->email_template->name ?? 'N/A') . "</td>";
            echo "<td>{$campaign->status}</td>";
            echo "</tr>";
        }

        echo "</table>";
    } else {
        echo "<p>No campaigns found.</p>";
    }

    echo "<hr>";
    echo "<p><strong>IMPORTANT:</strong> Please delete this file (check-template.php) for security reasons.</p>";

} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

echo "</body></html>";
