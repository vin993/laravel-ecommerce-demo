<?php

namespace App\Models\WPS;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WpsProductItem extends Model
{
    protected $table = 'wps_product_items';
    
    protected $fillable = [
        'wps_product_id',
        'wps_item_id',
        'bagisto_product_id',
        'sku',
        'name',
        'list_price',
        'dealer_price',
        'website_price',
        'cost',
        'special_price',
        'special_price_from',
        'special_price_to',
        'status',
        'drop_ship_eligible',
        'inventory_total',
        'weight',
        'length',
        'width',
        'height',
        'is_new',
        'is_featured',
        'is_available',
        'last_synced_at',
        'sync_errors'
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_errors' => 'array',
        'list_price' => 'decimal:2',
        'dealer_price' => 'decimal:2',
        'website_price' => 'decimal:2',
        'cost' => 'decimal:2',
        'special_price' => 'decimal:2',
        'special_price_from' => 'date',
        'special_price_to' => 'date',
        'drop_ship_eligible' => 'boolean',
        'inventory_total' => 'integer',
        'weight' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'is_new' => 'boolean',
        'is_featured' => 'boolean',
        'is_available' => 'boolean',
    ];

    public function wpsProduct(): BelongsTo
    {
        return $this->belongsTo(WpsProduct::class, 'wps_product_id', 'wps_product_id');
    }

    public function scopeDropShipEligible($query)
    {
        return $query->where('drop_ship_eligible', true);
    }

    public function scopeInStock($query)
    {
        return $query->where('inventory_total', '>', 0);
    }

    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    public function scopeWithDimensions($query)
    {
        return $query->whereNotNull('length')
                    ->whereNotNull('width')
                    ->whereNotNull('height')
                    ->whereNotNull('weight');
    }

    public function isDiscontinued()
    {
        return $this->status === 'NA' && $this->inventory_total > 1;
    }

    public function isOutOfStock()
    {
        return $this->inventory_total <= 0;
    }

    public function hasDimensions()
    {
        return !is_null($this->length) && 
               !is_null($this->width) && 
               !is_null($this->height) && 
               !is_null($this->weight);
    }

    public function getEffectivePrice()
    {
        // Return special price if active, otherwise website price or dealer price
        if ($this->special_price && $this->isSpecialPriceActive()) {
            return $this->special_price;
        }
        
        return $this->website_price ?: $this->dealer_price;
    }

    public function isSpecialPriceActive()
    {
        $now = now()->toDateString();
        
        $fromValid = !$this->special_price_from || $this->special_price_from <= $now;
        $toValid = !$this->special_price_to || $this->special_price_to >= $now;
        
        return $fromValid && $toValid;
    }
}