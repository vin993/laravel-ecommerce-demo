<?php

return [
    'stripe' => [
        'code'  => 'stripe',
        'title' => 'Credit Card',
        'class' => \App\Payment\StripePaymentMethod::class,
        'sort'  => 1,
        'active' => true,
    ],

    'paypal' => [
        'code'  => 'paypal',
        'title' => 'PayPal',
        'class' => \App\Payment\PaypalPaymentMethod::class,
        'sort'  => 2,
        'active' => true,
    ],
];
