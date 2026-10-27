<?php

use App\Modules\Imports\Http\Controllers\DownloadBatchErrors;
use Illuminate\Support\Facades\Route;

/*
| Rutas de Imports. Quién entra a cada una lo decide su policy (ImportBatchPolicy), no la ruta.
*/

Route::middleware('auth')->prefix('imports')->name('imports.')->group(function () {
    Route::get('/batches/{batch}/errors', DownloadBatchErrors::class)->name('batch-errors');
});
