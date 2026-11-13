<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\SnapshotPayload;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\LoadSnapshotQuery;
use Carbon\CarbonImmutable;

/*
| Los grupos de la foto de una carga (tarea 4.3): los no cancelados del departamento en la modalidad y el periodo, de
| todos sus campus (M1), cada uno con su campus, con la forma de `groups` en SnapshotPayload
| (docs/dominio/modelo-de-datos.md, «Contenido del snapshot»).
*/

beforeEach(function () {
    $this->campus = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Closed, 'modality_id' => $this->campus->modality_id, 'department_id' => departmentKey(10)]);
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id, 'code' => 'A', 'name' => 'Edificio A']);
});

function snapshotGroup(array $attributes = [], ?Subject $subject = null): Group
{
    $subject ??= Subject::factory()->create(['department_id' => departmentKey(10)]);

    return Group::factory()->withQuota()->create(['term_id' => test()->load->term_id, 'campus_id' => test()->campus->id, 'subject_id' => $subject->id, ...$attributes]);
}

function snapshotGroups(): array
{
    return app(LoadSnapshotQuery::class)->groups(test()->load);
}

it('da los grupos con la forma del snapshot, que pasa la revisión del payload', function () {
    $teacher = Teacher::factory()->create(['rfc' => 'OISM850312AB1', 'name' => 'María Ortiz']);
    $room = Room::factory()->create(['building_id' => $this->building->id, 'code' => 'A-101', 'name' => 'Aula 101', 'department_id' => departmentKey(10)]);
    $subject = Subject::factory()->create(['department_id' => departmentKey(10), 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3]);
    $group = snapshotGroup(['code' => 'A', 'teacher_id' => $teacher->id, 'semester' => 4, 'capacity' => 30], $subject);
    GroupSession::factory()->for($group)->inRoom($room)->on(3, '07:00', '10:00')->practica()->create();
    GroupSession::factory()->for($group)->inRoom($room)->on(1, '07:00', '09:00')->create();

    $groups = snapshotGroups();
    $payload = [...SnapshotPayload::header($this->load, 1, User::factory()->create(), CarbonImmutable::now()), 'groups' => $groups];

    expect(SnapshotPayload::errors($payload))->toBe([])
        ->and($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray([
            'id' => $group->id,
            'code' => 'A',
            'status' => 'planned',
            'capacity' => 30,
            'justification' => null,
            'campus' => ['id' => $this->campus->id, 'code' => 'C2', 'name' => 'Campus II'],
            'subject' => ['id' => $subject->id, 'code' => 'SCD-1027', 'name' => 'Tópicos Avanzados', 'credits' => 5, 'theory_hours' => 2, 'practice_hours' => 3, 'department_id' => departmentKey(10)],
            'teacher' => ['id' => $teacher->id, 'rfc' => 'OISM850312AB1', 'name' => 'María Ortiz'],
            'placements' => [[
                'career' => ['id' => $group->ownerCareer->id, 'code' => $group->ownerCareer->code, 'name' => $group->ownerCareer->name],
                'study_plan' => ['id' => $group->studyPlan->id, 'code' => $group->studyPlan->code],
                'semester' => 4,
                'seats' => 30,
                'is_owner' => true,
            ]],
        ])
        ->and($groups[0]['sessions'])->toBe([
            ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '09:00', 'kind' => 'teoria', 'kind_label' => 'Teoría', 'room' => ['id' => $room->id, 'code' => 'A-101', 'name' => 'Aula 101', 'building' => ['id' => $this->building->id, 'code' => 'A', 'name' => 'Edificio A'], 'owner_department_id' => departmentKey(10)]],
            ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '10:00', 'kind' => 'practica', 'kind_label' => 'Práctica', 'room' => ['id' => $room->id, 'code' => 'A-101', 'name' => 'Aula 101', 'building' => ['id' => $this->building->id, 'code' => 'A', 'name' => 'Edificio A'], 'owner_department_id' => departmentKey(10)]],
        ]);
});

it('un grupo compartido cuenta en cada retícula de la carrera con cupo que tiene la materia, y sin retícula si ninguna la tiene', function () {
    $subject = Subject::factory()->create(['department_id' => departmentKey(10)]);
    $group = snapshotGroup(['capacity' => 40], $subject);
    GroupQuota::query()->where('group_id', $group->id)->update(['max_seats' => 20]);

    $ige = Career::factory()->create(['code' => 'IGE']);
    $older = StudyPlan::factory()->create(['career_id' => $ige->id, 'code' => 'IGEM-2009-201']);
    $newer = StudyPlan::factory()->create(['career_id' => $ige->id, 'code' => 'IGEM-2016-201']);
    StudyPlanSubject::factory()->create(['study_plan_id' => $older->id, 'subject_id' => $subject->id, 'semester' => 5]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $newer->id, 'subject_id' => $subject->id, 'semester' => 6]);
    GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => $ige->id, 'max_seats' => 15]);

    $ind = Career::factory()->create(['code' => 'IND']);
    StudyPlan::factory()->create(['career_id' => $ind->id]);
    GroupQuota::factory()->create(['group_id' => $group->id, 'career_id' => $ind->id, 'max_seats' => 5]);

    $placements = collect(snapshotGroups()[0]['placements'])
        ->map(fn (array $p): array => [$p['career']['code'], $p['study_plan']['code'] ?? null, $p['semester'], $p['seats'], $p['is_owner']])->all();

    expect($placements)->toBe([
        [$group->ownerCareer->code, $group->studyPlan->code, 1, 20, true],
        ['IGE', 'IGEM-2009-201', 5, 15, false],
        ['IGE', 'IGEM-2016-201', 6, 15, false],
        ['IND', null, null, 5, false],
    ]);
});

