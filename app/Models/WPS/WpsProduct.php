<?php

namespace App\Models\WPS;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Product;

class WpsProduct extends Model
{
    protected $table = 'wps_products';
    
    protected $fillable = [
        'wps_product_id',
        'bagisto_product_id',
        'name',
        'description',
        'status',
        'last_synced_at',
        'sync_errors',
        'total_items',
        'synced_items'
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_errors' => 'array',
        'wps_product_id' => 'integer',
        'bagisto_product_id' => 'integer',
        'total_items' => 'integer',
        'synced_items' => 'integer'
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WpsProductItem::class, 'wps_product_id', 'wps_product_id');
    }

    // Add this relationship
    public function bagistoProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bagisto_product_id', 'id');
    }

    public function scopeNeedSync($query)
    {
        return $query->where('status', '!=', 'synced')
                    ->orWhere('last_synced_at', '<', now()->subHours(24));
    }

    public function markAsSynced()
    {
        $this->update([
            'status' => 'synced',
            'last_synced_at' => now(),
            'sync_errors' => null
        ]);
    }

    public function markAsError($errors)
    {
        $this->update([
            'status' => 'error',
            'sync_errors' => is_array($errors) ? $errors : [$errors]
        ]);
    }
}