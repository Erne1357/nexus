<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Database\Seeders\LoadClosingDemoSeeder;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\StaffingServiceProvider;
use Database\Seeders\DatabaseSeeder;

/*
| El cierre de la carga en la demo (tarea 4.1; docs/arquitectura/testing.md, «Demo por puntos del proceso»): los tres
| pasos del punto 8 «publicacion» (terreno limpio, grupos justificados y cargas cerradas) y el modo `mixed` de la demo
| mezclada (`todo`), que cierra unas cargas y reabre una. Un mundo chico de Campus II con departamentos de grupos
| completos: horario, docente con su asignación y salón.
*/

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['username' => 'admin']));
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->building = Building::factory()->create(['campus_id' => $this->campus->id]);
});

/**
 * Un departamento con su carga en `$status` y un grupo completo por cada día de `$days` (lunes de 7:00 a 9:00, martes…),
 * cada uno de su semestre, con su docente de 20 h (o el de `$hours`) y su aula; el de `$labDay`, en un laboratorio.
 *
 * @param  list<int>  $days
 * @param  array<int, int>  $hours  día => horas del docente de ese grupo
 */
function loadClosingDepartment(string $code, LoadStatus $status = LoadStatus::Staffing, array $days = [1, 2], ?int $labDay = null, array $hours = []): AcademicLoad
{
    $department = Department::factory()->create(['code' => $code]);
    $load = AcademicLoad::factory()->inStatus($status)->create(['term_id' => test()->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => $department->id]);
    $subject = Subject::factory()->create(['department_id' => $department->id, 'theory_hours' => 2, 'practice_hours' => 0]);
    $career = Career::factory()->create(['department_id' => $department->id]);
    CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => test()->campus->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);

    foreach ($days as $day) {
        $teacher = Teacher::factory()->create();
        TeacherPosition::factory()->for($teacher)->asignatura($hours[$day] ?? 20)->create();
        $group = Group::factory()->withQuota(25)->create([
            'term_id' => test()->term->id, 'campus_id' => test()->campus->id, 'subject_id' => $subject->id, 'owner_career_id' => $career->id,
            'study_plan_id' => $plan->id, 'semester' => $day, 'code' => "{$code}{$day}", 'capacity' => 30, 'teacher_id' => $teacher->id,
        ]);
        GroupTeacherAssignment::factory()->create(['group_id' => $group->id, 'teacher_id' => $teacher->id]);
        $room = Room::factory()->capacity(40)->ofType($day === $labDay ? RoomType::Laboratorio : RoomType::Aula)->create(['building_id' => test()->building->id]);
        GroupSession::factory()->for($group)->on($day, '07:00', '09:00')->inRoom($room)->create();
    }

    return $load;
}

function loadClosingGroup(AcademicLoad $load, int $day): Group
{
    return Group::query()->where('department_id', $load->department_id)->where('semester', $day)->firstOrFail();
}

/**
 * @param  list<string>  $keepOpen
 */
function loadClosingSeed(string $mode, array $keepOpen = []): void
{
    (new DatabaseSeeder)->setContainer(app())
        ->callWith(LoadClosingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'mode' => $mode, 'keepOpen' => $keepOpen]);
}

