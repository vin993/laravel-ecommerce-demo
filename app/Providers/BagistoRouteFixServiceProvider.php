<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BagistoRouteFixServiceProvider extends ServiceProvider {
    public function boot(): void {
        if ($this->app->runningInConsole()) {
            return;
        }

        $routes = [
            base_path('packages/Webkul/Shop/src/Routes/api.php'),
            base_path('packages/Webkul/Shop/src/Routes/web.php'),
            base_path('packages/Webkul/Shop/src/Routes/checkout-routes.php'),
            base_path('packages/Webkul/Shop/src/Routes/customer-routes.php'),
            base_path('packages/Webkul/Shop/src/Routes/store-front-routes.php'),
        ];
        foreach ($routes as $route) {
            if (file_exists($route)) {
                Route::middleware(['web', 'shop'])->group($route);
            }
        }
    }
}
