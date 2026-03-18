<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => '注意：自动邮件的配置',
            'days-info'        => '获取今天和这一天之间的被遗弃购物车。',
            'days'             => '被遗弃购物车的天数',
            'hours-info'       => '经过这些小时后，购物车将被视为遗弃。',
            'hours'            => '被遗弃购物车的小时数',
            'info'             => '被遗弃购物车的电子邮件提醒',
            'second-mail-info' => '在这些天之后，第二封邮件将发送给客户。',
            'second-mail'      => '第二封邮件的跟进',
            'status'           => '状态',
            'third-mail-info'  => '在这些天之后，第三封邮件将发送给客户。',
            'third-mail'       => '第三封邮件的跟进',
            'title'            => '被遗弃购物车的电子邮件提醒',

            'settings' => [
                'info'  => '放弃购物车电子邮件提醒，商店老板可以向未完成结账流程的客户发送电子邮件。',
                'title' => '设置',
            ],

            'general' => [
                'info'  => '设置状态以使其放弃购物车.',
                'title' => '一般的',
            ],
        ],

        'datagrid' => [
            'customer-name' => '顾客姓名',
            'date'          => '日期',
            'id'            => 'ID',
            'mail-sent'     => '发送的邮件',
            'no-of-items'   => '项目数s',
            'no'            => '不',
            'notify'        => '通知',
            'send-mail'     => '发送5月l',
            'view'          => '行动',
            'yes'           => '是的',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => '帐户信息',
                'cart-info'       => '购物车信息',
                'customer-name'   => '顾客姓名- :first-name :last-name',
                'date'            => '日期 - :date',
                'discount-amount' => '折扣金额',
                'discount-amount' => '折扣金额',
                'email'           => '电子邮件 - :email',
                'grand-total'     => '累计',
                'mail-sent'       => '发送的邮件 -',
                'price'           => '价格',
                'product-name'    => '产品名称',
                'products-info'   => '产品信息',
                'qty'             => '数量',
                'sku'             => 'sku',
                'subtotal'        => '小计',
                'tax-amount'      => '税额',
                'tax-percent'     => '税率',
                'tax'             => '税',
                'title'           => '放弃购物车',
                'view-title'      => '放弃购物车#:abandon_cart_id',

                'view' => [
                    'create-at'   => '创建于',
                    'mail-info'   => '邮件信息',
                    'mail-type'   => '邮件类型',
                    'sender-mail' => '发件人邮件',
                    'time-ago'    => '之前的时间',
                ],

                'mail' => [
                    'auto-mail'       => '注意：自动邮件的配置',
                    'checkout-msg'    => '请单击此处完成您的订单',
                    'content'         => '您已将以下产品添加到购物车中，忘了结帐 ',
                    'place-order'     => '下订单',
                    'something-wrong' => '出现问题。请检查！',
                    'subject'         => '看起来您留下了一些东西...',
                    'success'         => '邮件已成功发送',
                    'thanks'          => '谢谢!',
                ],
            ],
        ],
    ],
];