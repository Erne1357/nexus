<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Queries\SelectionRoomOptionsQuery;

/*
| SelectionRoomOptionsQuery (tarea 1.33, SEL-01 a SEL-04): los salones que un docente puede elegir para un grupo.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->modality = Modality::factory()->create();
    $this->dept = Department::factory()->create();
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id]);
    $this->selection = Selection::factory()->create([
        'term_id' => $this->term->id, 'modality_id' => $this->modality->id, 'department_id' => $this->dept->id,
    ]);
    $this->load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->modality->id, 'department_id' => $this->dept->id]);
    $this->selection->loads()->attach($this->load->id);
    $this->subject = Subject::factory()->create(['department_id' => $this->dept->id, 'required_space' => RequiredSpace::Aula]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => $this->modality->id,
        'subject_id' => $this->subject->id, 'department_id' => $this->dept->id, 'capacity' => 30,
    ]);
    GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->create();
    GroupSession::factory()->for($this->group)->on(3, '09:00', '10:00')->create();
});

/**
 * Un salón del departamento de la selección, confirmado en su revisión de salones validada del periodo (SEL-01, tarea
 * 1.53). Con `$confirm` en false, o de otro departamento, queda fuera de la foto de la revisión.
 */
function selectionRoom(string $code, array $attributes = [], bool $confirm = true): Room
{
    $room = Room::factory()->create(['department_id' => test()->dept->id, 'building_id' => test()->building->id, 'code' => $code, 'type' => RoomType::Aula, 'capacity' => 40, ...$attributes]);

    if ($confirm && $room->department_id === test()->dept->id) {
        confirmRooms(test()->term->id, test()->dept->id, [$room]);
    }

    return $room;
}

function offeredCodes(): array
{
    return array_column(app(SelectionRoomOptionsQuery::class)->forGroup(test()->selection, test()->group->fresh())['eligible'], 'code');
}

it('SEL-01: ofrece los salones libres del campus del grupo', function () {
    selectionRoom('A1');
    selectionRoom('A2');
    selectionRoom('A3', ['is_active' => false]);

    expect(offeredCodes())->toBe(['A1', 'A2']);
});

it('SEL-01: no ofrece salones de otro departamento', function () {
    selectionRoom('A1');
    selectionRoom('OTRO', ['department_id' => Department::factory()->create()->id]);

    expect(offeredCodes())->toBe(['A1']);
});

it('SEL-01: no ofrece un salón sin dueño aunque esté libre', function () {
    selectionRoom('A1');
    selectionRoom('HUERFANO', ['department_id' => null], confirm: false);

    expect(offeredCodes())->toBe(['A1']);
});

it('SEL-01: no ofrece un salón del departamento que no está en la foto de la revisión validada', function () {
    selectionRoom('A1');
    selectionRoom('NUEVO', confirm: false);

    expect(offeredCodes())->toBe(['A1']);
});

it('SEL-01: no ofrece un salón con una observación abierta, pero sí cuando se resuelve', function () {
    $room = selectionRoom('A1');
    selectionRoom('A2');
    $flag = CatalogReviewFlag::factory()->on($room)->create(['review_id' => CatalogReview::query()->where('catalog', Catalog::Rooms)->value('id')]);

    expect(offeredCodes())->toBe(['A2']);

    $flag->update(['resolved_at' => now(), 'resolved_by' => User::factory()->create()->id]);

    expect(offeredCodes())->toBe(['A1', 'A2']);
});

it('SEL-01: sin la revisión de salones del departamento validada no ofrece ningún salón y lo dice', function () {
    selectionRoom('A1');
    CatalogReview::query()->where('catalog', Catalog::Rooms)->update(['status' => ReviewStatus::InReview, 'validated_by' => null, 'validated_at' => null]);

    $result = app(SelectionRoomOptionsQuery::class)->forGroup($this->selection, $this->group->fresh());

    expect($result['eligible'])->toBe([])
        ->and($result['validated'])->toBeFalse();
});

it('SEL-01: la revisión validada de otro periodo no confirma salones de este', function () {
    $room = selectionRoom('A1', confirm: false);
    confirmRooms(Term::factory()->create()->id, $this->dept->id, [$room]);

    expect(offeredCodes())->toBe([]);
});

