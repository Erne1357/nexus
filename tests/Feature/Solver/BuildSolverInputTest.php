<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Solver\Contract\GroupInput;
use App\Modules\Solver\Contract\ScopeKind;
use App\Modules\Solver\Contract\SessionKind;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Queries\BuildSolverInput;
use App\Modules\Solver\Queries\SolverInputBuild;

/*
| BuildSolverInput: arma el contrato de entrada del motor desde la base (tarea 6.4). Una corrida es la carga de un
| departamento en una modalidad, en todos sus campus, o los grupos de una carrera; lo demás entra fijo
| (docs/arquitectura/solver.md, «Descomposición del problema»).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->pres = modalityOf('PRES');
    $this->ead = modalityOf('EAD');
    $this->s1 = Campus::factory()->create(['code' => 'S1']);
    $this->s2 = Campus::factory()->create(['code' => 'S2']);
    $this->s3 = Campus::factory()->create(['code' => 'S3']);

    $this->sistemas = Department::factory()->create(['name' => 'Sistemas']);
    $this->basicas = Department::factory()->create(['name' => 'Ciencias Básicas']);
    $this->isc = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $this->iind = Career::factory()->create(['department_id' => $this->sistemas->id]);
    $this->planIsc = StudyPlan::factory()->create(['career_id' => $this->isc->id]);
    $this->planInd = StudyPlan::factory()->create(['career_id' => $this->iind->id]);

    $this->prog = buildInputSubject($this->sistemas, 'SCC-1010');
    $this->bd = buildInputSubject($this->sistemas, 'SCD-1020');
    $this->calc = buildInputSubject($this->basicas, 'AEF-1041');
    $this->fis = buildInputSubject($this->basicas, 'AEF-1042');

    $this->t1 = Teacher::factory()->create();
    $this->t2 = Teacher::factory()->create();
    TeacherPosition::factory()->for($this->t1)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($this->t2)->medioTiempo()->create();
    TeacherAvailability::factory()->for($this->t1)->create(['term_id' => $this->term->id, 'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00']);
    TeacherAvailability::factory()->for($this->t1)->preferred()->create(['term_id' => $this->term->id, 'day' => 4, 'starts_at' => '08:00', 'ends_at' => '10:00']);

    $this->roomS1 = buildInputRoom($this->s1, $this->sistemas);
    $this->roomS1b = buildInputRoom($this->s1, null);
    $this->roomS2 = buildInputRoom($this->s2, null);
    $this->roomS3 = buildInputRoom($this->s3, null);

    // Movibles del departamento de Sistemas.
    $this->progS1 = buildInputGroup($this->prog, $this->s1, $this->isc, $this->planIsc, 1, ['teacher_id' => $this->t1->id]);
    $this->bdS2 = buildInputGroup($this->bd, $this->s2, $this->isc, $this->planIsc, 1);
    $this->indS1 = buildInputGroup($this->prog, $this->s1, $this->iind, $this->planInd, 2);

    // Tronco común de otro departamento, de la misma carrera: comparten cohorte con los movibles.
    $this->calcS1 = buildInputGroup($this->calc, $this->s1, $this->isc, $this->planIsc, 1, ['teacher_id' => $this->t2->id]);
    $this->calcS2 = buildInputGroup($this->calc, $this->s2, $this->isc, $this->planIsc, 1);

    // Comparte docente con un movible, sin cohorte.
    $this->sharedTeacher = buildInputGroup($this->fis, $this->s1, $this->iind, $this->planInd, 3, ['teacher_id' => $this->t1->id]);

    // No tiene nada en común con los movibles.
    $this->unrelated = buildInputGroup($this->fis, $this->s1, $this->iind, $this->planInd, 5);

    // Otra modalidad con el docente, y uno cancelado.
    $this->eadGroup = buildInputGroup($this->fis, $this->s1, $this->iind, $this->planInd, 7, ['teacher_id' => $this->t1->id, 'modality_id' => $this->ead->id]);
    $this->cancelled = buildInputGroup($this->fis, $this->s1, $this->iind, $this->planInd, 8, ['teacher_id' => $this->t1->id, 'status' => 'cancelled']);

    GroupSession::factory()->for($this->progS1)->inRoom($this->roomS1)->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->progS1)->practica()->on(3, '09:00', '10:00')->create();
    GroupSession::factory()->for($this->sharedTeacher)->inRoom($this->roomS1)->on(3, '07:00', '08:00')->create();
    GroupSession::factory()->for($this->unrelated)->inRoom($this->roomS1b)->on(4, '11:00', '12:00')->create();
    GroupSession::factory()->for($this->eadGroup)->on(5, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->cancelled)->on(2, '07:00', '09:00')->create();
});

function buildInputSubject(Department $department, string $code): Subject
{
    return Subject::factory()->create(['department_id' => $department->id, 'code' => $code, 'theory_hours' => 3, 'practice_hours' => 2]);
}

function buildInputRoom(Campus $campus, ?Department $owner): Room
{
    $building = Building::factory()->create(['campus_id' => $campus->id]);

    return Room::factory()->create(['building_id' => $building->id, 'department_id' => $owner?->id, 'capacity' => 40]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function buildInputGroup(Subject $subject, Campus $campus, Career $career, StudyPlan $plan, int $semester, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->term->id,
        'campus_id' => $campus->id,
        'modality_id' => test()->pres->id,
        'subject_id' => $subject->id,
        'owner_career_id' => $career->id,
        'study_plan_id' => $plan->id,
        'semester' => $semester,
        'capacity' => 30,
        ...$attributes,
    ]);
}

function buildInputForDepartment(?Department $department = null): SolverInputBuild
{
    return app(BuildSolverInput::class)->forDepartment(test()->term, test()->pres, $department ?? test()->sistemas, 'run-1');
}

function buildInputForCareer(?Career $career = null): SolverInputBuild
{
    return app(BuildSolverInput::class)->forCareer(test()->term, test()->pres, $career ?? test()->isc, 'run-2');
}

/**
 * @return array<string, GroupInput>
 */
