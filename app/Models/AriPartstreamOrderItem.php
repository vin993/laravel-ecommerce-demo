<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Sales\Models\Order;

class AriPartstreamOrderItem extends Model
{
    protected $table = 'ari_partstream_order_items';

    protected $fillable = [
        'order_id',
        'sku',
        'name',
        'brand',
        'quantity',
        'price',
        'total',
        'tax_amount',
        'base_tax_amount',
        'selected_supplier',
        'image_url',
        'return_url',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:4',
        'total' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'base_tax_amount' => 'decimal:4',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
