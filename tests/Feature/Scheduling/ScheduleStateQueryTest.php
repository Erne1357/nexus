<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;

/*
| ScheduleStateQuery: la foto del periodo vista desde un campus que leen las reglas del validador (tarea 3.2).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->campus = Campus::factory()->create(['settings' => ['days' => [1, 2, 3, 4, 5, 6]]]);
    $this->other = Campus::factory()->create();
});

it('trae los grupos del campus, aun cancelados, y de otros campus solo los activos con docente', function () {
    $teacher = Teacher::factory()->create();
    $here = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $cancelled = Group::factory()->cancelled()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $taught = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->other->id, 'teacher_id' => $teacher->id]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->other->id]);
    Group::factory()->create(['campus_id' => $this->campus->id]);
    GroupSession::factory()->for($taught)->on(2, '07:00', '09:00')->create();
    GroupQuota::factory()->for($here)->create(['max_seats' => 5]);

    $state = app(ScheduleStateQuery::class)->forCampus($this->term, $this->campus);

    expect(array_keys($state->groups()))->toEqualCanonicalizing([$here->id, $cancelled->id, $taught->id])
        ->and($state->group($cancelled->id)->isActive)->toBeFalse()
        ->and($state->teacherSessions($teacher->id))->toHaveCount(1)
        ->and($state->quotasOf($here->id)[0]->maxSeats)->toBe(5)
        ->and($state->subject($here->subject_id)->weeklyHours())->toBe(5)
        ->and($state->profile($this->campus->id)->days)->toBe([1, 2, 3, 4, 5, 6]);
});

it('trae del docente sus horas base, externas, bloques no disponibles y si es de honorarios', function () {
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($teacher)->honorarios()->create();
    TeacherExternalLoad::factory()->for($teacher)->create(['term_id' => $this->term->id, 'hours' => 6]);
    TeacherAvailability::factory()->for($teacher)->create(['term_id' => $this->term->id, 'day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']);
    TeacherAvailability::factory()->for($teacher)->preferred()->create(['term_id' => $this->term->id]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'teacher_id' => $teacher->id]);

    $loaded = app(ScheduleStateQuery::class)->forCampus($this->term, $this->campus)->teacher($teacher->id);

    expect($loaded)
        ->name->toBe('Ana Pérez')
        ->baseHours->toBe(40 + TeacherPosition::query()->where('kind', 'honorarios')->value('base_hours'))
        ->externalHours->toBe(6)
        ->unavailable->toBe([[3, 420, 540]])
        ->isHonorarios->toBeTrue();
});

it('trae los salones del campus con sus bloqueos del periodo o de todos los periodos', function () {
    $room = Room::factory()->ofType(RoomType::Laboratorio)->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
    RoomBlock::factory()->for($room)->create(['term_id' => null, 'day' => 1]);
    RoomBlock::factory()->for($room)->create(['term_id' => $this->term->id, 'day' => 2]);
    RoomBlock::factory()->for($room)->create(['term_id' => Term::factory()->create()->id, 'day' => 3]);

    $state = app(ScheduleStateQuery::class)->forCampus($this->term, $this->campus);

    expect($state->room($room->id)->type)->toBe(RoomType::Laboratorio)
        ->and(array_map(fn ($block) => $block->day, $state->blocksOf($room->id)))->toEqualCanonicalizing([1, 2]);
});

it('trae las carreras ofrecidas, los planes activos y los parámetros del validador', function () {
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $this->campus->id]);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.extra_hours_tolerance', 'value' => 2]);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'demand.target_group_size', 'value' => ['default' => 35]]);

    $state = app(ScheduleStateQuery::class)->forCampus($this->term, $this->campus);

    expect($state->isOffered($group->owner_career_id, $this->campus->id))->toBeTrue()
        ->and($state->isOffered($group->owner_career_id, $this->other->id))->toBeFalse()
        ->and($state->isPlanActive($group->study_plan_id))->toBeTrue()
        ->and($state->setting('staffing.extra_hours_tolerance'))->toBe(2)
        ->and($state->setting('demand.target_group_size'))->toBeNull();
});

it('sin campus trae solo los grupos activos con docente del periodo, y los docentes que se le piden', function () {
    $teacher = Teacher::factory()->create();
    $taught = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $teacher->id]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $subject = Subject::query()->find($taught->subject_id);

    $query = app(ScheduleStateQuery::class);
    $state = $query->forCampus($this->term);

    expect(array_keys($state->groups()))->toBe([$taught->id])
        ->and($state->subject($subject->id)->name)->toBe($subject->name)
        ->and($query->teachers($this->term, []))->toBe([])
        ->and($query->teachers($this->term, [$teacher->id])[0]->id)->toBe($teacher->id);
});
