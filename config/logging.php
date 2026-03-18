<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', env('LOG_STACK', 'daily')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 30,
            'replace_placeholders' => true,
        ],

        'wps_orders' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wps-orders.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'dropship' => [
            'driver' => 'daily',
            'path' => storage_path('logs/dropship.log'),
            'level' => 'info',
            'days' => 60,
        ],

        'parts_unlimited_orders' => [
            'driver' => 'daily',
            'path' => storage_path('logs/parts-unlimited-orders.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'turn14_orders' => [
            'driver' => 'daily',
            'path' => storage_path('logs/turn14-orders.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'kawasaki_sync' => [
            'driver' => 'daily',
            'path' => storage_path('logs/kawasaki-sync.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'helmet_house_orders' => [
            'driver' => 'daily',
            'path' => storage_path('logs/helmet-house-orders.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://' . env('PAPERTRAIL_URL') . ':' . env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
        'helmet_house' => [
            'driver' => 'daily',
            'path' => storage_path('logs/helmet_house.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],
        'wps' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wps.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
        ],

        'shipstation' => [
            'driver' => 'daily',
            'path' => storage_path('logs/shipstation.log'),
            'level' => 'info',
            'days' => 90,
        ],

        'stripe_webhook' => [
            'driver' => 'daily',
            'path' => storage_path('logs/stripe-webhook.log'),
            'level' => 'debug',
            'days' => 90,
        ],

    ],

];
