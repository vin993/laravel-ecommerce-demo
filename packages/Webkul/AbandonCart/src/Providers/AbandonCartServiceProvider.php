<?php

namespace Webkul\AbandonCart\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\AbandonCart\Console\Commands\AbandonCartMail;
use Webkul\AbandonCart\Http\Middleware\AbandonCartMIddleware;

class AbandonCartServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        $this->app->register(ModuleServiceProvider::class);

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'abandon_cart');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'abandon_cart');

        Route::middleware('web')->group(__DIR__ . '/../Routes/web.php');

        $router->aliasMiddleware('abandoned', AbandonCartMIddleware::class);

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/menu.php',
            'menu.admin'
        );

        if (core()->getConfigData('abandon_cart.settings.general.status')) {
            Event::listen('checkout.cart.add.after', 'Webkul\AbandonCart\Listeners\Cart@addAfter');

            Event::listen('checkout.order.save.after', 'Webkul\AbandonCart\Listeners\Order@placeAfter');
        }
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConfig();

        $this->registerCommands();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/system.php', 
            'core'
        );
    }

    /**
     * Register the console commands of this package.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AbandonCartMail::class,
            ]);
        }
    }
}