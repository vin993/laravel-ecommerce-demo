<?php

namespace Webkul\AbandonCart\Http\Middleware;

use Closure;

class AbandonCartMIddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        abort_if(! core()->getConfigData('abandon_cart.settings.general.status'), 404);

        return $next($request);
    }
}