function buildInputGroupsOf(SolverInputBuild $build): array
{
    return collect($build->input->groups)->keyBy('id')->all();
}

it('por departamento: sus grupos de todos los campus son movibles y los demás que comparten docente, salón o cohorte entran fijos', function () {
    $groups = buildInputGroupsOf(buildInputForDepartment());

    expect(array_keys($groups))->toEqualCanonicalizing([
        $this->progS1->id, $this->bdS2->id, $this->indS1->id,
        $this->calcS1->id, $this->calcS2->id, $this->sharedTeacher->id,
    ])
        ->and(collect($groups)->filter->movable->keys()->all())->toEqualCanonicalizing([$this->progS1->id, $this->bdS2->id, $this->indS1->id])
        ->and($groups[$this->calcS1->id]->movable)->toBeFalse()
        ->and($groups[$this->sharedTeacher->id]->movable)->toBeFalse()
        ->and($groups[$this->unrelated->id] ?? null)->toBeNull()
        ->and($groups[$this->eadGroup->id] ?? null)->toBeNull()
        ->and($groups[$this->cancelled->id] ?? null)->toBeNull();
});

it('un movible lleva sus sesiones actuales y un fijo las suyas como sesiones fijas, con el salón y el tipo', function () {
    $groups = buildInputGroupsOf(buildInputForDepartment());
    $prog = $groups[$this->progS1->id];
    $shared = $groups[$this->sharedTeacher->id];

    expect($prog->fixedSessions)->toBe([])
        ->and($prog->currentSessions)->toHaveCount(2)
        ->and($prog->currentSessions[0]->toArray())->toBe(['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'room_id' => $this->roomS1->id, 'kind' => 'teoria'])
        ->and($prog->currentSessions[1]->kind)->toBe(SessionKind::Practice)
        ->and($prog->currentSessions[1]->roomId)->toBeNull()
        ->and($shared->currentSessions)->toBe([])
        ->and($shared->fixedSessions)->toHaveCount(1)
        ->and($shared->fixedSessions[0]->roomId)->toBe($this->roomS1->id);
});

it('trae del grupo su campus, materia, horas, capacidad, docente y dueños', function () {
    $prog = buildInputGroupsOf(buildInputForDepartment())[$this->progS1->id];

    expect($prog)
        ->campusCode->toBe('S1')
        ->subjectCode->toBe('SCC-1010')
        ->departmentId->toBe($this->sistemas->id)
        ->ownerCareerId->toBe($this->isc->id)
        ->studyPlanId->toBe($this->planIsc->id)
        ->semester->toBe(1)
        ->weeklyHours->toBe(5)
        ->practiceHours->toBe(2)
        ->needsLab->toBeFalse()
        ->capacity->toBe(30)
        ->teacherId->toBe($this->t1->id)
        ->candidateRoomIds->toBe([]);
});

it('HC-03: cada grupo lleva su clave de cohorte y los que la comparten se ven, también los fijos', function () {
    $groups = buildInputGroupsOf(buildInputForDepartment());

    expect($groups[$this->progS1->id]->cohorts)->toBe(["plan:{$this->planIsc->id}:1:S1"])
        ->and($groups[$this->calcS1->id]->cohorts)->toBe(["plan:{$this->planIsc->id}:1:S1"])
        ->and($groups[$this->bdS2->id]->cohorts)->toBe(["plan:{$this->planIsc->id}:1:S2"])
        ->and($groups[$this->calcS2->id]->cohorts)->toBe(["plan:{$this->planIsc->id}:1:S2"]);
});

it('los campus de la corrida son los de los grupos que entran, con la rejilla del perfil de la modalidad', function () {
    $this->pres->update(['settings' => [...$this->pres->profile->toArray(), 'day_start' => '07:00', 'day_end' => '15:00', 'days' => [1, 2, 3, 4, 5, 6]]]);

    $campuses = collect(buildInputForDepartment()->input->campuses)->keyBy('code');

    expect($campuses->keys()->all())->toEqualCanonicalizing(['S1', 'S2'])
        ->and($campuses['S1']->toArray())->toBe(['code' => 'S1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '15:00', 'days' => [1, 2, 3, 4, 5, 6], 'uses_rooms' => true]);
});

it('trae los salones de esos campus con su tipo, capacidad, edificio, dueño, bloqueos y ocupación de grupos fuera de la corrida', function () {
    RoomBlock::factory()->for($this->roomS1)->create(['term_id' => $this->term->id, 'day' => 5, 'starts_at' => '13:00', 'ends_at' => '14:00']);

    $rooms = collect(buildInputForDepartment()->input->rooms)->keyBy('id');
    $room = $rooms[$this->roomS1->id];

    expect($rooms->keys()->all())->toEqualCanonicalizing([$this->roomS1->id, $this->roomS1b->id, $this->roomS2->id])
        ->and($room)
        ->campusCode->toBe('S1')
        ->type->toBe($this->roomS1->type->value)
        ->capacity->toBe(40)
        ->buildingId->toBe($this->roomS1->building_id)
        ->ownerDepartmentId->toBe($this->sistemas->id)
        ->and($room->blocks)->toHaveCount(1)
        ->and($room->blocks[0]->toArray())->toBe(['day' => 5, 'starts_at' => '13:00', 'ends_at' => '14:00'])
        ->and($room->busy)->toBe([])
        ->and($rooms[$this->roomS2->id]->ownerDepartmentId)->toBeNull()
        ->and(array_map(fn ($slot) => $slot->toArray(), $rooms[$this->roomS1b->id]->busy))->toBe([['day' => 4, 'starts_at' => '11:00', 'ends_at' => '12:00']]);
});

it('trae los docentes de los grupos con horas restantes, honorarios, no disponibilidad, preferencias y ocupación de otras modalidades', function () {
    $teachers = collect(buildInputForDepartment()->input->teachers)->keyBy('id');
    $t1 = $teachers[$this->t1->id];

    // 40 h base, menos las 5 del grupo de otra modalidad y las 5 del fijo; las del movible no cuentan (se reprograman).
    expect($teachers->keys()->all())->toEqualCanonicalizing([$this->t1->id, $this->t2->id])
        ->and($t1->remainingHours)->toBe(30)
        ->and($t1->honorarios)->toBeFalse()
        ->and(array_map(fn ($slot) => $slot->toArray(), $t1->unavailable))->toBe([['day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00']])
        ->and(array_map(fn ($slot) => $slot->toArray(), $t1->preferred))->toBe([['day' => 4, 'starts_at' => '08:00', 'ends_at' => '10:00']])
        ->and(array_map(fn ($slot) => $slot->toArray(), $t1->busy))->toBe([['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00', 'campus_code' => 'S1']])
        ->and($teachers[$this->t2->id]->remainingHours)->toBe(15);
});

it('un docente de honorarios se marca y un grupo cancelado no ocupa al docente', function () {
    TeacherPosition::factory()->for($this->t2)->honorarios()->create();

    $teachers = collect(buildInputForDepartment()->input->teachers)->keyBy('id');

    expect($teachers[$this->t2->id]->honorarios)->toBeTrue()
        ->and(collect($teachers[$this->t1->id]->busy)->pluck('day')->all())->not->toContain(2);
});

it('HC-10: los préstamos aceptados a un grupo de la corrida son acuerdos; los propuestos no', function () {
    $owned = buildInputRoom($this->s2, $this->basicas);
    $loan = fn (array $attributes, bool $accepted = false) => InterdepartmentalAgreement::factory()->when($accepted, fn ($factory) => $factory->accepted())->create([
        'term_id' => $this->term->id, 'campus_id' => $this->s2->id, 'from_department_id' => $this->sistemas->id,
        'to_department_id' => $this->basicas->id, 'group_id' => $this->bdS2->id, 'room_id' => $owned->id, ...$attributes,
    ]);
    $loan(['day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00'], accepted: true);
    $loan(['day' => 3, 'starts_at' => '09:00', 'ends_at' => '11:00']);

    $input = buildInputForDepartment()->input;

    expect($input->agreements)->toHaveCount(1)
        ->and($input->agreements[0]->toArray())->toBe(['room_id' => $owned->id, 'to_department_id' => $this->sistemas->id, 'day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00'])
        ->and(collect($input->rooms)->pluck('id')->all())->toContain($owned->id)
        ->and($input->subjectRoomDefaults)->toBe([]);
});

it('trae el contrato: versión, corrida, periodo, modalidad, alcance, traslado, reglas duras y pesos blandos', function () {
    $input = buildInputForDepartment()->input;

    expect($input)
        ->version->toBe(1)
        ->runId->toBe('run-1')
        ->termCode->toBe($this->term->code)
        ->modalityCode->toBe('PRES')
        ->travelMinutes->toBe(60)
        ->timeLimitSeconds->toBe(300)
        ->hardRules->toBe(['HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-05', 'HC-06', 'HC-07', 'HC-08', 'HC-09', 'HC-10', 'HC-11', 'HC-12', 'HC-13'])
        ->and($input->scope->kind)->toBe(ScopeKind::Department)
        ->and($input->scope->id)->toBe($this->sistemas->id)
        ->and($input->softWeights->toArray())->toBe(['SC-01' => 10, 'SC-02' => 6, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2])
        ->and($input->options->suggestTeachers)->toBeFalse();
});

it('la tolerancia de HC-12 sale del perfil de la modalidad y, sin ella, de la configuración global', function () {
    expect(buildInputForDepartment()->input->weeklyHoursTolerance)->toBe(0);

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'scheduling.weekly_hours_tolerance', 'value' => 15]);
    expect(buildInputForDepartment()->input->weeklyHoursTolerance)->toBe(15);

    $this->pres->update(['settings' => [...$this->pres->settings, 'weekly_hours_tolerance' => 30]]);
    expect(buildInputForDepartment()->input->weeklyHoursTolerance)->toBe(30);
});

it('el traslado y el tiempo máximo salen de los parámetros', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.min_campus_travel_minutes', 'value' => 45]);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'solver.time_limits', 'value' => ['department' => 120, 'career' => 20]]);

    expect(buildInputForDepartment()->input)->travelMinutes->toBe(45)->timeLimitSeconds->toBe(120)
        ->and(buildInputForCareer()->input)->timeLimitSeconds->toBe(20);
});

it('por carrera: solo los grupos de la carrera son movibles y entran fijos los que comparten con ellos', function () {
    $build = buildInputForCareer();
    $groups = buildInputGroupsOf($build);

    expect($build->input->scope->kind)->toBe(ScopeKind::Career)
        ->and($build->input->scope->id)->toBe($this->isc->id)
        ->and(collect($groups)->filter->movable->keys()->all())->toEqualCanonicalizing([$this->progS1->id, $this->bdS2->id, $this->calcS1->id, $this->calcS2->id])
        ->and($groups[$this->sharedTeacher->id]->movable)->toBeFalse()
        ->and($groups[$this->indS1->id] ?? null)->toBeNull()
        ->and($groups[$this->unrelated->id] ?? null)->toBeNull();
});

it('avisa del tronco común: un grupo de otro departamento que comparte cohorte y todavía no tiene horario', function () {
    $warnings = buildInputForDepartment()->warnings;

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toBe('Ciencias Básicas todavía no tiene horario aceptado para AEF-1041; lo que se acepte puede cambiar.');
});

it('el aviso del tronco común desaparece cuando esos grupos ya tienen sesiones, y no aplica a la carrera que los lleva', function () {
    expect(buildInputForCareer()->warnings)->toBe([]);

    GroupSession::factory()->for($this->calcS1)->on(2, '07:00', '09:00')->create();
    expect(buildInputForDepartment()->warnings)->toHaveCount(1);

    GroupSession::factory()->for($this->calcS2)->on(2, '07:00', '09:00')->create();
    expect(buildInputForDepartment()->warnings)->toBe([]);
});

it('la entrada armada es un contrato válido: se lee y vuelve a su JSON', function (string $scope) {
    $input = ($scope === 'department' ? buildInputForDepartment() : buildInputForCareer())->input;

    $data = $input->toArray();

    expect(SolverInput::fromArray($data)->toArray())->toBe($data)
        ->and(json_decode(json_encode($data), true))->toBe($data);
})->with(['department', 'career']);

it('un departamento sin grupos en la modalidad da una entrada sin grupos ni campus', function () {
    $input = buildInputForDepartment(Department::factory()->create())->input;

    expect($input->groups)->toBe([])->and($input->campuses)->toBe([]);
});

it('una modalidad sin salones no trae salones y sus sesiones van sin salón', function () {
    $this->pres->update(['settings' => [...$this->pres->profile->toArray(), 'uses_rooms' => false]]);

    $input = buildInputForDepartment()->input;

    expect($input->rooms)->toBe([])
        ->and($input->campuses[0]->usesRooms)->toBeFalse()
        ->and(collect($input->groups)->flatMap(fn ($group) => [...$group->fixedSessions, ...$group->currentSessions])->pluck('roomId')->filter()->all())->toBe([]);
});

it('un grupo fuera de la corrida que usa un salón que un movible ya usa entra fijo, aunque no comparta nada más', function () {
    $roomMate = buildInputGroup($this->fis, $this->s1, $this->iind, $this->planInd, 9);
    GroupSession::factory()->for($roomMate)->inRoom($this->roomS1)->on(2, '07:00', '08:00')->create();

    $groups = buildInputGroupsOf(buildInputForDepartment());

    expect($groups[$roomMate->id]->movable)->toBeFalse()
        ->and($groups[$roomMate->id]->fixedSessions)->toHaveCount(1);
});

it('HC-03: los grupos de una instancia de paquete llevan su clave de cohorte y los de otra carga que la comparten entran fijos', function () {
    $package = ProposalPackage::factory()->create();
    $loose = buildInputGroup($this->prog, $this->s1, $this->isc, $this->planIsc, 1, ['package_id' => $package->id, 'package_number' => 2, 'code' => 'PK1']);
    $basic = buildInputGroup($this->fis, $this->s1, $this->isc, $this->planIsc, 1, ['package_id' => $package->id, 'package_number' => 2, 'code' => 'PK2']);
    $other = buildInputGroup($this->fis, $this->s1, $this->isc, $this->planIsc, 1, ['package_id' => $package->id, 'package_number' => 1, 'code' => 'PK3']);

    $groups = buildInputGroupsOf(buildInputForDepartment($this->sistemas));

    expect($groups[$loose->id]->cohorts)->toContain("package:{$package->id}:S1:2")
        ->and($groups[$loose->id]->movable)->toBeTrue()
        ->and($groups[$basic->id]->cohorts)->toContain("package:{$package->id}:S1:2")
        ->and($groups[$basic->id]->movable)->toBeFalse()
        ->and($groups[$other->id] ?? null)->toBeNull();
});

it('HC-03: el mismo paquete con el mismo número en dos campus no queda en la misma cohorte', function () {
    $package = ProposalPackage::factory()->create();
    $inS1 = buildInputGroup($this->prog, $this->s1, $this->isc, $this->planIsc, 1, ['package_id' => $package->id, 'package_number' => 1, 'code' => 'PK1']);
    $inS2 = buildInputGroup($this->prog, $this->s2, $this->isc, $this->planIsc, 1, ['package_id' => $package->id, 'package_number' => 1, 'code' => 'PK2']);

    $groups = buildInputGroupsOf(buildInputForDepartment($this->sistemas));
    $keyOf = fn (Group $group): array => array_values(array_filter($groups[$group->id]->cohorts, fn (string $key): bool => str_starts_with($key, 'package:')));

    expect($keyOf($inS1))->toBe(["package:{$package->id}:S1:1"])
        ->and($keyOf($inS2))->toBe(["package:{$package->id}:S2:1"]);
});

it('los pesos blandos salen del parámetro solver.soft_weights, con SC-01 por la llave sc_01', function () {
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'solver.soft_weights', 'value' => ['sc_01' => 0, 'sc_02' => 50]]);

    expect(buildInputForDepartment()->input->softWeights->toArray())->toBe(['SC-01' => 0, 'SC-02' => 50, 'SC-03' => 5, 'SC-04' => 4, 'SC-05' => 3, 'SC-06' => 2, 'SC-07' => 3, 'SC-08' => 2, 'SC-09' => 2]);
});
