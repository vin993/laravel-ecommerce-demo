<?php

namespace Webkul\AbandonCart\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\AbandonCart\Contracts\AbondonedCartMail as AbondonedCartMailContract;

class AbondonedCartMail extends Model implements AbondonedCartMailContract
{
    /**
     * Define table of the model.
     *
     * @var string
     */
    protected $table ='abondon_cart_mails';

    /**
     * Define the fillable property of the model.
     *
     * @var array
     */
    protected $fillable = [
        'sender_mail',
        'cart_id',
        'mail_type',
    ];
}