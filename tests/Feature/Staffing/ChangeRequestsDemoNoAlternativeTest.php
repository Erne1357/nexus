<?php

use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\Core\Models\Campus;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use Illuminate\Support\Facades\Event;
use Tests\Support\Staffing\ChangeRequestsWorld;

/*
| Las solicitudes de cambio de la fase 8 (8.6 y 8.7) en el punto `reanalisis-casos`: el paso de Staffing reabre a ajustes
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí una solicitud en cada estado de la
| pantalla, por las Actions reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza
| como en el masivo: publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay
| docentes con clases en los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en
| Campus I en un grupo de otro departamento, sin choques. Corre la demo real hasta `reanalisis` y solo el paso nuevo.
|
| Este archivo: la solicitud sin alternativa aunque sobren salones. Ver ChangeRequestsDemoSeederTest.
*/

it('deja sin alternativa la de `n` aunque sobren salones libres a sus horas, como con el masivo: Subdirección los aparta en el periodo', function () {
    $w = ChangeRequestsWorld::world();
    $request = $w->request;

    // Con el masivo sobran salones: a las horas del grupo de `n` queda otro libre del tipo que pide, y la misma hora en otro
    // salón ya es otro acomodo (CR-02), así que el motor le encontraba alternativa. Aquí, uno libre de cada tipo y con cupo
    // de sobra en cada campus de la carga.
    $spare = Group::query()->active()->where('term_id', $w->term->id)->where('department_id', $w->sc->department_id)->distinct()->pluck('campus_id')
        ->flatMap(fn (string $campusId) => collect([RoomType::Aula, RoomType::Laboratorio, RoomType::SalaComputo, RoomType::Taller])
            ->map(fn (RoomType $type): Room => Room::factory()->ofType($type)->capacity(80)->create(['building_id' => Building::query()->where('campus_id', $campusId)->orderBy('code')->value('id')])));

    ($w->run)();

    $group = $request('n')->group()->with('sessions')->first();
    $blocks = RoomBlock::query()->where('reason', ChangeRequestsDemoSeeder::ROOM_BLOCK_REASON)->get();
    $hours = $group->sessions->map(fn (GroupSession $session): string => $session->day.' '.substr((string) $session->starts_at, 0, 5).'-'.substr((string) $session->ends_at, 0, 5));

    expect(ChangeAlternativesStatus::of($request('n')->alternatives))->toBe(ChangeAlternativesStatus::None)
        ->and(($w->check->passes)($w->demo))->toBeTrue()
        ->and($blocks->pluck('room_id')->intersect($spare->where('campus_id', $group->campus_id)->pluck('id'))->all())->not->toBeEmpty()
        ->and($blocks->every(fn (RoomBlock $block): bool => $block->term_id === $w->term->id
            && $hours->contains($block->day.' '.substr((string) $block->starts_at, 0, 5).'-'.substr((string) $block->ends_at, 0, 5))))->toBeTrue();

    // A sus horas ningún otro salón del campus le sirve sin agregar un choque duro (lo que revisa al final cada alternativa).
    $validator = app(ScheduleValidator::class);
    $validator->forget();
    $had = collect($validator->validateGroup($group)->violations())->map(fn (Violation $violation): string => $violation->key())->flip();
    $free = Room::query()->where('campus_id', $group->campus_id)->where('is_active', true)->orderBy('code')->get()
        ->flatMap(fn (Room $room) => $group->sessions->reject(fn (GroupSession $session): bool => $session->room_id === $room->id)
            ->filter(fn (GroupSession $session): bool => collect($validator->checkSession($group, new SessionCandidate($session->day, (string) $session->starts_at, (string) $session->ends_at, $room->id, $session->kind, $session->id))->violations())
                ->every(fn (Violation $violation): bool => $violation->severity !== ConflictSeverity::Hard || $had->has($violation->key())))
            ->map(fn (GroupSession $session): string => "{$room->code} el día {$session->day}"));

    expect($free->values()->all())->toBe([]);

    // Los salones apartados estaban libres: no abren choques; y volver a correrlo no aparta otra vez.
    ChangeRequestsWorld::expectSettled($request, $w->term);
    $before = RoomBlock::query()->count();
    Event::fakeFor(fn () => ($w->step->run)($w->demo), $w->plan->quietEvents());

    expect(RoomBlock::query()->count())->toBe($before);
});
