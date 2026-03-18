<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Remarque : Configurations pour le mail automatique',
            'days-info'        => 'Obtenez le panier abandonné entre aujourd’hui et ce jour.',
            'days'             => 'Jours pour panier abandonné',
            'hours-info'       => 'Après ces heures, le panier sera considéré comme abandonné.',
            'hours'            => 'Heures pour panier abandonné',
            'info'             => 'Rappel par email pour panier abandonné',
            'second-mail-info' => 'Après ces jours, le deuxième email sera envoyé au client.',
            'second-mail'      => 'Suivi pour le deuxième email',
            'status'           => 'Statut',
            'third-mail-info'  => 'Après ces jours, le troisième email sera envoyé au client.',
            'third-mail'       => 'Suivi pour le troisième email',
            'title'            => 'Rappel par email pour panier abandonné',

            'settings' => [
                'info'  => 'Rappel par e-mail pour panier abandonné, le propriétaire de la boutique peut envoyer des e-mails aux clients qui n\'ont pas terminé le processus de paiement.',
                'title' => 'Paramètres',
            ],

            'general' => [
                'info'  => "Définissez le statut pour permettre d'abandonner le panier.",
                'title' => 'Générale',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Nom du client',
            'date'          => 'date',
            'id'            => 'Identifiant',
            'mail-sent'     => 'Email envoyé',
            'no-of-items'   => "Nombre d'objets",
            'no'            => 'Non',
            'notify'        => 'Aviser',
            'send-mail'     => 'Envoyer un mail',
            'view'          => 'action',
            'yes'           => 'Oui',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Information sur le compte',
                'cart-info'       => 'Informations sur le chariot',
                'customer-name'   => 'Nom du client - :first-name :last-name',
                'date'            => 'date - :date',
                'discount-amount' => 'Montant de réduction',
                'discount-amount' => 'Montant de réduction',
                'email'           => 'E-mail - :email',
                'grand-total'     => 'Total',
                'mail-sent'       => 'Email envoyé -',
                'price'           => 'Prix',
                'product-name'    => 'Nom de produit',
                'products-info'   => 'Informations sur les produits',
                'qty'             => 'Quantité',
                'sku'             => 'Sku',
                'subtotal'        => 'Total',
                'tax-amount'      => 'Montant de la taxe',
                'tax-percent'     => "Pourcentage d'impôt",
                'tax'             => 'impôt',
                'title'           => 'Chariot abandonné',
                'view-title'      => 'Chariot abandonné#:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Créé à',
                    'mail-info'   => 'Informations sur le courriel',
                    'mail-type'   => 'Type de courriel',
                    'sender-mail' => 'Courriel de l\'expéditeur',
                    'time-ago'    => 'Il y a quelque temps',
                ],

                'mail' => [
                    'auto-mail'       => 'Remarque : Configurations pour le mail automatique',
                    'checkout-msg'    => 'Veuillez cliquer ici pour terminer votre commande.',
                    'content'         => 'Vous avez ajouté les produits suivants à votre panier et oublié de vérifier. ',
                    'place-order'     => 'Passer la commande',
                    'something-wrong' => 'Quelque chose a mal tourné. Veuillez vérifier!',
                    'subject'         => 'On dirait que tu as laissé quelque chose derrière...',
                    'success'         => 'Le courrier a été envoyé avec succès',
                    'thanks'          => 'Merci!',
                ],
            ],
        ],
    ],
];