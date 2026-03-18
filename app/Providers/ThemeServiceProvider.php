<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class ThemeServiceProvider extends ServiceProvider {
	public function boot(): void {
		if (app()->runningInConsole()) {
			return;
		}

		$this->loadViewsFrom(resource_path('themes/maddparts/views'), 'shop');
		$this->loadViewsFrom(resource_path('themes/maddparts/views'), 'maddparts');

		View::addNamespace('layouts', resource_path('themes/maddparts/views/layouts'));

		config(['view.paths' => array_merge(
			[resource_path('themes/maddparts/views')],
			config('view.paths', [])
		)]);
	}
}
