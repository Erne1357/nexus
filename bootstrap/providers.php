<?php

use App\Modules\Academic\AcademicServiceProvider;
use App\Modules\AcademicLoad\AcademicLoadServiceProvider;
use App\Modules\CatalogValidation\CatalogValidationServiceProvider;
use App\Modules\Communication\CommunicationServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Core\Providers\FortifyServiceProvider;
use App\Modules\Demand\DemandServiceProvider;
use App\Modules\Facilities\FacilitiesServiceProvider;
use App\Modules\Faculty\FacultyServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Solver\SolverServiceProvider;
use App\Modules\Staffing\StaffingServiceProvider;
use App\Modules\Students\StudentsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Módulos, en el orden del mapa de dependencias (docs/dominio/modelo-de-datos.md).
    CoreServiceProvider::class,
    FortifyServiceProvider::class, // login de Core (ADR-0004)
    AcademicServiceProvider::class,
    FacilitiesServiceProvider::class,
    FacultyServiceProvider::class,
    StudentsServiceProvider::class,
    CommunicationServiceProvider::class, // a la derecha de Students; nadie depende de él
    ImportsServiceProvider::class,
    CatalogValidationServiceProvider::class,
    DemandServiceProvider::class,
    AcademicLoadServiceProvider::class,
    SchedulingServiceProvider::class,
    StaffingServiceProvider::class, // a la derecha de Scheduling (ADR-0020)
    SolverServiceProvider::class, // a la derecha de Scheduling; nadie depende de él
];
