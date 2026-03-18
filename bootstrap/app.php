<?php

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\CustomerAuth;
use App\Http\Middleware\ContactSpamPrevention;
use App\Http\Middleware\NewsletterSpamPrevention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies as BaseEncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Webkul\Core\Http\Middleware\SecureHeaders;
use Webkul\Installer\Http\Middleware\CanInstall;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            '/webhooks/*',
        ]);

        /**
         * Remove the default Laravel middleware that prevents requests during maintenance mode. There are three
         * middlewares in the shop that need to be loaded before this middleware. Therefore, we need to remove this
         * middleware from the list and add the overridden middleware at the end of the list.
         *
         * As of now, this has been added in the Admin and Shop providers. I will look for a better approach in Laravel 11 for this.
         */
        $middleware->remove(PreventRequestsDuringMaintenance::class);

        /**
         * Remove the default Laravel middleware that converts empty strings to null. First, handle all nullable cases,
         * then remove this line.
         */
        $middleware->remove(ConvertEmptyStringsToNull::class);

        $middleware->append(SecureHeaders::class);
        $middleware->append(CanInstall::class);

        /**
         * Add the overridden middleware at the end of the list.
         */
        $middleware->replaceInGroup('web', BaseEncryptCookies::class, EncryptCookies::class);

        /**
         * Add response cache middleware to web group for all GET requests
         */
        $middleware->appendToGroup('web', \Webkul\Shop\Http\Middleware\CacheResponse::class);

        $middleware->alias([
            'customer.auth' => CustomerAuth::class,
            'contact.spam' => ContactSpamPrevention::class,
            'newsletter.spam' => NewsletterSpamPrevention::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $emails = config('automated-sync.email', 'your@email.com');
        $emailList = is_array($emails) ? $emails : explode(',', $emails);

        $schedule->command('sync:automated-ftp-sync')
            ->dailyAt(config('automated-sync.time', '02:00'))
            ->timezone(config('automated-sync.timezone', 'America/Chicago'))
            ->emailOutputOnFailure($emailList)
            ->runInBackground()
            ->withoutOverlapping(120);

        $schedule->command('abandoncart-mail:send')
            ->dailyAt('02:00')
            ->timezone('America/Chicago');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
