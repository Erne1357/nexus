<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use Illuminate\Support\Facades\DB;

/*
| RoomSuggestionQuery (tarea 3.7, SC-03): los salones activos del campus del grupo para una sesión, ordenados por un
| puntaje con sus motivos (por defecto de la materia, historial, tipo requerido, capacidad y mismo edificio que la
| clase anterior del semestre; supuesto por confirmar) y con lo que pasaría al elegir cada uno: ocupado (HC-02),
| bloqueado (HC-11), préstamo (HC-10), no cabe (HC-05) o tipo equivocado (HC-06).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->campus = Campus::factory()->create();
    $this->mine = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $this->metal = Department::factory()->create(['code' => 'MM', 'name' => 'Metal-Mecánica']);
    $this->subject = Subject::factory()->create(['code' => 'AED-1026', 'name' => 'Estructura de Datos', 'department_id' => $this->mine->id, 'needs_lab' => true]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'code' => '3B', 'capacity' => 30, 'semester' => 3,
    ]);
    $this->session = GroupSession::factory()->for($this->group)->practica()->on(2, '15:00', '17:00')->create();
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'CC']);
});

function suggestedRoom(string $code, RoomType $type = RoomType::Aula, int $capacity = 30, array $attributes = []): Room
{
    return Room::factory()->create(['building_id' => test()->building->id, 'code' => $code, 'type' => $type, 'capacity' => $capacity, ...$attributes]);
}

/**
 * @return array{department: array{code: string, name: string}, current: ?array<string, mixed>, rooms: list<array<string, mixed>>, results: list<array<string, mixed>>}
 */
function suggestionsFor(?GroupSession $session = null, int $limit = 8, string $search = ''): array
{
    return app(RoomSuggestionQuery::class)->for(($session ?? test()->session)->fresh(), $limit, $search);
}

/**
 * Los renglones por clave.
 *
 * @return array<string, array<string, mixed>>
 */
function suggestionsByCode(?GroupSession $session = null): array
{
    return collect(suggestionsFor($session)['rooms'])->keyBy('code')->all();
}

/**
 * Una sesión de otro grupo del campus y periodo (del mismo plan y semestre, salvo que se diga otra cosa).
 */
function sessionOfSuggestion(Room $room, int $day, string $startsAt, string $endsAt, array $group = []): GroupSession
{
    $other = Group::factory()->create([
        'term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'study_plan_id' => test()->group->study_plan_id,
        'owner_career_id' => test()->group->owner_career_id, 'semester' => 3, ...$group,
    ]);

    return GroupSession::factory()->for($other)->inRoom($room)->on($day, $startsAt, $endsAt)->create();
}

it('SC-03: ordena por defecto de la materia, historial, tipo requerido, capacidad y edificio, con sus motivos', function () {
    $default = suggestedRoom('CC-05', RoomType::SalaComputo, 32);
    $usual = suggestedRoom('CC-03', RoomType::SalaComputo, 30, ['building_id' => Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'LAB'])->id]);
    suggestedRoom('CC-07', RoomType::SalaComputo, 40);
    suggestedRoom('LC-106', RoomType::Aula, 35);
    SubjectRoomDefault::factory()->create(['subject_id' => $this->subject->id, 'room_id' => $default->id, 'priority' => 1]);

    foreach (['20263' => '2026-08-17', '20261' => '2026-01-19', '20273' => '2027-08-16'] as $code => $startsOn) {
        $term = Term::factory()->create(['code' => $code, 'starts_on' => $startsOn, 'ends_on' => $startsOn]);
        $old = Group::factory()->create(['term_id' => $term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id]);
        GroupSession::factory()->for($old)->inRoom($usual)->on(1, '07:00', '09:00')->create();
    }

    $elsewhere = Building::factory()->create(['campus_id' => $this->campus->id]);
    sessionOfSuggestion(suggestedRoom('LC-201', attributes: ['building_id' => $elsewhere->id]), 2, '11:00', '13:00');
    sessionOfSuggestion($usual, 2, '13:00', '15:00');

    $rooms = suggestionsFor()['rooms'];

    expect(array_column($rooms, 'code'))->toBe(['CC-05', 'CC-03', 'CC-07', 'LC-201', 'LC-106'])
        ->and(array_column($rooms, 'score'))->toBe(collect($rooms)->pluck('score')->sortDesc()->values()->all())
        ->and($rooms[0]['reasons'])->toBe(['Por defecto de la materia', 'Sala de cómputo', 'Cabe: 30 de 32'])
        ->and($rooms[1]['reasons'])->toBe(['Usado en 20263 y 20261', 'Sala de cómputo', 'Cabe: 30 de 30', 'Mismo edificio que la clase anterior'])
        ->and($rooms[2]['reasons'])->toBe(['Sala de cómputo', 'Cabe: 30 de 40'])
        ->and($rooms[0])->toMatchArray(['id' => $default->id, 'type' => 'Sala de cómputo', 'capacity' => 32, 'building' => 'CC', 'owner' => null])
        ->and($rooms[1]['building'])->toBe('LAB');
});

