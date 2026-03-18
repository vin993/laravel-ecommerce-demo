<?php

return [
    'enabled' => env('VIRTUAL_INVENTORY_ENABLED', true),

    'default_quantity' => env('VIRTUAL_INVENTORY_QTY', 10),

    'replenish_threshold' => env('VIRTUAL_INVENTORY_REPLENISH_THRESHOLD', 3),

    'categories' => [
        'accessories',
        'gear',
    ],

    'category_search_terms' => [
        'accessor',
        'gear',
        'apparel',
        'clothing',
    ],
];
