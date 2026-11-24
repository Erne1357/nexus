<?php

use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;

/*
| Las solicitudes de cambio de la fase 8 (8.6 y 8.7) en el punto `reanalisis-casos`: el paso de Staffing reabre a ajustes
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí una solicitud en cada estado de la
| pantalla, por las Actions reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza
| como en el masivo: publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay
| docentes con clases en los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en
| Campus I en un grupo de otro departamento, sin choques. Corre la demo real hasta `reanalisis` y solo el paso nuevo.
|
| Este archivo: la demo mezclada agrega una pendiente con choque duro. Ver ChangeRequestsDemoSeederTest.
*/

it('en la demo mezclada agrega una pendiente con choque duro y sus alternativas listas, una sola vez', function () {
    app(DemoRunner::class)->run(DemoCheckpoint::Rooms, DemoContext::for(DemoVolume::Demo));
    $this->actingAs(User::query()->where('username', 'admin')->sole());

    $seed = fn () => app(ChangeRequestsDemoSeeder::class)->setContainer(app())->__invoke(['mode' => 'showcase']);
    $seed();
    $seed();

    $requests = ChangeRequest::query()->where('reason', ChangeRequestsDemoSeeder::SHOWCASE_REASON)->get();

    expect($requests)->toHaveCount(1)
        ->and($requests->sole()->status)->toBe(ChangeRequestStatus::Pending)
        ->and($requests->sole()->impact['hard'])->not->toBeEmpty()
        ->and(ChangeAlternativesStatus::of($requests->sole()->alternatives))->toBe(ChangeAlternativesStatus::Ready);
});