it('SC-03: un salón sugerido de prioridad más baja suma menos que el por defecto', function () {
    $first = suggestedRoom('CC-01', RoomType::SalaComputo, 30);
    $second = suggestedRoom('CC-02', RoomType::SalaComputo, 30);
    SubjectRoomDefault::factory()->create(['subject_id' => $this->subject->id, 'room_id' => $second->id, 'priority' => 2]);
    SubjectRoomDefault::factory()->create(['subject_id' => $this->subject->id, 'room_id' => $first->id, 'priority' => 1]);

    $rooms = suggestionsFor()['rooms'];

    expect(array_column($rooms, 'code'))->toBe(['CC-01', 'CC-02'])
        ->and($rooms[1]['reasons'][0])->toBe('Sugerido para la materia');
});

it('la teoría prefiere un aula; una práctica sin laboratorio no pide tipo', function (SessionKind $kind, bool $needsLab, array $reasons) {
    $this->subject->update(['needs_lab' => $needsLab]);
    $this->session->update(['kind' => $kind]);
    suggestedRoom('LC-106', RoomType::Aula, 30);

    expect(suggestionsByCode()['LC-106']['reasons'])->toBe($reasons)
        ->and(suggestionsByCode()['LC-106']['state']['wrongType'])->toBeFalse();
})->with([
    'teoría' => [SessionKind::Teoria, true, ['Aula', 'Cabe: 30 de 30']],
    'práctica sin laboratorio' => [SessionKind::Practica, false, ['Cabe: 30 de 30']],
]);

