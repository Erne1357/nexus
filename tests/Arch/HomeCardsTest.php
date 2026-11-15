<?php

/*
| Inicio (Core) pinta las tarjetas que ponen los módulos de la derecha al contestar HomeCardsRequested, sin conocerlos
| (CLAUDE.md §4.5; ModulesTest revisa además el texto de todo Core). La tarjeta de la validación se arma solo con
| lecturas: su listener no usa Actions.
*/

arch('the home cards event and its card do not depend on the modules that answer it')
    ->expect(['App\Modules\Core\Events\HomeCardsRequested', 'App\Modules\Core\Support\HomeCard'])
    ->not->toUse(['App\Modules\CatalogValidation', 'App\Modules\Faculty', 'App\Modules\AcademicLoad']);

arch('the catalog validation home card only reads')
    ->expect('App\Modules\CatalogValidation\Listeners\ReportValidationHomeCard')
    ->not->toUse(['App\Modules\CatalogValidation\Actions', 'Illuminate\Support\Facades\DB']);
