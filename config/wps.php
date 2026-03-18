<?php

return [
    'api_url' => env('WPS_API_BASE_URL', 'https://api.wps-inc.com'),
    'api_key' => env('WPS_API_TOKEN'),
    'hold_order' => (bool) env('WPS_HOLD_ORDER', false),
];
