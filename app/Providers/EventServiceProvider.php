<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        'order.created' => [
            \App\Listeners\CreateInvoiceOnOrderSuccess::class,
        ],
    ];

    public function boot()
    {
        parent::boot();
    }
}
