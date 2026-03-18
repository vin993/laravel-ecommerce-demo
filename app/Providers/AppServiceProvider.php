<?php

namespace App\Providers;

use Barryvdh\Debugbar\Facades\Debugbar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {
	/* Register any application services. */
	public function register(): void {
		$allowedIPs = array_map('trim', explode(',', config('app.debug_allowed_ips')));
		$allowedIPs = array_filter($allowedIPs);
		if (empty($allowedIPs)) {
			return;
		}
	}

	/* Bootstrap any application services. */
	public function boot(): void {
		if (!app()->runningInConsole() || !in_array(request()->server('argv')[1] ?? null, ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback'])) {
			View::addLocation(resource_path('themes/maddparts/views'));
		}

		if (app()->runningUnitTests()) {
			ParallelTesting::setUpTestDatabase(function (string $database, int $token) {
				Artisan::call('db:seed');
			});
		}

		if (!app()->runningInConsole() || !in_array(request()->server('argv')[1] ?? null, ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback'])) {
			View::composer('*', function ($view) {
				if (!$view->offsetExists('oemBrands')) {
					$oemBrands = [
						['name' => 'CFMOTO', 'code' => 'CFMTO', 'logo' => 'CFMTO.png'],
						['name' => 'Honda Powersports', 'code' => 'HOM', 'logo' => 'HOM.png'],
						['name' => 'Kawasaki', 'code' => 'KUS', 'logo' => 'KUS.png'],
						['name' => 'Polaris', 'code' => 'POL', 'logo' => 'POL.png'],
						['name' => 'Ski-Doo / Sea-Doo / Can-Am', 'code' => 'BRP', 'logo' => 'BRP.png'],
						['name' => 'Suzuki Motor of America, Inc. – Marine', 'code' => 'SZM', 'logo' => 'SZM.png'],
						['name' => 'Yamaha', 'code' => 'YAM', 'logo' => 'YAM.png'],
					];
					$view->with('oemBrands', $oemBrands);
				}
				if (!$view->offsetExists('bestSellers')) {
					$view->with('bestSellers', collect());
				}
				if (!$view->offsetExists('cmsContent')) {
					$view->with('cmsContent', collect());
				}
			});
		}
	}
}
