<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\GroupFitQuery;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Support\Facades\DB;

/*
| GroupFitQuery (tarea 3.5): para pintar «dónde cabe» un grupo en el editor. Por cada día y bloque del perfil del
| campus, sin las horas donde el grupo ya tiene sesión, pregunta al validador por una sesión de un bloque y dice si
| cabe (sin reglas duras nuevas) y, si no, qué reglas rompe y sus mensajes. Lee la foto una vez y revisa cada celda
| contra la parte de la foto que la toca, con el mismo resultado que la foto completa. Un grupo que no se edita
| (cancelado, o con la carga fuera de propuesta, asignación o ajustes) no tiene celdas.
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'teacher_id' => $this->teacher->id]);
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'department_id' => $this->group->department_id,
    ]);
});

function fitGroup(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, ...$attributes]);
}

it('da una celda por cada día y bloque del perfil, sin las horas donde el grupo ya tiene sesión', function () {
    GroupSession::factory()->for($this->group)->on(1, '07:00', '09:00')->create();

    $cells = app(GroupFitQuery::class)->for($this->group);

    expect($cells)->toHaveCount(5 * 14 - 2)
        ->and($cells)->not->toHaveKeys(['1|07:00', '1|08:00'])
        ->and($cells['1|09:00'])->toBe(['day' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00', 'fits' => true, 'rules' => [], 'messages' => []])
        ->and($cells['5|20:00']['ends_at'])->toBe('21:00');
});

it('HC-01: marca las celdas donde el docente ya da clase en otro grupo, con la regla y el mensaje', function () {
    $busy = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id, 'code' => 'F',
        'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
    GroupSession::factory()->for($busy)->on(2, '10:00', '12:00')->create();

    $cells = app(GroupFitQuery::class)->for($this->group);

    expect($cells['2|10:00'])->toBe([
        'day' => 2, 'starts_at' => '10:00', 'ends_at' => '11:00', 'fits' => false,
        'rules' => ['HC-01'], 'messages' => ['Ana Pérez ya da Química, grupo F el martes de 10:00 a 12:00.'],
    ])
        ->and($cells['2|11:00']['fits'])->toBeFalse()
        ->and($cells['2|09:00']['fits'])->toBeTrue()
        ->and($cells['2|12:00']['fits'])->toBeTrue();
});

it('no cuenta contra una celda lo que el grupo ya rompía antes', function () {
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
    GroupSession::factory()->for($this->group)->inRoom($room)->on(3, '07:00', '08:00')->create();
    $other = GroupSession::factory()->for(fitGroup())->on(3, '07:00', '08:00')->create();
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $other->id)->update(['room_id' => $room->id]);

    $cells = app(GroupFitQuery::class)->for($this->group);

    expect(array_filter($cells, fn (array $cell): bool => ! $cell['fits']))->toBe([]);
});

it('da lo mismo que preguntar al validador con la foto completa, celda por celda', function () {
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
    GroupSession::factory()->for($this->group)->inRoom($room)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for(fitGroup(['teacher_id' => $this->teacher->id]))->on(2, '10:00', '12:00')->create();
    GroupSession::factory()->for(Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $this->teacher->id]))->on(3, '07:00', '09:00')->create();
    GroupSession::factory()->for(fitGroup(['owner_career_id' => $this->group->owner_career_id, 'study_plan_id' => $this->group->study_plan_id]))->inRoom($room)->on(4, '08:00', '10:00')->create();
    GroupSession::factory()->for(fitGroup())->inRoom($room)->on(5, '09:00', '10:00')->create();

    $validator = app(ScheduleValidator::class);
    $before = array_map(fn (Violation $violation): string => $violation->key(), $validator->validateGroup($this->group)->violations());
    $cells = app(GroupFitQuery::class)->for($this->group);
    $candidates = array_map(fn (array $cell): SessionCandidate => new SessionCandidate($cell['day'], $cell['starts_at'], $cell['ends_at']), $cells);
    $expected = array_map(function (ValidationResult $result) use ($before): array {
        $new = array_values(array_filter($result->violations(), fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard
            && ! in_array($violation->key(), $before, true) && $violation->rule !== 'HC-12'));

        return [$new === [], array_values(array_unique(array_map(fn (Violation $violation): string => $violation->rule, $new))), array_map(fn (Violation $violation): string => $violation->message, $new)];
    }, $validator->checkSessions($this->group, $candidates));

    expect(array_map(fn (array $cell): array => [$cell['fits'], $cell['rules'], $cell['messages']], $cells))->toBe($expected)
        ->and(array_keys(array_filter($cells, fn (array $cell): bool => ! $cell['fits'])))->toBe(['2|10:00', '2|11:00', '3|07:00', '3|08:00']);
});

it('un grupo cancelado o con la carga fuera de propuesta, asignación o ajustes no tiene celdas', function (Closure $setUp) {
    $setUp($this);

    expect(app(GroupFitQuery::class)->for($this->group->fresh()))->toBe([]);
})->with([
    'grupo cancelado' => [fn ($test) => $test->group->update(['status' => 'cancelled'])],
    'carga cerrada' => [fn ($test) => $test->load->update(['status' => LoadStatus::Closed])],
    'sin carga' => [fn ($test) => $test->load->delete()],
]);

it('sigue los días, la ventana y el bloque del perfil del campus', function () {
    $this->campus->update(['settings' => [...$this->campus->settings, 'days' => [6], 'block_minutes' => 30, 'day_start' => '08:00', 'day_end' => '10:00']]);

    expect(array_keys(app(GroupFitQuery::class)->for($this->group->fresh())))->toBe(['6|08:00', '6|08:30', '6|09:00', '6|09:30']);
});

it('un campus sin bloques de tiempo no tiene celdas', function () {
    $this->campus->update(['settings' => [...$this->campus->settings, 'uses_time_blocks' => false]]);

    expect(app(GroupFitQuery::class)->for($this->group->fresh()))->toBe([]);
});

it('con 9 grupos en el campus y 70 celdas lee con pocas consultas', function () {
    foreach (range(1, 9) as $index) {
        $group = fitGroup(['teacher_id' => Teacher::factory()->create()->id]);
        GroupSession::factory()->for($group)->on(1 + $index % 5, sprintf('%02d:00', 7 + $index), sprintf('%02d:00', 8 + $index))->create();
    }

    DB::enableQueryLog();
    $cells = app(GroupFitQuery::class)->for($this->group);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($cells)->toHaveCount(70)
        ->and($queries)->toBeLessThanOrEqual(20);
});

it('con 200 grupos en el campus revisa las 70 celdas con pocas consultas', function () {
    $teachers = Teacher::factory()->count(50)->create()->prepend($this->teacher)->values();
    $building = Building::factory()->create(['campus_id' => $this->campus->id]);
    $rooms = Room::factory()->count(30)->create(['building_id' => $building->id]);
    $subject = Subject::factory()->create();
    $shared = ['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $subject->id, 'department_id' => $subject->department_id,
        'owner_career_id' => $this->group->owner_career_id, 'study_plan_id' => $this->group->study_plan_id];
    $groups = collect(range(0, 199))->map(fn (int $index): Group => Group::factory()->create([...$shared, 'semester' => $index % 9 + 1, 'teacher_id' => $teachers[$index % 51]->id]));

    DB::table('group_sessions')->insert($groups->map(fn (Group $group, int $index): array => [
        'group_id' => $group->id,
        'day' => $index % 5 + 1,
        'starts_at' => sprintf('%02d:00', 7 + intdiv($index, 5) % 14),
        'ends_at' => sprintf('%02d:00', 8 + intdiv($index, 5) % 14),
        'room_id' => $rooms[$index % 30]->id,
        'kind' => 'teoria',
    ])->all());

    DB::enableQueryLog();
    $cells = app(GroupFitQuery::class)->for($this->group);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($cells)->toHaveCount(70)
        ->and(array_filter($cells, fn (array $cell): bool => ! $cell['fits']))->not->toBe([])
        ->and($queries)->toBeLessThanOrEqual(25);
});
