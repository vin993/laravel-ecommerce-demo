<?php

use Illuminate\Support\Facades\Route;
use Webkul\AbandonCart\Http\Controllers\Admin\AbandonCartController;

Route::group(['middleware' => ['admin', 'abandoned'], 'prefix' => 'admin/customers'], function () {
    Route::controller(AbandonCartController::class)->prefix('abandon-cart')->group(function(){
        Route::get('',  'index')->name('admin.customers.abandon-cart.index');

        Route::get('{id}',  'show')->name('admin.customers.abandon-cart.view');

        Route::get('mail/{id}', 'sendMail')->name('admin.sales.abandon-cart.mail');

        Route::post('mass-notify', 'massNotify')->name('admin.customers.abandon-cart.mass-notify');
    });
});