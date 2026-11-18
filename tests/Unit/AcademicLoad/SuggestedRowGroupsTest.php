<?php

use App\Modules\AcademicLoad\Rules\SuggestedRowGroups;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Rules\SuggestedGroups;

/*
| Los grupos de «Usar sugerido» (tarea 5.6) con la regla de paquetes de la 5.6b: sin paquetes, los sugeridos; con paquetes,
| los de paquetes más los sueltos sugeridos, restando el nuevo ingreso solo en primer semestre.
*/

it('sin paquetes son los grupos sugeridos', function () {
    expect(SuggestedRowGroups::for(3, 90, 0, ForecastMethod::Formula, 40, false, new SuggestedGroups))->toBe(3);
});

it('con paquetes son los de paquetes más los sueltos sugeridos, con el tamaño de la materia', function () {
    expect(SuggestedRowGroups::for(2, 68, 2, ForecastMethod::Formula, 40, false, new SuggestedGroups))->toBe(4)
        ->and(SuggestedRowGroups::for(2, 68, 2, ForecastMethod::Formula, 40, true, new SuggestedGroups))->toBe(5);
});

it('en primer semestre los sueltos restan el nuevo ingreso, sin bajar de cero', function () {
    expect(SuggestedRowGroups::for(2, 68, 2, ForecastMethod::FirstSemester, 40, false, new SuggestedGroups))->toBe(3)
        ->and(SuggestedRowGroups::for(2, 30, 2, ForecastMethod::FirstSemester, 40, false, new SuggestedGroups))->toBe(2);
});
