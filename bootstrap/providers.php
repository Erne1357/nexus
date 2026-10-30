<?php

use App\Modules\Academic\AcademicServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Providers\FortifyServiceProvider;
use App\Modules\Facilities\FacilitiesServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Students\StudentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Módulos, en el orden del mapa de dependencias (docs/dominio/modelo-de-datos.md).
    CoreServiceProvider::class,
    FortifyServiceProvider::class, // login de Core (ADR-0004)
    AcademicServiceProvider::class,
    FacilitiesServiceProvider::class,
    StudentsServiceProvider::class,
    ImportsServiceProvider::class,
];
