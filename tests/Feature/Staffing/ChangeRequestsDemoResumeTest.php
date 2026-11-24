<?php

use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Campus;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use Tests\Support\Staffing\ChangeRequestsWorld;

/*
| Las solicitudes de cambio de la fase 8 (8.6 y 8.7) en el punto `reanalisis-casos`: el paso de Staffing reabre a ajustes
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí una solicitud en cada estado de la
| pantalla, por las Actions reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza
| como en el masivo: publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay
| docentes con clases en los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en
| Campus I en un grupo de otro departamento, sin choques. Corre la demo real hasta `reanalisis` y solo el paso nuevo.
|
| Este archivo: retomar tras caerse a la mitad. Ver ChangeRequestsDemoSeederTest.
*/

it('retoma tras caerse a la mitad y deja lo mismo: claves, choques y alternativas al día', function () {
    $w = ChangeRequestsWorld::world();
    $request = $w->request;
    $status = fn (string $key): ?ChangeRequestStatus => $request($key)?->status;
    $crash = fn (string $class) => app()->bind($class, fn () => throw new RuntimeException("caída en {$class}"));
    $recover = fn (string $class) => app()->offsetUnset($class);

    // Se cae al aplicar la alternativa: ya rechazó, aplicó lo pedido, acomodó a mano `m` y aprobó `y` con una alternativa.
    $crash(ApplyChosenAlternative::class);
    expect(fn () => ($w->run)())->toThrow(RuntimeException::class, 'caída');
    expect($w->sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(collect(['r1', 'r2', 'r3', 'r4'])->map($status)->all())->toBe(array_fill(0, 4, ChangeRequestStatus::Rejected))
        ->and(collect(['ap1', 'ap2', 'ap3', 'ap4', 'm'])->map($status)->all())->toBe(array_fill(0, 5, ChangeRequestStatus::Applied))
        ->and($status('y'))->toBe(ChangeRequestStatus::Approved)
        ->and($status('n'))->toBeNull();
    $recover(ApplyChosenAlternative::class);

    // Aplica `y` (la carga regresa a asignación), pide lo de después y se cae al pedir las primeras alternativas.
    $crash(ProposeChangeAlternatives::class);
    expect(fn () => ($w->run)())->toThrow(RuntimeException::class, 'caída');
    expect($status('y'))->toBe(ChangeRequestStatus::Applied)
        ->and($w->sc->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($status('p1'))->toBe(ChangeRequestStatus::Pending)
        ->and($request('n')->alternatives)->toBeNull()
        ->and($request('p4')->alternatives)->toBeNull()
        ->and($status('a1'))->toBe(ChangeRequestStatus::Pending);
    $recover(ProposeChangeAlternatives::class);

    ($w->run)();

    expect(($w->check->passes)($w->demo))->toBeTrue()
        ->and(($w->cases)())->toBe(ChangeRequestsWorld::expectedCases());

    ChangeRequestsWorld::expectSettled($request, $w->term);
});
