<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Not: Otomatik posta için yapılandırmalar',
            'days-info'        => 'Bugün ile bu gün arasında terk edilmiş sepeti alın.',
            'days'             => 'Terk edilmiş sepet için günler',
            'hours-info'       => 'Bu saatlerden sonra, sepet terk edilmiş olarak kabul edilecektir.',
            'hours'            => 'Terk edilmiş sepet için saatler',
            'info'             => 'Terk edilmiş sepet için e-posta hatırlatıcı',
            'second-mail-info' => 'Bu günlerden sonra, müşteriye ikinci e-posta gönderilecektir.',
            'second-mail'      => 'İkinci e-posta için takip',
            'status'           => 'Durum',
            'third-mail-info'  => 'Bu günlerden sonra, müşteriye üçüncü e-posta gönderilecektir.',
            'third-mail'       => 'Üçüncü e-posta için takip',
            'title'            => 'Terk edilmiş sepet için e-posta hatırlatıcı',

            'settings' => [
                'info'  => 'Terk Edilen Sepet E-posta Hatırlatıcısı, mağaza sahibi ödeme sürecini tamamlayamayan müşterilere e-posta gönderebilir.',
                'title' => 'Ayarlar',
            ],

            'general' => [
                'info'  => 'Sepet terk etmek için durumu ayarlayın.n.',
                'title' => 'Genel',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'müşteri adı',
            'date'          => 'Tarihh',
            'id'            => 'İD',
            'mail-sent'     => 'Mail gönderildierildi',
            'no-of-items'   => 'Öğe Sayısı',
            'no'            => 'HAYIRYIR',
            'notify'        => 'Bilgilendirmekendirmek',
            'send-mail'     => 'Posta göndermekdermek',
            'view'          => 'Aksiyonn',
            'yes'           => 'Evett',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Hesap Bilgileri',
                'cart-info'       => 'Sepet bilgileri',
                'customer-name'   => 'Müşteri Nam - :first-name :last-name',
                'date'            => 'Tarihh - :date',
                'discount-amount' => 'İndirim tutarı',
                'discount-amount' => 'İndirim tutarı',
                'email'           => 'E -postasta - :email',
                'grand-total'     => 'Genel Toplamm',
                'mail-sent'       => 'Mail gönderildierildi -',
                'price'           => 'Fiyat',
                'product-name'    => 'Ürün adı',
                'products-info'   => 'Ürünler Bilgileriion',
                'qty'             => 'Miktar',
                'sku'             => 'Sukaa',
                'subtotal'        => 'ara toplamam',
                'tax-amount'      => 'Vergi miktarıarı',
                'tax-percent'     => 'Vergi yüzdesisi',
                'tax'             => 'vergigi',
                'title'           => 'Terk sepeti',
                'view-title'      => 'Terk sepeti#:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Oluşturulma Tarihi',
                    'mail-info'   => 'E-posta Bilgisi',
                    'mail-type'   => 'E-posta Türü',
                    'sender-mail' => 'Gönderen E-posta',
                    'time-ago'    => 'Birkaç zaman önce',
                ],

                'mail' => [
                    'auto-mail'       => 'Not: Otomatik posta için yapılandırmalar',
                    'checkout-msg'    => 'Siparişinizi bitirmek için lütfen buraya tıklayınya tıklayın.',
                    'content'         => 'Aşağıdaki ürünleri sepetinize eklediniz ve ödeme yapmayı unuttunuz. ',
                    'place-order'     => 'Sipariş Vermekmek',
                    'something-wrong' => 'Bir şeyler ters gitti. Lütfen kontrol edin!',
                    'subject'         => 'Görünüşe göre geride bir şey bıraktın ... ',
                    'success'         => 'Posta başarıyla gönderildi',
                    'thanks'          => 'Teşekkürlerürler!',
                ],
            ],
        ],
    ],
];