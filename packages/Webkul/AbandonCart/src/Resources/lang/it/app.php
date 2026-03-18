<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Nota: Configurazioni per la posta automatica',
            'days-info'        => 'Ottieni il carrello abbandonato tra oggi e questo giorno.',
            'days'             => 'Giorni per il carrello abbandonato',
            'hours-info'       => 'Dopo queste ore, il carrello sarà considerato abbandonato.',
            'hours'            => 'Ore per il carrello abbandonato',
            'info'             => 'Promemoria email per carrello abbandonato',
            'second-mail-info' => 'Dopo questi giorni, la seconda email sarà inviata al cliente.',
            'second-mail'      => 'Seguito per la seconda email',
            'status'           => 'Stato',
            'third-mail-info'  => 'Dopo questi giorni, la terza email sarà inviata al cliente.',
            'third-mail'       => 'Seguito per la terza email',
            'title'            => 'Promemoria email per carrello abbandonato',

            'settings' => [
                'info'  => 'Promemoria e-mail per carrello abbandonato, il proprietario del negozio può inviare e-mail ai clienti che non hanno completato il processo di checkout.',
                'title' => 'Impostazioni',
            ],

            'general' => [
                'info'  => 'Imposta lo stato per consentire di abbandonare il carrello.',
                'title' => 'Generale',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Nome del cliente',
            'date'          => 'Data',
            'id'            => 'id',
            'mail-sent'     => 'Posta inviata',
            'no-of-items'   => 'Numero di articoli',
            'no'            => 'NO',
            'notify'        => 'Notificare',
            'send-mail'     => 'Inviare una mail',
            'view'          => 'Azione',
            'yes'           => 'SÌ',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Informazioni account',
                'cart-info'       => 'Informazioni sul carrello',
                'customer-name'   => 'Nome del cliente- :first-name :last-name',
                'date'            => 'Data - :date',
                'discount-amount' => 'Totale sconto',
                'discount-amount' => 'Totale sconto',
                'email'           => 'E-mail - :email',
                'grand-total'     => 'Somma totale',
                'mail-sent'       => 'Posta inviata -',
                'price'           => 'Prezzo',
                'product-name'    => 'nome del prodotto',
                'products-info'   => 'Informazioni sui prodotti',
                'qty'             => 'Quantità',
                'sku'             => 'Sku',
                'subtotal'        => 'totale parziale',
                'tax-amount'      => 'Ammontare della tassa',
                'tax-percent'     => 'Percentuale fiscale',
                'tax'             => 'imposta',
                'title'           => 'abandonCart',
                'view-title'      => 'abandonCart #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Creato il',
                    'mail-info'   => 'Informazioni e-mail',
                    'mail-type'   => 'Tipo di e-mail',
                    'sender-mail' => 'E-mail del mittente',
                    'time-ago'    => 'Tempo fa',
                ],

                'mail' => [
                    'auto-mail'       => 'Nota: Configurazioni per la posta automatica',
                    'checkout-msg'    => 'Fai clic qui per terminare il tuo ordine.',
                    'content'         => 'Hai aggiunto i seguenti prodotti al carrello e hai dimenticato di fare il checkout. ',
                    'place-order'     => 'Invia ordine',
                    'something-wrong' => 'Qualcosa è andato storto. Si prega di controllare!',
                    'subject'         => 'Sembra che tu abbia lasciato qualcosa alle spalle...',
                    'success'         => 'La posta è stata inviata con successo',
                    'thanks'          => 'Grazie!',
                ],
            ],
        ],
    ],
];