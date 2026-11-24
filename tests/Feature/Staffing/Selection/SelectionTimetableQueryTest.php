<?php

use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Queries\SelectionTimetableQuery;
use Carbon\CarbonImmutable;
use Tests\Support\Staffing\SelectionWorld;

/*
| SelectionTimetableQuery (tarea 1.33, diseño 6.4): el horario de la selección por día y hora, con lo suyo, sus choques
| (HC-01), su perfil o historial y su turno; strips() lo junta en un renglón por hora (maqueta A). Nombres ficticios.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
    $this->teacher = $this->world->teacher(base: 10, name: 'Docente Uno');
    $this->query = app(SelectionTimetableQuery::class);
});

it('da una celda por sesión con llave día|hora y las horas de 7:00 a 20:00', function () {
    $group = $this->world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);

    $timetable = $this->query->forTeacher($this->world->selection, $this->teacher, CarbonImmutable::now());

    expect(array_keys($timetable['cells']))->toBe(['1|09:00', '3|09:00'])
        ->and($timetable['cells']['1|09:00'][0])->toMatchArray([
            'group_id' => $group->id, 'group_code' => 'A', 'capacity' => 30, 'required_space' => 'aula',
            'teacher' => null, 'mine' => false, 'room' => null, 'in_profile' => false, 'clashes' => false,
        ])
        ->and($timetable['days'])->toBe([1, 2, 3, 4, 5])
        ->and($timetable['hours'][0])->toBe('07:00')
        ->and(end($timetable['hours']))->toBe('20:00')
        ->and($timetable['base_hours'])->toBe(10);
});

it('marca lo suyo, sus horas y lo que le choca con lo que ya tiene (HC-01)', function () {
    $mine = $this->world->group('A', [[1, '09:00', '10:00']], theory: 3, practice: 1);
    $clash = $this->world->group('B', [[1, '09:00', '10:00']]);
    $free = $this->world->group('C', [[1, '10:00', '11:00']]);
    app(PickSelectionGroup::class)->handle($this->world->selection, $this->teacher, $mine, $this->world->room('A1'), $this->teacher->user);

    $timetable = $this->query->forTeacher($this->world->selection, $this->teacher, CarbonImmutable::now());
    $cells = collect($timetable['cells'])->flatten(1)->keyBy('group_id');

    expect($cells[$mine->id])->toMatchArray(['mine' => true, 'teacher' => 'Docente Uno', 'room' => 'A1', 'clashes' => false])
        ->and($cells[$clash->id]['clashes'])->toBeTrue()
        ->and($cells[$clash->id]['clash_with'])->toBe($mine->subject->code.' A')
        ->and($cells[$free->id]['clashes'])->toBeFalse()
        ->and($timetable['my_hours'])->toBe(4)
        ->and(array_column($timetable['my_groups'], 'group_code'))->toBe(['A']);
});

it('la materia está en su perfil si la tiene calificada o en su historial', function () {
    $qualified = $this->world->group('Q', [[1, '09:00', '10:00']]);
    $taught = $this->world->group('H', [[2, '09:00', '10:00']]);
    $other = $this->world->group('O', [[3, '09:00', '10:00']]);
    TeacherSubjectQualification::factory()->create(['teacher_id' => $this->teacher->id, 'subject_id' => $qualified->subject_id]);
    TeacherSubjectHistory::factory()->create(['teacher_id' => $this->teacher->id, 'subject_id' => $taught->subject_id]);

    $cells = collect($this->query->forTeacher($this->world->selection, $this->teacher, CarbonImmutable::now())['cells'])->flatten(1)->keyBy('group_id');

    expect($cells[$qualified->id]['in_profile'])->toBeTrue()
        ->and($cells[$taught->id]['in_profile'])->toBeTrue()
        ->and($cells[$other->id]['in_profile'])->toBeFalse();
});

it('strips() junta cada grupo en su hora con sus días, y marca la hora que no es de una hora exacta', function () {
    $daily = $this->world->group('A', [[1, '09:00', '10:00'], [2, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $long = $this->world->group('B', [[2, '09:00', '11:00']]);

    $strips = collect(SelectionTimetableQuery::strips($this->query->forTeacher($this->world->selection, $this->teacher, CarbonImmutable::now())))->keyBy('hour');
    $nine = collect($strips['09:00']['groups'])->keyBy('group_id');

    expect($strips)->toHaveCount(14)
        ->and($strips['07:00']['groups'])->toBe([])
        ->and($nine[$daily->id]['days'])->toBe([1, 2, 3])
        ->and($nine[$daily->id]['time'])->toBeNull()
        ->and($nine[$long->id]['time'])->toBe('9:00–11:00')
        ->and($strips['09:00']['free'])->toBe(2);
});

it('dice si es su turno, hasta cuándo y su hora; a otro docente le dice quién está en turno', function () {
    $later = $this->world->teacher(base: 10, slotStartsAt: CarbonImmutable::now()->addMinutes(25), position: 2, name: 'Docente Dos');
    $entry = $this->world->entryOf($this->teacher);

    $mine = $this->query->forTeacher($this->world->selection, $this->teacher, CarbonImmutable::now())['turn'];
    $other = $this->query->forTeacher($this->world->selection, $later, CarbonImmutable::now())['turn'];
    $manager = $this->query->forTeacher($this->world->selection, null, CarbonImmutable::now())['turn'];

    expect($mine)->toMatchArray(['is_mine' => true, 'current' => 'Docente Uno', 'ends_at' => $entry->slot_ends_at->toIso8601String(), 'my_slot' => $entry->slot_starts_at->toIso8601String(), 'my_extra_slot' => null])
        ->and($other)->toMatchArray(['is_mine' => false, 'current' => 'Docente Uno', 'ends_at' => $entry->slot_ends_at->toIso8601String(), 'position' => 2, 'total' => 2])
        ->and($manager)->toMatchArray(['is_mine' => false, 'current' => 'Docente Uno', 'my_slot' => null]);
});
