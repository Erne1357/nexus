<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Events\NewGroupPlacementRequested;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\NewGroupPlacementQuery;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Queries\NewGroupSlotQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
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
        ->and($placement->evidence)->toBe(['Sin choques duros con los 4 grupos que deja fijos', 'Puntaje blando que agrega: 0'])
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

it('HC-03: en una materia sin grupos, no se empalma con la cohorte del semestre aunque sus grupos sean de otro departamento', function () {
    // El grupo de SC del semestre 3 de ISIC va el lunes de 9:00 a 11:00: sin él, la mejor hora (sin empalmarse con el
    // semestre 2, SC-07) sería las 9:00.
    $other = $this->world->otherDepartmentInReanalysis('SC', $this->world->isc);
    $mate = Group::query()->where('subject_id', $other['subject']->id)->sole()->sessions()->sole();
    $mate->update(['starts_at' => '09:00', 'ends_at' => '11:00']);
    $subject = Subject::factory()->create(['code' => 'ACF-0905', 'name' => 'Cálculo Vectorial', 'department_id' => $this->world->department->id, 'theory_hours' => 2, 'practice_hours' => 0]);
    placeInPlan(StudyPlan::query()->where('career_id', $this->world->isc->id)->where('is_current', true)->sole(), $subject, 3);
    $this->world->forecast($subject, $this->world->isc, $this->world->c2, 30);
    Room::factory()->capacity(60)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'L-60']);

    $suggestion = $this->world->openGroupSuggestion($subject);
    $placement = $this->world->newGroupPlacement($suggestion);

    expect($suggestion->evidence[1])->toBe('No hay grupos de la materia en el campus.')
        ->and($suggestion->newGroup->semester)->toBe(3)
        ->and($placement->isPlaced())->toBeTrue()
        ->and(array_filter($placement->sessions, fn (array $session): bool => $session['day'] === $mate->day
            && Minutes::overlap(Minutes::of($mate->starts_at), Minutes::of($mate->ends_at), Minutes::of($session['startsAt']), Minutes::of($session['endsAt']))))->toBe([]);
});

it('HC-10: nunca sugiere un salón de otro departamento, aunque la carga tenga ahí el préstamo aceptado de otro grupo', function () {
    Room::query()->where('campus_id', $this->world->c2->id)->update(['capacity' => 30]);
    $owner = Department::factory()->create(['name' => 'Sistemas']);
    $borrowed = Room::factory()->capacity(70)->create(['building_id' => Building::factory()->create(['campus_id' => $this->world->c2->id])->id, 'code' => 'SC-70', 'department_id' => $owner->id]);
    $a = $this->world->groups['A'];
    GroupSession::query()->where('group_id', $a->id)->update(['room_id' => $borrowed->id]);
    InterdepartmentalAgreement::factory()->accepted()->create([
        'term_id' => $this->world->term->id, 'campus_id' => $this->world->c2->id, 'modality_id' => $this->world->modality->id,
        'from_department_id' => $this->world->department->id, 'to_department_id' => $owner->id, 'group_id' => $a->id,
        'room_id' => $borrowed->id, 'day' => 1, 'starts_at' => '07:00', 'ends_at' => '11:00',
    ]);

    $placement = $this->world->newGroupPlacement();

    expect($placement->isPlaced())->toBeFalse()
        ->and($placement->roomIds())->not->toContain($borrowed->id)
        ->and($placement->reasons[0])->toStartWith('HC-05');
});

it('lo que propone el motor pasa la revisión de aplicar; si no, lo da sin lugar con ese motivo', function () {
    $placement = $this->world->newGroupPlacement();
    $spec = $this->world->openGroupSuggestion()->newGroup;

    expect(app(NewGroupPlacementQuery::class)->conflicts($this->world->load, $spec, $placement))->toBe([]);

    $other = $this->world->group($this->world->differential, $this->world->iind, 20, $this->world->c2, 1, day: 5);
    $session = $placement->sessions[0];
    GroupSession::factory()->for($other)->inRoom(Room::query()->whereKey($session['roomId'])->sole())->on($session['day'], $session['startsAt'], $session['endsAt'])->create();
    Event::forget(NewGroupPlacementRequested::class);
    Event::listen(NewGroupPlacementRequested::class, fn (NewGroupPlacementRequested $event) => $event->providePlacement($placement));

    $stale = $this->world->newGroupPlacement();

    expect($stale->isPlaced())->toBeFalse()
        ->and($stale->reasons)->toBe(["{$placement->roomCode($session['roomId'])} ya está ocupado (HC-02) el lunes 9:00–10:00."]);
});

it('si se acaba el tiempo, lo dice para el jefe y no como en una corrida', function () {
    $now = 0.0;
    $clock = function () use (&$now): float {
        return $now += 10.0;
    };

    $placement = app(NewGroupSlotQuery::class)->place($this->world->load, $this->world->openGroupSuggestion()->newGroup, $clock);

    expect($placement->isPlaced())->toBeFalse()
        ->and($placement->reasons)->toBe(['El motor no le encontró lugar a tiempo: vuelve a buscar en un momento.']);
});

it('si el motor falla, la tarjeta dice que no hay lugar, sin un error de la página', function () {
    $spec = $this->world->openGroupSuggestion()->newGroup;
    $broken = new NewGroupSpec((string) Str::uuid7(), $spec->subjectCode, $spec->campusId, $spec->campusCode, $spec->careerId, $spec->careerCode, $spec->studyPlanId, $spec->semester, $spec->capacity, $spec->seats);

    $placement = app(NewGroupPlacementQuery::class)->place($this->world->load, $broken);

    expect($placement->isPlaced())->toBeFalse()
        ->and($placement->reasons)->toBe(['El motor falló al buscar el lugar y ya quedó registrado: vuelve a intentar o ábrelo en Horarios.']);
});

it('sin grupos de la carga en el campus lo dice sin armar la entrada del motor', function () {
    $spec = $this->world->openGroupSuggestion()->newGroup;
    $elsewhere = new NewGroupSpec($spec->subjectId, $spec->subjectCode, Campus::factory()->create(['code' => 'C3'])->id, 'C3', $spec->careerId, $spec->careerCode, $spec->studyPlanId, $spec->semester, $spec->capacity, $spec->seats);

    $placement = app(NewGroupPlacementQuery::class)->place($this->world->load, $elsewhere);

    expect($placement->isPlaced())->toBeFalse()
        ->and($placement->reasons)->toBe(['La carga no tiene grupos en C3, así que el motor no ve sus salones: ábrelo en Horarios.']);
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