it('el salón prestado lleva a su dueño, la sesión virtual va sin salón y el grupo sin docente va con null', function () {
    $loaned = Room::factory()->create(['building_id' => $this->building->id, 'department_id' => departmentKey(11)]);
    $group = snapshotGroup();
    GroupSession::factory()->for($group)->inRoom($loaned)->on(2, '08:00', '10:00')->create();
    GroupSession::factory()->for($group)->virtual()->on(4, '19:00', '21:00')->create();

    $groups = snapshotGroups();

    expect($groups[0]['teacher'])->toBeNull()
        ->and($groups[0]['sessions'][0]['room']['owner_department_id'])->toBe(departmentKey(11))
        ->and($groups[0]['sessions'][1])->toMatchArray(['kind' => 'virtual', 'kind_label' => 'Virtual', 'room' => null]);
});

it('M1: la carga presencial de Sistemas tiene los grupos de C1 y C2, cada uno con su campus', function () {
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $subject = Subject::factory()->create(['department_id' => departmentKey(10), 'code' => 'SCD-1027']);
    snapshotGroup(['code' => 'A'], $subject);
    snapshotGroup(['code' => 'B', 'campus_id' => $c1->id], $subject);

    expect(collect(snapshotGroups())->map(fn (array $g): string => "{$g['code']} {$g['campus']['code']}")->all())->toBe(['A C2', 'B C1']);
});

it('deja fuera los grupos cancelados, los de otro departamento, otra modalidad y otro periodo, y ordena por materia y clave', function () {
    $calculo = Subject::factory()->create(['department_id' => departmentKey(10), 'code' => 'ACF-0901']);
    $taller = Subject::factory()->create(['department_id' => departmentKey(10), 'code' => 'ACA-0909']);
    snapshotGroup(['code' => 'B'], $calculo);
    snapshotGroup(['code' => 'A'], $calculo);
    snapshotGroup(['code' => 'D'], $taller);
    snapshotGroup(['code' => 'C', 'status' => 'cancelled'], $calculo);
    Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => $this->campus->id]);
    Group::factory()->create(['term_id' => $this->load->term_id, 'subject_id' => $calculo->id, 'campus_id' => Campus::factory()->create(['modality_id' => modalityOf('EAD')->id])->id]);
    Group::factory()->create(['campus_id' => $this->campus->id, 'subject_id' => $calculo->id]);

    expect(collect(snapshotGroups())->map(fn (array $g): string => "{$g['subject']['code']} {$g['code']}")->all())
        ->toBe(['ACA-0909 D', 'ACF-0901 A', 'ACF-0901 B']);
});

it('la foto dice lo que había aunque la materia, el docente o el salón ya estén borrados', function () {
    $teacher = Teacher::factory()->create();
    $room = Room::factory()->create(['building_id' => $this->building->id]);
    $group = snapshotGroup(['teacher_id' => $teacher->id]);
    GroupSession::factory()->for($group)->inRoom($room)->create();
    $group->subject->delete();
    $teacher->delete();
    $room->delete();

    $groups = snapshotGroups();

    expect($groups[0]['subject']['id'])->toBe($group->subject_id)
        ->and($groups[0]['teacher']['id'])->toBe($teacher->id)
        ->and($groups[0]['sessions'][0]['room']['id'])->toBe($room->id);
});

it('arma la foto con un número fijo de consultas', function () {
    $room = Room::factory()->create(['building_id' => $this->building->id]);

    foreach (range(1, 8) as $i) {
        $group = snapshotGroup(['teacher_id' => Teacher::factory()->create()->id]);
        GroupSession::factory()->for($group)->inRoom($room)->on(($i % 5) + 1, sprintf('%02d:00', 7 + $i), sprintf('%02d:00', 8 + $i))->create();
    }

    DB::enableQueryLog();
    snapshotGroups();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(13);
});

it('lleva la justificación de HC-15 solo del grupo al que le falta docente o salón', function () {
    $room = Room::factory()->create(['building_id' => $this->building->id]);
    $withoutTeacher = snapshotGroup(['code' => 'A', 'close_justification' => 'Docente en contratación.']);
    GroupSession::factory()->for($withoutTeacher)->inRoom($room)->create();
    $withoutRoom = snapshotGroup(['code' => 'B', 'teacher_id' => Teacher::factory()->create()->id, 'close_justification' => 'Laboratorio en remodelación.']);
    GroupSession::factory()->for($withoutRoom)->on(2, '07:00', '09:00')->create();
    $complete = snapshotGroup(['code' => 'C', 'teacher_id' => Teacher::factory()->create()->id, 'close_justification' => 'Quedó de antes.']);
    GroupSession::factory()->for($complete)->inRoom($room)->on(3, '07:00', '09:00')->create();

    expect(collect(snapshotGroups())->pluck('justification', 'code')->all())->toEqual([
        'A' => 'Docente en contratación.',
        'B' => 'Laboratorio en remodelación.',
        'C' => null,
    ]);
});
