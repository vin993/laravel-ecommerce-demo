<?php

namespace Webkul\AbandonCart\Providers;

use Konekt\Concord\BaseModuleServiceProvider;

class ModuleServiceProvider extends BaseModuleServiceProvider
{
    /**
     * Define models property.
     *
     * @var array
     */
    protected $models = [
        \Webkul\AbandonCart\Models\AbondonedCartMail::class,
    ];
}