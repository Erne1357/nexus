<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
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
| ScheduleStateQuery: la foto del periodo vista desde una modalidad, con los grupos de todos sus campus (M1), que leen
| las reglas del validador (tarea 3.2).
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['starts_on' => '2027-01-18']);
    $this->modality = Modality::query()->where('code', 'PRES')->sole();
    $this->ead = Modality::query()->where('code', 'EAD')->sole();
    $this->campus = Campus::factory()->create();
    $this->other = Campus::factory()->create();
});

function inOtherModality(array $attributes = []): Group
{
    return Group::factory()->create(['term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'modality_id' => test()->ead->id, ...$attributes]);
}

it('trae los grupos de la modalidad en todos sus campus, aun cancelados, y de otras modalidades solo los activos con docente o con salón', function () {
    $this->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'days' => [1, 2, 3, 4, 5, 6]]]);
    $teacher = Teacher::factory()->create();
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
    $here = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $there = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->other->id]);
    $cancelled = Group::factory()->cancelled()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $taught = inOtherModality(['teacher_id' => $teacher->id]);
    $roomed = inOtherModality();
    inOtherModality();
    Group::factory()->create(['campus_id' => $this->campus->id]);
    GroupSession::factory()->for($taught)->on(2, '07:00', '09:00')->create();
    GroupSession::factory()->for($roomed)->inRoom($room)->on(3, '07:00', '09:00')->create();
    GroupQuota::factory()->for($here)->create(['max_seats' => 5]);

    $state = app(ScheduleStateQuery::class)->forModality($this->term, $this->modality->id);

    expect(array_keys($state->groups()))->toEqualCanonicalizing([$here->id, $there->id, $cancelled->id, $taught->id, $roomed->id])
        ->and($state->group($cancelled->id)->isActive)->toBeFalse()
        ->and($state->group($there->id)->modalityId)->toBe($this->modality->id)
        ->and($state->teacherSessions($teacher->id))->toHaveCount(1)
        ->and($state->roomSessions($room->id))->toHaveCount(1)
        ->and($state->quotasOf($here->id)[0]->maxSeats)->toBe(5)
        ->and($state->subject($here->subject_id)->weeklyHours())->toBe(5)
        ->and($state->profile($this->modality->id)->days)->toBe([1, 2, 3, 4, 5, 6])
        ->and($state->profile($this->ead->id)->usesRooms)->toBe($this->ead->profile->usesRooms);
});

it('trae del docente sus horas base, externas, bloques no disponibles y si es de honorarios', function () {
    $teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    TeacherPosition::factory()->for($teacher)->tiempoCompleto()->create();
    TeacherPosition::factory()->for($teacher)->honorarios()->create();
    TeacherExternalLoad::factory()->for($teacher)->create(['term_id' => $this->term->id, 'hours' => 6]);
    TeacherAvailability::factory()->for($teacher)->create(['term_id' => $this->term->id, 'day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00']);
    TeacherAvailability::factory()->for($teacher)->preferred()->create(['term_id' => $this->term->id]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'teacher_id' => $teacher->id]);

    $loaded = app(ScheduleStateQuery::class)->forModality($this->term, $this->modality->id)->teacher($teacher->id);

    expect($loaded)
        ->name->toBe('Ana Pérez')
        ->baseHours->toBe(40 + TeacherPosition::query()->where('kind', 'honorarios')->value('base_hours'))
        ->externalHours->toBe(6)
        ->unavailable->toBe([[3, 420, 540]])
        ->isHonorarios->toBeTrue();
});

it('trae los salones de los campus donde la modalidad tiene grupos, con sus bloqueos del periodo o de todos los periodos', function () {
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $room = Room::factory()->ofType(RoomType::Laboratorio)->create(['building_id' => Building::factory()->create(['campus_id' => $this->campus->id])->id]);
    $elsewhere = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->other->id])->id]);
    RoomBlock::factory()->for($room)->create(['term_id' => null, 'day' => 1]);
    RoomBlock::factory()->for($room)->create(['term_id' => $this->term->id, 'day' => 2]);
    RoomBlock::factory()->for($room)->create(['term_id' => Term::factory()->create()->id, 'day' => 3]);

    $state = app(ScheduleStateQuery::class)->forModality($this->term, $this->modality->id);

    expect($state->room($room->id)->type)->toBe(RoomType::Laboratorio)
        ->and($state->room($elsewhere->id))->toBeNull()
        ->and(array_map(fn ($block) => $block->day, $state->blocksOf($room->id)))->toEqualCanonicalizing([1, 2]);
});

it('trae las carreras ofrecidas, los planes activos y los parámetros del validador', function () {
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $this->campus->id]);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.extra_hours_tolerance', 'value' => 2]);
    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'demand.target_group_size', 'value' => ['default' => 35]]);

    $state = app(ScheduleStateQuery::class)->forModality($this->term, $this->modality->id);

    expect($state->isOffered($group->owner_career_id, $this->campus->id))->toBeTrue()
        ->and($state->isOffered($group->owner_career_id, $this->other->id))->toBeFalse()
        ->and($state->isPlanActive($group->study_plan_id))->toBeTrue()
        ->and($state->setting('staffing.extra_hours_tolerance'))->toBe(2)
        ->and($state->setting('demand.target_group_size'))->toBeNull();
});

it('sin modalidad trae solo los grupos activos con docente del periodo, y los docentes que se le piden', function () {
    $teacher = Teacher::factory()->create();
    $taught = Group::factory()->create(['term_id' => $this->term->id, 'teacher_id' => $teacher->id]);
    Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $subject = Subject::query()->find($taught->subject_id);

    $query = app(ScheduleStateQuery::class);
    $state = $query->forModality($this->term);

    expect(array_keys($state->groups()))->toBe([$taught->id])
        ->and($state->subject($subject->id)->name)->toBe($subject->name)
        ->and($query->teachers($this->term, []))->toBe([])
        ->and($query->teachers($this->term, [$teacher->id])[0]->id)->toBe($teacher->id);
});

it('trae los préstamos de salón vivos de los campus de la modalidad, aceptados o propuestos (HC-10)', function () {
    $group = Group::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id]);
    $accepted = InterdepartmentalAgreement::factory()->accepted()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $group->id, 'day' => 2]);
    InterdepartmentalAgreement::factory()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $group->id, 'day' => 3]);
    InterdepartmentalAgreement::factory()->rejected()->create(['term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'group_id' => $group->id, 'day' => 4]);

    $loans = app(ScheduleStateQuery::class)->forModality($this->term, $this->modality->id)->loansOf($group->id);

    expect($loans)->toHaveCount(2)
        ->and(collect($loans)->firstWhere('day', 2))->roomId->toBe($accepted->room_id)->start->toBe(540)->end->toBe(660)->isAccepted->toBeTrue()
        ->and(collect($loans)->firstWhere('day', 3)->isAccepted)->toBeFalse();
});
