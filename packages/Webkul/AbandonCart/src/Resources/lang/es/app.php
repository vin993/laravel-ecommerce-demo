<?php

return [
    'admin' => [
        'configuration' => [
            'auto-mail'        => 'Nota: Configuraciones para correo automático',
            'days-info'        => 'Obtenga el carrito abandonado entre hoy y este día.',
            'days'             => 'Días para carrito abandonado',
            'hours-info'       => 'Después de estas horas, el carrito será considerado abandonado.',
            'hours'            => 'Horas para carrito abandonado',
            'info'             => 'Recordatorio por correo electrónico de carrito abandonado',
            'second-mail-info' => 'Después de estos días, se enviará el segundo correo al cliente.',
            'second-mail'      => 'Seguimiento para el segundo correo',
            'status'           => 'Estado',
            'third-mail-info'  => 'Después de estos días, se enviará el tercer correo al cliente.',
            'third-mail'       => 'Seguimiento para el tercer correo',
            'title'            => 'Recordatorio por correo electrónico de carrito abandonado',

            'settings' => [
                'info'  => 'Recordatorio de correo electrónico de carrito abandonado. El propietario de la tienda puede enviar correos electrónicos a los clientes que no han completado el proceso de pago.',
                'title' => 'Ajustes',
            ],

            'general' => [
                'info'  => 'Establecer estatus para habilitar el carrito.',
                'title' => 'general',
            ],
        ],

        'datagrid' => [
            'customer-name' => 'Nombre del cliente',
            'date'          => 'Fecha',
            'id'            => 'Identificación',
            'mail-sent'     => 'Correo enviado',
            'no-of-items'   => 'Número de items',
            'no'            => 'no',
            'notify'        => 'Notificar',
            'send-mail'     => 'Enviar correo',
            'view'          => 'Acción',
            'yes'           => 'Sí',
        ],

        'customers' => [
            'abandon-cart' => [
                'account-info'    => 'Información de la cuenta',
                'cart-info'       => 'Información de carro',
                'customer-name'   => 'Nombre del cliente - :first-name :last-name',
                'date'            => 'Fecha - :date',
                'discount-amount' => 'Importe de descuento',
                'discount-amount' => 'Importe de descuento',
                'email'           => 'Correo electrónico - :email',
                'grand-total'     => 'Gran total',
                'mail-sent'       => 'Correo enviado -',
                'price'           => 'Precio',
                'product-name'    => 'nombre del producto',
                'products-info'   => 'Información de productos',
                'qty'             => 'Cantidad',
                'sku'             => 'Sku',
                'subtotal'        => 'Total parcial',
                'tax-amount'      => 'Importe del impuesto',
                'tax-percent'     => 'Porcentaje fiscal',
                'tax'             => 'impuesto',
                'title'           => 'Abandonar el carrito',
                'view-title'      => 'Abandonar el carrito #:abandon_cart_id',

                'view' => [
                    'create-at'   => 'Creado en',
                    'mail-info'   => 'Información del correo',
                    'mail-type'   => 'Tipo de correo',
                    'sender-mail' => 'Correo del remitente',
                    'time-ago'    => 'Hace tiempo',
                ],

                'mail' => [
                    'auto-mail'       => 'Nota: Configuraciones para correo automático',
                    'checkout-msg'    => 'Haga clic aquí para finalizar su pedido.',
                    'content'         => 'Ha agregado los siguientes productos a su carrito y olvidó pagar.',
                    'place-order'     => 'Realizar pedido',
                    'something-wrong' => 'Algo salió mal. ¡Por favor verifica!',
                    'subject'         => 'Parece que dejaste algo atrás...',
                    'success'         => 'El correo ha sido enviado con éxito',
                    'thanks'          => 'Gracias!',
                ],
            ],
        ],
    ],
];