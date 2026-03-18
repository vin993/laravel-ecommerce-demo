<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => '注意：自动邮件的配置',
            'days-info'        => '今日とこの日の間に放置されたカートを取得します。',
            'days'             => '放置カートの日数',
            'hours-info'       => 'これらの時間が経過した後、カートは放置されたと見なされます。',
            'hours'            => '放置カートの時間',
            'info'             => '放置カートのメールリマインダー',
            'second-mail-info' => 'これらの日数後、顧客に2通目のメールが送信されます。',
            'second-mail'      => '2通目のメールのフォローアップ',
            'status'           => 'ステータス',
            'third-mail-info'  => 'これらの日数後、顧客に3通目のメールが送信されます。',
            'third-mail'       => '3通目のメールのフォローアップ',
            'title'            => '放置カートのメールリマインダー',

            'settings' => [
                'info'  => '放棄されたカートのメールリマインダー、ストアオーナーはチェックアウトプロセスを完了できなかった顧客にメールを送信できます。',
                'title' => '設定',
            ],

            'general' => [
                'info'  => 'カートを放棄できるようにステータスを設定します。',
                'title' => '一般的な',
            ],
        ],

        'datagrid' => [
            'customer-name' => '顧客名',
            'date'          => '日付',
            'id'            => 'id',
            'mail-sent'     => 'メールが送信されました',
            'no-of-items'   => 'アイテムの数',
            'no'            => 'いいえ',
            'notify'        => 'Notify',
            'send-mail'     => 'メールを送信します',
            'view'          => 'アクション',
            'yes'           => 'はい',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => '口座情報',
                'cart-info'       => 'カート情報',
                'customer-name'   => '顧客名- :first-name :last-name',
                'date'            => '日付 - :date',
                'discount-amount' => '割引額',
                'discount-amount' => '割引額',
                'email'           => 'Eメール - :email',
                'grand-total'     => '総計',
                'mail-sent'       => 'メールが送信されました -',
                'price'           => '価格',
                'product-name'    => '商品名',
                'products-info'   => '製品情報 ',
                'qty'             => '量',
                'sku'             => 'sku',
                'subtotal'        => '小計',
                'tax-amount'      => '課税額',
                'tax-percent'     => '税率',
                'tax'             => '税',
                'title'           => 'カートを放棄します',
                'view-title'      => 'カートを放棄します #:abandon_cart_id',

                'view' => [
                    'create-at'   => '作成日',
                    'mail-info'   => 'メール情報',
                    'mail-type'   => 'メールの種類',
                    'sender-mail' => '送信者のメール',
                    'time-ago'    => '前の時間',
                ],

                'mail' => [
                    'auto-mail'       => '注意：自动邮件的配置',
                    'checkout-msg'    => '「ここをクリックして注文を完了してください。」',
                    'content'         => '次の製品をカートに追加し、チェックアウトを忘れてしまいました。」',
                    'place-order'     => '注文を配置します',
                    'something-wrong' => '問題が発生しました。ご確認ください！',
                    'subject'         => 'あなたは何かを残したようです。..',
                    'success'         => 'メールは正常に送信されました',
                    'thanks'          => 'ありがとう!',
                ],
            ],
        ],
    ],
];