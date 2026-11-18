<?php

use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\NewGroupPlacementQuery;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| «Abrir un grupo» del re-análisis (tarea 8.3b): el drawer pide al motor (NewGroupPlacementRequested, que contesta Solver
| con la heurística) dónde cabe el grupo nuevo, con todos los grupos de la carga fijos. El mundo: Cálculo Integral en C2
| con ISIC faltante (120 esperados; el grupo A de 40 lugares en un salón de 50, el lunes de 7:00 a 9:00, que ya no crece
| más que a 50), así que hace falta un grupo nuevo de 35 lugares para ISIC.
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 120]);
});

it('propone un grupo nuevo de la materia para la carrera faltante, con su capacidad, su cupo y su plan', function () {
    $spec = $this->world->openGroupSuggestion()->newGroup;

    expect($spec->subjectId)->toBe($this->world->integral->id)
        ->and($spec->campusId)->toBe($this->world->c2->id)
        ->and($spec->careerId)->toBe($this->world->isc->id)
        ->and($spec->semester)->toBe(2)
        ->and($spec->capacity)->toBe(35)
        ->and($spec->seats)->toBe(35);
});

it('HC-02, HC-03, HC-04, HC-05: coloca el grupo nuevo sin duras, con todo lo demás fijo y sin escribir nada', function () {
    $sessions = GroupSession::query()->orderBy('id')->get(['id', 'group_id', 'day', 'starts_at', 'ends_at', 'room_id'])->toArray();

    $placement = $this->world->newGroupPlacement();

    expect($placement->isPlaced())->toBeTrue()
        ->and($placement->reasons)->toBe([])
        ->and($placement->sessions)->toHaveCount(2)
        ->and(GroupSession::query()->orderBy('id')->get(['id', 'group_id', 'day', 'starts_at', 'ends_at', 'room_id'])->toArray())->toBe($sessions)
        ->and($placement->evidence[0])->toStartWith('Puntaje blando que agrega: ')
        ->and($placement->scheduleLabel())->not->toBe('');

    $a = $this->world->groups['A']->sessions()->sole();

    foreach ($placement->sessions as $session) {
        $room = Room::query()->whereKey($session['roomId'])->sole();
        $overlaps = fn (GroupSession $other): bool => $other->day === $session['day']
            && Minutes::overlap(Minutes::of($other->starts_at), Minutes::of($other->ends_at), Minutes::of($session['startsAt']), Minutes::of($session['endsAt']));

        expect($room->campus_id)->toBe($this->world->c2->id)
            ->and($room->capacity)->toBeGreaterThanOrEqual(35)
            ->and($placement->roomCode($room->id))->toBe($room->code)
            ->and(Minutes::of($session['startsAt']))->toBeGreaterThanOrEqual(7 * 60)
            ->and(GroupSession::query()->where('room_id', $room->id)->get()->contains($overlaps))->toBeFalse()
            ->and($overlaps($a))->toBeFalse();
    }
});

it('sin lugar posible lo dice con la regla y no trae sesiones', function () {
    Room::query()->where('campus_id', $this->world->c2->id)->update(['capacity' => 30]);

    $placement = $this->world->newGroupPlacement();

    expect($placement->isPlaced())->toBeFalse()
        ->and($placement->sessions)->toBe([])
        ->and($placement->reasons)->not->toBeEmpty()
        ->and($placement->reasons[0])->toStartWith('HC-05');
});

it('sin quien conteste (el motor no está) no hay lugar', function () {
    Event::fake([NewGroupPlacementRequested::class]);

    expect($this->world->newGroupPlacement())->toBeNull();
});

it('lee en un número fijo de consultas, sin importar cuántos grupos y salones tenga la carga', function () {
    $count = function (): int {
        $spec = $this->world->openGroupSuggestion()->newGroup;
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(NewGroupPlacementQuery::class)->place($this->world->load->fresh(), $spec);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();
    $this->world->addSubjects(4);

    expect($count())->toBe($few)->toBeLessThanOrEqual(40);
});
