<?php

return [
    'enabled' => env('AUTOMATED_SYNC_ENABLED', true),
    'time' => env('AUTOMATED_SYNC_TIME', '02:00'),
    'timezone' => env('AUTOMATED_SYNC_TIMEZONE', 'America/Chicago'),
    'email' => env('AUTOMATED_SYNC_EMAIL', 'your@email.com'),
    'retry_attempts' => env('AUTOMATED_SYNC_RETRY_ATTEMPTS', 3),
    'cleanup_after_days' => env('AUTOMATED_SYNC_CLEANUP_AFTER_DAYS', 7),
    'min_free_space_gb' => env('SYNC_STORAGE_MIN_FREE_SPACE_GB', 10),
    'max_execution_time_minutes' => env('SYNC_MAX_EXECUTION_TIME_MINUTES', 120),
];
