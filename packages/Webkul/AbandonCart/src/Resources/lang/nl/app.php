<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Opmerking: Configuraties voor automatische e-mail',
            'days-info'        => 'Ontvang een verlaten winkelwagen tussen vandaag en deze dag.',
            'days'             => 'Dagen voor verlaten winkelwagen',
            'hours-info'       => 'Na deze uren wordt de winkelwagen als verlaten beschouwd.',
            'hours'            => 'Uren voor verlaten winkelwagen',
            'info'             => 'Herinnering per e-mail voor verlaten winkelwagen',
            'second-mail-info' => 'Na deze dagen wordt de tweede e-mail naar de klant gestuurd.',
            'second-mail'      => 'Opvolging voor de tweede e-mail',
            'status'           => 'Status',
            'third-mail-info'  => 'Na deze dagen wordt de derde e-mail naar de klant gestuurd.',
            'third-mail'       => 'Opvolging voor de derde e-mail',
            'title'            => 'Herinnering per e-mail voor verlaten winkelwagen',

            'settings' => [
                'info'  => 'E-mailherinnering voor verlaten winkelwagen. De winkelier kan e-mails sturen naar klanten die het afrekenproces niet hebben voltooid.',
                'title' => 'Instellingen',
            ],

            'general' => [
                'info'  => 'Status instellen om de winkelwagen te verlaten. ',
                'title' => 'Algemeen',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'klantnaam',
            'date'          => 'Datum',
            'id'            => 'ID kaart',
            'mail-sent'     => 'Mail verzonden',
            'no-of-items'   => 'Aantal stuks',
            'no'            => 'Nee',
            'notify'        => 'Melden',
            'send-mail'     => 'Verzend mail',
            'view'          => 'Actie',
            'yes'           => 'Ja',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Account Informatie',
                'cart-info'       => 'Informatie over kar',
                'customer-name'   => 'klantnaam - :first-name :last-name',
                'date'            => 'Datum - :date',
                'discount-amount' => 'Korting hoeveelheid',
                'discount-amount' => 'Korting hoeveelheid',
                'email'           => 'E -mail - :email',
                'grand-total'     => 'Eindtotaal',
                'mail-sent'       => 'Mail verzonden -',
                'price'           => 'Prijs',
                'product-name'    => 'productnaam',
                'products-info'   => 'Producteninformatie',
                'qty'             => 'Hoeveelheid',
                'sku'             => 'Sku',
                'subtotal'        => 'Subtotaal',
                'tax-amount'      => 'Belastingbedrag',
                'tax-percent'     => 'Belastingpercentage',
                'tax'             => 'belasting',
                'title'           => 'Verlaat kar',
                'view-title'      => 'Verlaat kar #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Aangemaakt op',
                    'mail-info'   => 'E-mail informatie',
                    'mail-type'   => 'E-mailtype',
                    'sender-mail' => 'Verzender e-mail',
                    'time-ago'    => 'Enige tijd geleden',
                ],

                'mail' => [
                    'auto-mail'       => 'Opmerking: Configuraties voor automatische e-mail', 
                    'checkout-msg'    => 'Klik hier om uw O te voltooien.',
                    'content'         => 'Je hebt de volgende producten aan je winkelwagen toegevoegd en vergat om te checken.',
                    'place-order'     => 'Plaats bestelling',
                    'something-wrong' => 'Er is iets misgegaan. Controleer alstublieft!',
                    'subject'         => 'Het lijkt erop dat je iets achter hebt gelaten...',
                    'success'         => 'Mail is succesvol verzonden',
                    'thanks'          => 'Bedankt!',
                ],
            ],
        ],
    ],
];