it('HC-02, HC-05, HC-06, HC-10 y HC-11: da el estado de cada salón para esta sesión y lo que causaría', function () {
    suggestedRoom('CC-05', RoomType::SalaComputo, 32);
    $busy = suggestedRoom('CC-03', RoomType::SalaComputo, 30);
    $blocked = suggestedRoom('CC-04', RoomType::SalaComputo, 30);
    suggestedRoom('MM-L2', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    suggestedRoom('LF-01', RoomType::Laboratorio, 28);
    suggestedRoom('LC-106', RoomType::Aula, 35);
    sessionOfSuggestion($busy, 2, '15:00', '16:00', ['code' => '3A', 'subject_id' => $this->subject->id]);
    RoomBlock::factory()->create(['room_id' => $blocked->id, 'day' => 2, 'starts_at' => '16:00', 'ends_at' => '18:00', 'reason' => 'Mantenimiento']);
    RoomBlock::factory()->create(['room_id' => $blocked->id, 'day' => 3, 'starts_at' => '15:00', 'ends_at' => '17:00', 'reason' => 'Otro día']);

    $rooms = suggestionsByCode();

    expect($rooms['CC-05']['state'])->toMatchArray(['free' => true, 'busy' => null, 'blocked' => null, 'needsLoan' => false, 'tooSmall' => false, 'wrongType' => false])
        ->and($rooms['CC-05']['selectable'])->toBeTrue()
        ->and($rooms['CC-05']['conflicts'])->toBe([])
        ->and($rooms['CC-03']['state'])->toMatchArray(['free' => false, 'busy' => 'Ocupado', 'busyDetail' => 'AED-1026 3A · mar 15:00–16:00', 'busyNote' => 'HC-02: no se puede elegir.'])
        ->and($rooms['CC-03']['selectable'])->toBeFalse()
        ->and($rooms['CC-04']['state']['blocked'])->toBe('Mantenimiento · mar 16:00–18:00')
        ->and($rooms['CC-04']['conflicts'])->toBe([['rule' => 'HC-11', 'label' => 'Bloqueado', 'detail' => 'Mantenimiento · mar 16:00–18:00', 'message' => 'CC-04 está bloqueado el martes de 16:00 a 18:00: Mantenimiento.']])
        ->and($rooms['MM-L2']['state'])->toMatchArray(['free' => false, 'needsLoan' => true, 'covered' => false, 'pending' => false])
        ->and($rooms['MM-L2']['owner'])->toBe(['code' => 'MM', 'name' => 'Metal-Mecánica'])
        ->and($rooms['MM-L2']['conflicts'])->toBe([['rule' => 'HC-10', 'label' => 'Pide préstamo', 'detail' => 'De Metal-Mecánica', 'message' => 'MM-L2 es de Metal-Mecánica: el préstamo del martes de 15:00 a 17:00 queda pendiente hasta que su jefe lo acepte.']])
        ->and($rooms['LF-01']['state']['tooSmall'])->toBeTrue()
        ->and($rooms['LF-01']['reasons'])->toContain('No cabe: 30 de 28')
        ->and($rooms['LF-01']['conflicts'])->toBe([['rule' => 'HC-05', 'label' => 'No cabe', 'detail' => null, 'message' => 'AED-1026 3B es de 30 lugares y LF-01 tiene 28.']])
        ->and($rooms['LC-106']['state']['wrongType'])->toBeTrue()
        ->and($rooms['LC-106']['conflicts'])->toBe([['rule' => 'HC-06', 'label' => 'No sirve para práctica', 'detail' => null, 'message' => 'LC-106 es aula: las prácticas de AED-1026 piden laboratorio, taller o sala de cómputo.']]);
});

it('HC-10: un salón del mismo departamento no pide préstamo; uno cubierto por préstamos aceptados del grupo queda cubierto', function () {
    suggestedRoom('SC-L1', RoomType::Laboratorio, 30, ['department_id' => $this->mine->id]);
    $loaned = suggestedRoom('MM-L2', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    foreach ([['15:00', '16:00'], ['16:00', '17:00']] as [$startsAt, $endsAt]) {
        InterdepartmentalAgreement::factory()->accepted()->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id, 'room_id' => $loaned->id,
            'from_department_id' => $this->mine->id, 'to_department_id' => $this->metal->id, 'day' => 2, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
        ]);
    }

    $rooms = suggestionsByCode();

    expect($rooms['SC-L1']['state'])->toMatchArray(['free' => true, 'needsLoan' => false])
        ->and($rooms['MM-L2']['state'])->toMatchArray(['free' => true, 'needsLoan' => true, 'covered' => true])
        ->and($rooms['MM-L2']['conflicts'])->toBe([]);
});

it('da aparte el salón actual de la sesión, con su préstamo pendiente', function () {
    $current = suggestedRoom('MM-L2', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    $this->session->update(['room_id' => $current->id]);
    InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $this->group->id, 'room_id' => $current->id,
        'from_department_id' => $this->mine->id, 'to_department_id' => $this->metal->id, 'day' => 2, 'starts_at' => '15:00', 'ends_at' => '17:00',
    ]);

    $result = suggestionsFor();

    expect($result['current'])->toMatchArray(['code' => 'MM-L2', 'current' => true, 'selectable' => true])
        ->and($result['current']['state'])->toMatchArray(['busy' => null, 'needsLoan' => true, 'covered' => false, 'pending' => true])
        ->and($result['current']['conflicts'][0]['label'])->toBe('Préstamo pendiente')
        ->and(collect($result['rooms'])->firstWhere('code', 'MM-L2')['current'])->toBeTrue();
});

it('sin salón, el actual es null', function () {
    expect(suggestionsFor()['current'])->toBeNull();
});

it('solo da salones activos del campus del grupo, hasta el límite', function () {
    suggestedRoom('CC-01');
    suggestedRoom('CC-02');
    suggestedRoom('CC-03');
    suggestedRoom('CC-09', attributes: ['is_active' => false]);
    suggestedRoom('CC-08')->delete();
    Room::factory()->create(['code' => 'OTRO-1']);

    expect(array_column(suggestionsFor()['rooms'], 'code'))->toBe(['CC-01', 'CC-02', 'CC-03'])
        ->and(suggestionsFor(limit: 2)['rooms'])->toHaveCount(2);
});

