<?php

use Illuminate\Support\Facades\Route;

Route::prefix('demo-catalog')->name('demo-catalog.')->group(function () {
    Route::get('ping', fn () => 'pong')->name('ping');
    Route::livewire('greeting', 'demo-catalog::pages.greeting')->name('greeting');
});