it('en el punto 8 deja el terreno limpio, justifica grupos sin docente y un laboratorio sin salón, cierra cada carga con grupos y deja en borrador la que no tiene, con las revisiones del punto en verde', function () {
    $ii = loadClosingDepartment('II', days: [1, 2, 3], labDay: 3);
    $cea = loadClosingDepartment('CEA', days: [4, 5]);
    $draft = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id, 'department_id' => Department::factory()->create(['code' => 'EE'])->id]);
    $request = ChangeRequest::factory()->forGroup(loadClosingGroup($cea, 4))->pending()->create();
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create([
        'term_id' => $this->term->id, 'group_id' => loadClosingGroup($ii, 1)->id,
        'from_department_id' => $ii->department_id, 'to_department_id' => $cea->department_id,
    ]);

    foreach (['clean', 'justified', 'closed'] as $mode) {
        loadClosingSeed($mode);
    }

    $groups = Group::query()->get();
    $justified = $groups->whereNotNull('close_justification');
    $lab = $justified->firstWhere('close_justification', 'El laboratorio se entrega remodelado en enero.');

    expect($request->refresh()->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($request->decision_comment)->not->toBeEmpty()
        ->and($shared->refresh()->status)->toBe(AgreementStatus::Accepted)
        ->and(GroupQuota::query()->where('group_id', $shared->group_id)->where('career_id', $shared->career_id)->exists())->toBeTrue()
        ->and($groups->whereNull('teacher_id')->count())->toBeBetween(2, 4)
        ->and($groups->whereNull('teacher_id')->every(fn (Group $group): bool => filled($group->close_justification)))->toBeTrue()
        ->and($lab)->not->toBeNull()
        ->and($lab->id)->toBe(loadClosingGroup($ii, 3)->id)
        ->and($lab->sessions()->sole()->room_id)->toBeNull()
        ->and($justified->every(fn (Group $group): bool => $group->close_justified_by !== null))->toBeTrue()
        ->and(GroupTeacherAssignment::query()->whereNull('replaced_at')->whereIn('group_id', $groups->whereNull('teacher_id')->modelKeys())->exists())->toBeFalse()
        ->and($ii->refresh()->status)->toBe(LoadStatus::Closed)
        ->and($cea->refresh()->status)->toBe(LoadStatus::Closed)
        ->and($draft->refresh()->status)->toBe(LoadStatus::Draft)
        ->and($groups->every(fn (Group $group): bool => $group->status === GroupStatus::Confirmed))->toBeTrue();

    $checks = collect(app()->getProvider(StaffingServiceProvider::class)->demoChecks())
        ->filter(fn (DemoCheck $check): bool => in_array(DemoCheckpoint::Publication, $check->at, true))
        ->mapWithKeys(fn (DemoCheck $check): array => [$check->label => ($check->passes)(DemoContext::for(DemoVolume::Demo))]);

    expect($checks->count())->toBeGreaterThanOrEqual(5)
        ->and($checks->reject(fn (true|string $result): bool => $result === true)->all())->toBe([]);

    loadClosingSeed('justified');

    expect(Group::query()->whereNotNull('close_justification')->count())->toBe($justified->count());
});

it('en el punto 8 falla con el motivo del veto si una carga no se puede cerrar (HC-15)', function () {
    $ii = loadClosingDepartment('II', days: [1]);
    loadClosingGroup($ii, 1)->update(['teacher_id' => null]);

    expect(fn () => loadClosingSeed('closed'))->toThrow(RuntimeException::class, 'La carga de II en C2 no se cierra: HC-15: 1 grupo no tiene docente ni justificación.')
        ->and($ii->refresh()->status)->toBe(LoadStatus::Staffing);
});

it('en la demo mezclada cierra cargas con justificaciones, quita al docente que se pasa de su tope (HC-08), reabre la última con su motivo y no toca las reservadas ni las de solicitudes aprobadas, sin repetir al volver a correr', function () {
    $cea = loadClosingDepartment('CEA', days: [1, 2], hours: [2 => 1]);
    $ii = loadClosingDepartment('II', days: [3, 4]);
    loadClosingGroup($ii, 4)->update(['teacher_id' => null]);
    $mm = loadClosingDepartment('MM', days: [5]);
    ChangeRequest::factory()->forGroup(loadClosingGroup($mm, 5))->approved()->create();
    $sc = loadClosingDepartment('SC', days: [1]);
    $pending = ChangeRequest::factory()->forGroup(loadClosingGroup($cea, 1))->pending()->create();

    loadClosingSeed('mixed', keepOpen: ['SC']);

    $overLimit = loadClosingGroup($cea, 2);
    $reopening = LoadTransition::query()->where('academic_load_id', $ii->id)->where('to_status', LoadStatus::Adjusting)->sole();

    expect($cea->refresh()->status)->toBe(LoadStatus::Closed)
        ->and($overLimit->teacher_id)->toBeNull()
        ->and($overLimit->close_justification)->not->toBeEmpty()
        ->and($pending->refresh()->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($ii->refresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($ii->closed_at)->not->toBeNull()
        ->and($reopening->from_status)->toBe(LoadStatus::Closed)
        ->and($reopening->comment)->toContain('se reabre')
        ->and(loadClosingGroup($ii, 4)->close_justification)->not->toBeEmpty()
        ->and(loadClosingGroup($ii, 3)->status)->toBe(GroupStatus::Confirmed)
        ->and($mm->refresh()->status)->toBe(LoadStatus::Staffing)
        ->and($sc->refresh()->status)->toBe(LoadStatus::Staffing);

    $transitions = LoadTransition::query()->count();
    loadClosingSeed('mixed', keepOpen: ['SC']);

    expect(LoadTransition::query()->count())->toBe($transitions);
});

it('en la demo mezclada, con una sola carga que se puede cerrar, la deja cerrada sin reabrir ninguna', function () {
    $cea = loadClosingDepartment('CEA', days: [1]);

    loadClosingSeed('mixed');

    expect($cea->refresh()->status)->toBe(LoadStatus::Closed);
});

it('rechaza un modo que no existe', function () {
    expect(fn () => loadClosingSeed('todo'))->toThrow(RuntimeException::class, 'Modo desconocido');
});
