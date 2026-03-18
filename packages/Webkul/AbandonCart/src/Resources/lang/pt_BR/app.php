<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Nota: Configurações para e-mail automático',
            'days-info'        => 'Obtenha o carrinho abandonado entre hoje e este dia.',
            'days'             => 'Dias para carrinho abandonado',
            'hours-info'       => 'Após essas horas, o carrinho será considerado abandonado.',
            'hours'            => 'Horas para carrinho abandonado',
            'info'             => 'Lembrete de e-mail para carrinho abandonado',
            'second-mail-info' => 'Após esses dias, o segundo e-mail será enviado ao cliente.',
            'second-mail'      => 'Acompanhamento para o segundo e-mail',
            'status'           => 'Status',
            'third-mail-info'  => 'Após esses dias, o terceiro e-mail será enviado ao cliente.',
            'third-mail'       => 'Acompanhamento para o terceiro e-mail',
            'title'            => 'Lembrete de e-mail para carrinho abandonado',

            'settings' => [
                'info'  => 'Lembrete de e-mail de carrinho abandonado, o proprietário da loja pode enviar e-mails para clientes que não concluíram o processo de checkout.',
                'title' => 'Configurações',
            ],

            'general' => [
                'info'  => 'Defina o status para permitir abandonar o carrinho.',
                'title' => 'Em geral',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'nome do cliente',
            'date'          => 'Data',
            'id'            => 'Eu ia',
            'mail-sent'     => 'Mail enviado',
            'no-of-items'   => 'Número de ítens',
            'no'            => 'Não',
            'notify'        => 'Notificar',
            'send-mail'     => 'Enviar correio',
            'view'          => 'Ação',
            'yes'           => 'Sim',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Informação da conta',
                'cart-info'       => 'Informações sobre carrinho',
                'customer-name'   => 'nome do cliente- :first-name :last-name',
                'date'            => 'Data - :date',
                'discount-amount' => 'Valor do desconto',
                'discount-amount' => 'Valor do desconto',
                'email'           => 'E-mail - :email',
                'grand-total'     => 'Total geral',
                'mail-sent'       => 'Mail enviado -',
                'price'           => 'Preço',
                'product-name'    => 'Nome do Produto',
                'products-info'   => 'Informações sobre produtos ',
                'qty'             => 'Quantidade',
                'sku'             => 'Sku',
                'subtotal'        => 'subtotal',
                'tax-amount'      => 'Valor do imposto',
                'tax-percent'     => 'Porcentagem fiscal',
                'tax'             => 'imposto',
                'title'           => 'Abandonar o carrinho',
                'view-title'      => 'Abandonar o carrinho #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Criado em',
                    'mail-info'   => 'Informações do e-mail',
                    'mail-type'   => 'Tipo de e-mail',
                    'sender-mail' => 'E-mail do remetente',
                    'time-ago'    => 'Há algum tempo',
                ],

                'mail' => [
                    'auto-mail'       => 'Nota: Configurações para e-mail automático', 
                    'checkout-msg'    => 'Clique aqui para terminar seu pedido.',
                    'content'         => 'Você adicionou os seguintes produtos ao seu carrinho e esqueceu de fazer o checkout.',
                    'place-order'     => 'Faça a encomenda',
                    'something-wrong' => 'Algo deu errado. Por favor, verifique!',
                    'subject'         => 'Parece que você deixou algo para trás...',
                    'success'         => 'O correio foi enviado com sucesso',
                    'thanks'          => 'Obrigada!',
                ],
            ],
        ],
    ],
];