it('busca otro salón por clave o nombre entre los que no están sugeridos, con los comodines tomados literalmente', function () {
    suggestedRoom('L-1', RoomType::SalaComputo);
    suggestedRoom('L-2', RoomType::SalaComputo);
    suggestedRoom('LC-106', attributes: ['name' => 'Aula magna']);
    suggestedRoom('LC-201');
    suggestedRoom('AU-05', attributes: ['name' => 'Centro 100%']);

    $codes = fn (string $search): array => array_column(suggestionsFor(limit: 2, search: $search)['results'], 'code');

    expect(array_column(suggestionsFor(limit: 2)['rooms'], 'code'))->toBe(['L-1', 'L-2'])
        ->and(suggestionsFor(limit: 2)['results'])->toBe([])
        ->and($codes('lc-1'))->toBe(['LC-106'])
        ->and($codes('MAGNA'))->toBe(['LC-106'])
        ->and($codes('0%'))->toBe(['AU-05'])
        ->and($codes('%'))->toBe(['AU-05'])
        ->and($codes('l-1'))->toBe([])
        ->and(suggestionsFor(limit: 2, search: 'l-1')['matched'])->toBe([Room::query()->where('code', 'L-1')->value('id')])
        ->and(suggestionsFor(limit: 2, search: 'lc')['matched'])->toBe([])
        ->and($codes('lc'))->toBe(['LC-106', 'LC-201']);
});

it('HC-10: un salón que otro grupo ya tiene pedido o prestado a esa hora se ve ocupado y no se elige; el préstamo del propio grupo no', function () {
    $requested = suggestedRoom('MM-L1', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    $lent = suggestedRoom('MM-L2', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    $mine = suggestedRoom('MM-L3', RoomType::Laboratorio, 30, ['department_id' => $this->metal->id]);
    $other = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id, 'code' => '3A']);
    $loan = fn (Room $room, string $groupId, AgreementStatus $status, string $startsAt = '16:00', string $endsAt = '18:00') => InterdepartmentalAgreement::factory()->inStatus($status)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $groupId, 'room_id' => $room->id,
        'from_department_id' => $this->mine->id, 'to_department_id' => $this->metal->id, 'day' => 2, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
    $loan($requested, $other->id, AgreementStatus::Proposed);
    $loan($lent, $other->id, AgreementStatus::Accepted);
    $loan($mine, $this->group->id, AgreementStatus::Proposed, '15:00', '17:00');
    $loan($mine, $other->id, AgreementStatus::Accepted, '17:00', '18:00');

    $rooms = suggestionsByCode();

    expect($rooms['MM-L1']['state'])->toMatchArray(['busy' => 'Apartado', 'busyDetail' => 'Pedido para AED-1026 3A · SC', 'busyNote' => 'HC-10: el salón ya está apartado para otro grupo; no se puede elegir.'])
        ->and($rooms['MM-L1']['selectable'])->toBeFalse()
        ->and($rooms['MM-L2']['state']['busyDetail'])->toBe('Prestado a AED-1026 3A · SC')
        ->and($rooms['MM-L3']['state'])->toMatchArray(['busy' => null, 'pending' => true])
        ->and($rooms['MM-L3']['selectable'])->toBeTrue();
});

it('lee con pocas consultas aunque el campus tenga muchos salones', function () {
    foreach (range(1, 25) as $index) {
        $room = suggestedRoom(sprintf('R-%02d', $index), $index % 2 ? RoomType::Aula : RoomType::Laboratorio, 20 + $index, [
            'department_id' => $index % 3 ? null : $this->metal->id,
            'building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id,
        ]);
        RoomBlock::factory()->create(['room_id' => $room->id, 'day' => 2, 'starts_at' => '15:00', 'ends_at' => '16:00']);
    }
    $this->session->update(['room_id' => Room::query()->where('code', 'R-01')->value('id')]);
    $session = $this->session->fresh();

    $counted = function (string $search) use ($session): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(RoomSuggestionQuery::class)->for($session, $search === '' ? 30 : 5, $search);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $queries];
    };
    [$result, $queries] = $counted('');
    [$searched, $searchQueries] = $counted('R-2');

    expect($result['rooms'])->toHaveCount(25)
        ->and($searched['results'])->not->toBe([])
        ->and($searchQueries)->toBeLessThanOrEqual(12)
        ->and($queries)->toBeLessThanOrEqual(12);
});
