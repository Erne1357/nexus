<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->middleware('auth')->name('home');
