<?php

use Illuminate\Support\Facades\Route;

/*
| Rutas de CatalogValidation: la validación de catálogos del departamento. Quién entra lo decide la página
| (CatalogReviewPolicy) en mount() y en cada método, no la ruta.
*/

Route::middleware('auth')->prefix('catalog-validation')->name('catalog-validation.')->group(function () {
    Route::livewire('/', 'catalog-validation::pages.review')->name('review');
});
