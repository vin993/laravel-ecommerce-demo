<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CustomerAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->guard('customer')->check()) {
            return redirect()->route('customer.session.index')
                ->with('error', 'Please login to access this page.');
        }

        return $next($request);
    }
}
