<?php

return [
    [
        'key'  => 'abandon_cart',
        'name' => 'abandon_cart::app.admin.configuration.title',
        'info' => 'abandon_cart::app.admin.configuration.info',
        'sort' => 1,
    ],
    [
        'key'  => 'abandon_cart.settings',
        'name' => 'abandon_cart::app.admin.configuration.settings.title',
        'info' => 'abandon_cart::app.admin.configuration.settings.info',
        'icon' => '',
        'sort' => 1,
    ],
    [
        'key'    => 'abandon_cart.settings.general',
        'name'   => 'abandon_cart::app.admin.configuration.general.title',
        'info'   => 'abandon_cart::app.admin.configuration.settings.info',
        'sort'   => 1,
        'fields' => [
            [
                'name'          => 'status',
                'title'         => 'abandon_cart::app.admin.configuration.status',
                'type'          => 'boolean',
                'channel_based' => true,
                'locale_based'  => true,
            ],
            [
                'name'          => 'hours',
                'title'         => 'abandon_cart::app.admin.configuration.hours',
                'info'          => 'abandon_cart::app.admin.configuration.hours-info',
                'type'          => 'text',
                'validation'    => 'required|between:1,24|numeric',
                'channel_based' => true,
                'locale_based'  => true,
            ],
            [
                'name'          => 'days',
                'title'         => 'abandon_cart::app.admin.configuration.days',
                'info'          => 'abandon_cart::app.admin.configuration.days-info',
                'type'          => 'text',
                'channel_based' => true,
                'locale_based'  => true,
            ],
            [
                'name'          => 'second-mail',
                'title'         => 'abandon_cart::app.admin.configuration.second-mail',
                'info'          => 'abandon_cart::app.admin.configuration.second-mail-info',
                'type'          => 'text',
                'validation'    => 'numeric|min:0',
                'channel_based' => true,
                'locale_based'  => true,
            ],
            [
                'name'          => 'third-mail',
                'title'         => 'abandon_cart::app.admin.configuration.third-mail',
                'info'          => 'abandon_cart::app.admin.configuration.third-mail-info',
                'type'          => 'text',
                'validation'    => 'numeric|min:0',
                'channel_based' => true,
                'locale_based'  => true,
            ],
        ],
    ],
];
