<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Note: Configurations for auto mail',
            'days-info'        => 'Get Abandoned cart between this day till today.',
            'days'             => 'Days for abandoned cart',
            'hours-info'       => 'After these hours, cart will be abandoned.',
            'hours'            => 'Hours for abandoned cart',
            'info'             => 'Abandoned Cart Email reminder',
            'second-mail-info' => 'After these days, second mail will be sent to customer.',
            'second-mail'      => 'Follow Up For Second Mail',
            'status'           => 'Status',
            'third-mail-info'  => 'After these days, third mail will be sent to customer.',
            'third-mail'       => 'Follow Up For Third Mail',
            'title'            => 'Abandoned Cart Email reminder',

            'settings' => [
                'info'  => 'Abandoned Cart Email reminder, the store owner can send emails to customers who have failed to complete the checkout process.',
                'title' => 'Settings',
            ],

            'general' => [
                'info'  => 'Set status to enable to abandoned cart.',
                'title' => 'General',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Customer Name',
            'date'          => 'Date',
            'id'            => 'Id',
            'mail-sent'     => 'Mail Sent',
            'no-of-items'   => 'Number of Items',
            'no'            => 'No',
            'notify'        => 'Notify',
            'send-mail'     => 'Send mail',
            'view'          => 'Action',
            'yes'           => 'Yes',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Account Information',
                'cart-info'       => 'Cart Information',
                'customer-name'   => 'Customer Name - :first-name :last-name',
                'date'            => 'Date - :date',
                'discount-amount' => 'Discount Amount',
                'discount-amount' => 'Discount Amount',
                'email'           => 'Email - :email',
                'grand-total'     => 'Grand Total',
                'mail-sent'       => 'Mail Sent -',
                'price'           => 'Price',
                'product-name'    => 'Product Name',
                'products-info'   => 'Products Information',
                'qty'             => 'Quantity',
                'sku'             => 'SKU',
                'subtotal'        => 'Subtotal',
                'tax-amount'      => 'Tax Amount',
                'tax-percent'     => 'Tax Percent',
                'tax'             => 'Tax',
                'title'           => 'Abandoned Cart',
                'view-title'      => 'Abandoned Cart #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Create At',
                    'mail-info'   => 'Mail Information',
                    'mail-type'   => 'Mail Type',
                    'sender-mail' => 'Sender Mail',
                    'time-ago'    => 'Time Ago',
                ],

                'mail' => [
                    'auto-mail'       => 'Successfully sent :count abandoned cart follow-up emails.',
                    'checkout-msg'    => 'Please click here to finish your order.',
                    'content'         => 'You have added the following products to your cart and forgot to checkout.',
                    'place-order'     => 'Place Order',
                    'something-wrong' => 'Something Went Wrong.Please check!',
                    'subject'         => 'It looks like you left something behind...',
                    'success'         => 'Mail has been sent successfully.',
                    'thanks'          => 'Thanks!',
                ],
            ],
        ],
    ],
];