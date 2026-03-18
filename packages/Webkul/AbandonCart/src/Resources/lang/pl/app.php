<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Uwaga: Konfiguracje dla automatycznej poczty',
            'days-info'        => 'Pobierz porzucony koszyk między dzisiejszym dniem a tym dniem.',
            'days'             => 'Dni dla porzuconego koszyka',
            'hours-info'       => 'Po tych godzinach koszyk zostanie uznany za porzucony.',
            'hours'            => 'Godziny dla porzuconego koszyka',
            'info'             => 'Przypomnienie e-mail o porzuconym koszyku',
            'second-mail-info' => 'Po tych dniach drugi e-mail zostanie wysłany do klienta.',
            'second-mail'      => 'Kontynuacja drugiego e-maila',
            'status'           => 'Status',
            'third-mail-info'  => 'Po tych dniach trzeci e-mail zostanie wysłany do klienta.',
            'third-mail'       => 'Kontynuacja trzeciego e-maila',
            'title'            => 'Przypomnienie e-mail o porzuconym koszyku',

            'settings' => [
                'info'  => 'Przypomnienie e-mail o porzuconym koszyku, właściciel sklepu może wysyłać e-maile do klientów, którzy nie ukończyli procesu realizacji transakcji.',
                'title' => 'Ustawienia',
            ],

            'general' => [
                'info'  => 'Ustaw status, aby umożliwić porzucenie CART.',
                'title' => 'Ogólny',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Nazwa klienta',
            'date'          => 'Data',
            'id'            => 'ID',
            'mail-sent'     => 'Mail wysłany',
            'no-of-items'   => 'Liczba przedmiotów',
            'no'            => 'NIE',
            'notify'        => 'Notyfikować',
            'send-mail'     => 'Wyślij maila',
            'view'          => 'Działanie',
            'yes'           => 'Tak',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'informacje o koncie',
                'cart-info'       => 'Informacje o wózku',
                'customer-name'   => 'Nazwa klienta - :first-name :last-name',
                'date'            => 'Data - :date',
                'discount-amount' => 'Kwota rabatowa',
                'discount-amount' => 'Kwota rabatowa',
                'email'           => 'E-mail - :email',
                'grand-total'     => 'Łączna suma',
                'mail-sent'       => 'Mail wysłany -',
                'price'           => 'Cena',
                'product-name'    => 'Nazwa produktu',
                'products-info'   => 'Informacje o produktach',
                'qty'             => 'Ilość',
                'sku'             => 'sku',
                'subtotal'        => 'subOgółem',
                'tax-amount'      => 'Wysokość podatku',
                'tax-percent'     => 'Procent podatkowy',
                'tax'             => 'podatek',
                'title'           => 'Porzucić wózek',
                'view-title'      => 'Porzucić wózek #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Utworzono o',
                    'mail-info'   => 'Informacje o e-mailu',
                    'mail-type'   => 'Typ e-maila',
                    'sender-mail' => 'E-mail nadawcy',
                    'time-ago'    => 'Jakiś czas temu',
                ],

                'mail' => [
                    'auto-mail'       => 'Uwaga: Konfiguracje dla automatycznej poczty',
                    'checkout-msg'    => 'Kliknij tutaj, aby zakończyć zamówienie.',
                    'content'         => 'Dodałeś następujące produkty do swojego koszyka i zapomniałeś wymeldować.',
                    'place-order'     => 'Złożyć zamówienie',
                    'something-wrong' => 'Coś poszło nie tak. Proszę sprawdzić!',
                    'subject'         => 'Wygląda na to, że coś zostawiłeś...',
                    'success'         => 'Poczta została wysłana pomyślnie',
                    'thanks'          => 'Dzięki!',
                ],
            ],
        ],
    ],
];