<?php

use App\Modules\Core\Events\Concerns\CollectsReport;

/*
| CollectsReport: base de los eventos con reporte (docs/arquitectura/arquitectura.md, "Eventos con
| reporte"). Los listeners de los módulos de la derecha del mapa dicen qué hicieron con una línea; la
| Action que despachó el evento lee lines() para la bitácora y el toast.
*/

function reportingEvent(): object
{
    return new class
    {
        use CollectsReport;
    };
}

it('sin reportes no tiene líneas', function () {
    expect(reportingEvent()->lines())->toBe([]);
});

it('junta las líneas de varios listeners en el orden en que llegan', function () {
    $event = reportingEvent();
    $event->report('3 revisiones de catálogo nuevas');
    $event->report('1 carga académica nueva');

    expect($event->lines())->toBe(['3 revisiones de catálogo nuevas', '1 carga académica nueva']);
});