it('SEL-01: la revisión validada de otro departamento no confirma salones de este', function () {
    $room = selectionRoom('A1', confirm: false);
    confirmRooms($this->term->id, Department::factory()->create()->id, [$room]);

    // confirmRooms lo pasó al otro departamento: ya no es del de la selección.
    expect(offeredCodes())->toBe([]);
});

it('SEL-01: no ofrece un salón libre solo en algunas sesiones', function () {
    $partial = selectionRoom('PARCIAL');
    selectionRoom('LIBRE');
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    GroupSession::factory()->for($other)->on(3, '09:00', '10:00')->inRoom($partial)->create();

    $result = app(SelectionRoomOptionsQuery::class)->forGroup($this->selection, $this->group->fresh());

    expect(array_column($result['eligible'], 'code'))->toBe(['LIBRE'])
        ->and($result['rejected'])->toHaveCount(1)
        ->and($result['rejected'][0]['code'])->toBe('PARCIAL')
        ->and($result['rejected'][0]['reason'])->toBe('Ocupado a esa hora');
});

it('SEL-01: no ofrece salones bloqueados', function () {
    $blocked = selectionRoom('BLOQ');
    selectionRoom('LIBRE');
    RoomBlock::factory()->create(['room_id' => $blocked->id, 'day' => 1, 'starts_at' => '08:00', 'ends_at' => '09:30']);

    expect(offeredCodes())->toBe(['LIBRE']);
});

it('SEL-01: no ofrece salones de otro campus', function () {
    selectionRoom('A1');
    $far = Building::factory()->create();
    confirmRooms($this->term->id, $this->dept->id, [Room::factory()->create(['building_id' => $far->id, 'code' => 'LEJOS', 'capacity' => 40])]);

    expect(offeredCodes())->toBe(['A1']);
});

it('SEL-02: una materia de laboratorio solo ve laboratorios', function () {
    $this->subject->update(['required_space' => RequiredSpace::Laboratorio]);
    selectionRoom('A1');
    selectionRoom('L1', ['type' => RoomType::Laboratorio, 'capacity' => 35]);
    selectionRoom('S1', ['type' => RoomType::SalaComputo, 'capacity' => 35]);

    expect(offeredCodes())->toBe(['L1']);
});

it('SEL-04: la materia básica no ve las salas si las apartan los grupos de sala pendientes', function () {
    selectionRoom('A1');
    selectionRoom('S1', ['type' => RoomType::SalaComputo, 'capacity' => 35]);
    $sala = Subject::factory()->create(['department_id' => $this->dept->id, 'required_space' => RequiredSpace::SalaComputo]);
    $pending = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => $this->modality->id,
        'subject_id' => $sala->id, 'department_id' => $this->dept->id,
    ]);
    GroupSession::factory()->for($pending)->on(1, '09:00', '10:00')->create();

    expect(offeredCodes())->toBe(['A1']);

    $pending->sessions()->update(['room_id' => Room::query()->where('code', 'S1')->value('id')]);

    // La sala ya está ocupada por ese grupo: sigue sin ofrecerse, ahora por ocupada.
    expect(offeredCodes())->toBe(['A1']);
});

it('SEL-04: con una sala libre de sobra la materia básica la ve', function () {
    selectionRoom('S1', ['type' => RoomType::SalaComputo, 'capacity' => 35]);
    selectionRoom('S2', ['type' => RoomType::SalaComputo, 'capacity' => 35]);
    $sala = Subject::factory()->create(['department_id' => $this->dept->id, 'required_space' => RequiredSpace::SalaComputo]);
    $pending = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'modality_id' => $this->modality->id,
        'subject_id' => $sala->id, 'department_id' => $this->dept->id,
    ]);
    GroupSession::factory()->for($pending)->on(1, '09:00', '10:00')->create();

    expect(offeredCodes())->toBe(['S1', 'S2']);
});

it('SEL-01: lee la ocupación en una sola consulta sin importar cuántos salones hay', function () {
    foreach (range(1, 12) as $n) {
        selectionRoom("R{$n}");
    }

    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    offeredCodes();
    $few = $count;

    foreach (range(13, 40) as $n) {
        selectionRoom("R{$n}");
    }
    $count = 0;
    offeredCodes();

    expect($count)->toBe($few);
});
