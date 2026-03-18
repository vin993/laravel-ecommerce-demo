<?php

return [
    'api_url' => env('TURN14_API_BASE_URL', 'https://api.turn14.com'),
    'client_id' => env('TURN14_CLIENT_ID'),
    'client_secret' => env('TURN14_CLIENT_SECRET'),
    'timeout' => env('TURN14_API_TIMEOUT', 30),
    'environment' => env('TURN14_ENVIRONMENT', 'testing'),
    'test_mode' => env('TURN14_TEST_MODE', false),
    'payment_method' => env('TURN14_PAYMENT_METHOD', 'open_account'),
];
