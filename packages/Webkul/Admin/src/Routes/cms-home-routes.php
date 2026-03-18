<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\CmsHomeContentController;

Route::controller(CmsHomeContentController::class)->prefix('cms/home-content')->group(function () {
    Route::get('/', 'index')->name('admin.cms.home.index');
    Route::get('create', 'create')->name('admin.cms.home.create');
    Route::post('create', 'store')->name('admin.cms.home.store');
    Route::get('edit/{id}', 'edit')->name('admin.cms.home.edit');
    Route::put('edit/{id}', 'update')->name('admin.cms.home.update');
    Route::delete('edit/{id}', 'delete')->name('admin.cms.home.delete');
    Route::post('clear-cache', 'clearCache')->name('admin.cms.home.clear-cache');
});
