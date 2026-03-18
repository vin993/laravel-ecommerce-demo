<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Hinweis: Konfigurationen für automatische E-Mails',
            'days-info'        => 'Erhalten Sie verlassene Warenkörbe zwischen heute und diesem Tag.',
            'days'             => 'Tage für verlassene Warenkörbe',
            'hours-info'       => 'Nach diesen Stunden wird der Warenkorb als verlassen betrachtet.',
            'hours'            => 'Stunden für verlassene Warenkörbe',
            'info'             => 'Erinnerung für verlassene Warenkörbe per E-Mail',
            'second-mail-info' => 'Nach diesen Tagen wird die zweite E-Mail an den Kunden gesendet.',
            'second-mail'      => 'Nachverfolgung für die zweite E-Mail',
            'status'           => 'Status',
            'third-mail-info'  => 'Nach diesen Tagen wird die dritte E-Mail an den Kunden gesendet.',
            'third-mail'       => 'Nachverfolgung für die dritte E-Mail',
            'title'            => 'Erinnerung für verlassene Warenkörbe per E-Mail',

            'settings' => [
                'info'  => 'Erinnerungs-E-Mail für abgebrochene Warenkörbe. Der Ladenbesitzer kann E-Mails an Kunden senden, die den Checkout-Prozess nicht abgeschlossen haben.',
                'title' => 'Einstellungen',
            ],

            'general' => [
                'info'  => 'Legen Sie den Status fest, um den Warenkorb aufzugeben.',
                'title' => 'Allgemein',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Kundenname',
            'date'          => 'Datum',
            'id'            => 'Ausweis',
            'mail-sent'     => 'Mail gesendet',
            'no-of-items'   => 'Anzahl der Teile',
            'no'            => 'NEIN',
            'notify'        => 'Benachrichtigen',
            'send-mail'     => 'Post senden',
            'view'          => 'Aktion',
            'yes'           => 'Ja',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Kontoinformationen',
                'cart-info'       => 'Wageninformationen',
                'customer-name'   => 'Kundenname- :first-name :last-name',
                'date'            => 'Datum - :date',
                'discount-amount' => 'Rabattbetrag ',
                'discount-amount' => 'Rabattbetrag',
                'email'           => 'email - :email',
                'grand-total'     => 'Gesamtsumme',
                'mail-sent'       => 'Mail gesendet -',
                'price'           => 'Preis',
                'product-name'    => 'Produktname',
                'products-info'   => 'Produktinformationen',
                'qty'             => 'Menge',
                'sku'             => 'sku',
                'subtotal'        => 'Zwischensumme',
                'tax-amount'      => 'Steuerbetrag',
                'tax-percent'     => 'Steueranteil',
                'tax'             => 'Steuer',
                'title'           => 'Wagen aufgeben',
                'view-title'      => 'Wagen aufgeben #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Erstellt am',
                    'mail-info'   => 'E-Mail-Informationen',
                    'mail-type'   => 'E-Mail-Typ',
                    'sender-mail' => 'Absender-Mail',
                    'time-ago'    => 'Vor einiger Zeit',
                ],

                'mail' => [
                    'auto-mail'       => 'Hinweis: Konfigurationen für automatische E-Mails',
                    'checkout-msg'    => 'Bitte klicken Sie hier, um Ihre Bestellung zu beenden.',
                    'content'         => 'Sie haben den folgenden Produkten zu Ihrem Warenkorb hinzugefügt und vergessen zu überprüfen. ',
                    'place-order'     => 'Bestellung aufgeben',
                    'something-wrong' => 'Etwas ist schief gelaufen. Bitte überprüfen!',
                    'subject'         => 'Es sieht so aus, als hätten Sie etwas zurückgelassen...',
                    'success'         => 'Mail wurde erfolgreich gesendet',
                    'thanks'          => 'Danke!',
                ],
            ],
        ],
    ],
];