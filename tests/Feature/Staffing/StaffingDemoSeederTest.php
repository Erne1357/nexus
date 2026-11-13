<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\CatalogValidation\Database\Seeders\CatalogValidationDemoSeeder;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\OrgChartSeeder;
use App\Modules\Core\Database\Seeders\TermOpeningDemoSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Faculty\Database\Seeders\TeacherCaptureDemoSeeder;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Database\Seeders\SchedulingDemoSeeder;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ClosingBlockersQuery;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Database\Seeders\LoadClosingDemoSeeder;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
| Asignación docente de prueba (tareas 3.8 a 3.12): el DemoSeeder completo de Campus II, que al final corre
| StaffingDemoSeeder. Se revisa que cada pantalla de Staffing tenga todos sus casos: cargas en asignación, en ajustes y
| vetadas; rankings; asignaciones del primer lugar y fuera de él con motivo (HC-16), reemplazadas y quitadas;
| solicitudes de cambio en sus cuatro estados y la carga por docente con quien excede su tope (HC-08) y honorarios;
| y, con LoadClosingDemoSeeder, una carga cerrada con justificaciones (4.1).
| Aparte, un mundo chico con C1 y C2 para los traslados justos entre campus (SC-09) y el determinismo.
*/

function seedStaffingDemo(): void
{
    test()->seed(DemoSeeder::class);
    test()->term = Term::query()->where('code', '20271')->firstOrFail();
}

/**
 * La cadena del DemoSeeder hasta Scheduling, sin Staffing, con C1 y C2 y pocos docentes y alumnos (los mínimos que siguen dando traslados justos: es la prueba más cara del archivo).
 */
function seedTwoCampusWorld(): void
{
    (new class extends Seeder
    {
        use WithoutModelEvents;

        public function run(): void
        {
            $this->call([CampusSeeder::class, OrgChartSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class]);
            $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
            $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C1', 'C2']]);
            $this->callWith(FacilitiesDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
            $this->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C1' => 30, 'C2' => 40]]);
            $this->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C1' => 6, 'C2' => 10]]);
            $this->callWith(TermOpeningDemoSeeder::class, ['openings' => ['20271' => ['PRES']]]);
            $this->callWith(TeacherCaptureDemoSeeder::class, ['profile' => 'demo']);
            $this->callWith(CatalogValidationDemoSeeder::class, ['profile' => 'demo']);
            Event::fakeFor(fn () => $this->callWith(AcademicLoadDemoSeeder::class, ['campuses' => ['C1', 'C2']]), [GroupsCreated::class]);
            $this->callWith(SchedulingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C1', 'C2']]);
        }
    })->setContainer(app())->__invoke();
}

/**
 * Lo que dejó la asignación docente: grupos con docente (campus, materia, grupo y RFC) y los conteos de cada caso.
 *
 * @return array<string, mixed>
 */
function staffingSnapshot(): array
{
    $assignments = GroupTeacherAssignment::query()->get();

    return [
        'groups' => Group::query()->join('subjects', 'subjects.id', '=', 'groups.subject_id')->join('campuses', 'campuses.id', '=', 'groups.campus_id')
            ->join('teachers', 'teachers.id', '=', 'groups.teacher_id')
            ->orderBy('campuses.code')->orderBy('subjects.code')->orderBy('groups.code')
            ->get(['campuses.code as campus', 'subjects.code as subject', 'groups.code as code', 'teachers.rfc as rfc'])
            ->map(fn (Group $group): string => "{$group->campus}|{$group->subject}|{$group->code}|{$group->rfc}")->all(),
        'assignments' => [$assignments->count(), $assignments->whereNull('replaced_at')->count(), $assignments->whereNotNull('reason')->count()],
        'requests' => ChangeRequest::query()->get()->countBy(fn (ChangeRequest $request): string => $request->status->value)->sortKeys()->all(),
        'over' => TeacherTermLoad::query()->where('remaining_hours', '<', 0)->count(),
    ];
}

/**
 * @return array<string, list<string>> estado de la carga => códigos de departamento
 */
function staffingLoadsByStatus(): array
{
    return AcademicLoad::query()->where('term_id', test()->term->id)->with('department')->get()
        ->groupBy(fn (AcademicLoad $load): string => $load->status->value)
        ->map(fn ($loads): array => $loads->pluck('department.code')->sort()->values()->all())
        ->all();
}

it('deja cada pantalla de Staffing con sus casos: cargas en cada estado y rankeadas, asignaciones dentro y fuera del primer lugar (HC-16), reemplazos y quitadas, solicitudes en sus cuatro estados (3.11) y carga por docente (HC-08), una carga cerrada con justificaciones (4.1), sin duplicar al repetir', function () {
    // Un solo DemoSeeder para todas las revisiones: es lo más caro del archivo.
    seedStaffingDemo();

    // Cargas: en asignación, en ajustes y vetadas en propuesta, con sus grupos rankeados.
    $byStatus = staffingLoadsByStatus();
    $open = AcademicLoad::query()->where('term_id', $this->term->id)
        ->whereIn('status', [LoadStatus::Staffing, LoadStatus::Adjusting])->pluck('department_id');
    $groups = Group::query()->active()->where('term_id', $this->term->id)->whereIn('department_id', $open)->pluck('id');
    $ranked = StaffingRanking::query()->whereIn('group_id', $groups)->distinct()->pluck('group_id');

    expect($byStatus[LoadStatus::Staffing->value] ?? [])->not->toBeEmpty()
        ->and($byStatus[LoadStatus::Adjusting->value] ?? [])->not->toBeEmpty()
        ->and($byStatus[LoadStatus::Proposed->value] ?? [])->not->toBeEmpty()
        ->and($groups)->not->toBeEmpty()
        ->and($ranked->count())->toBeGreaterThan((int) floor($groups->count() * 0.9));

    // Asignaciones: primer lugar sin motivo, fuera de él con motivo, reemplazos, quitadas y grupos sin docente.
    $assignments = GroupTeacherAssignment::query()->where('term_id', $this->term->id)->get();
    $latest = $assignments->sortByDesc(fn (GroupTeacherAssignment $row): string => $row->assigned_at->format('U.u').$row->id)->groupBy('group_id')->map->first();
    $replaced = $assignments->filter(fn (GroupTeacherAssignment $row): bool => $row->replaced_at !== null && $latest[$row->group_id]->id !== $row->id);
    $removed = $latest->filter(fn (GroupTeacherAssignment $row): bool => $row->replaced_at !== null);

    expect($assignments->where('ranking_position_at_assignment', 1)->whereNull('reason')->count())->toBeGreaterThan(0)
        ->and($assignments->filter(fn (GroupTeacherAssignment $row): bool => $row->ranking_position_at_assignment !== 1)->every(fn (GroupTeacherAssignment $row): bool => filled($row->reason)))->toBeTrue()
        ->and($assignments->filter(fn (GroupTeacherAssignment $row): bool => $row->ranking_position_at_assignment > 1 && filled($row->reason))->count())->toBeGreaterThan(0)
        ->and($replaced)->not->toBeEmpty()
        ->and($removed)->not->toBeEmpty();

    $openLoads = AcademicLoad::query()->where('term_id', $this->term->id)->whereIn('status', [LoadStatus::Staffing, LoadStatus::Adjusting])->get();
    $groupsOf = fn (AcademicLoad $load) => Group::query()->active()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('department_id', $load->department_id);

    foreach ($openLoads as $load) {
        expect($groupsOf($load)->whereNull('teacher_id')->count())->toBeGreaterThan(0, "Todos los grupos de la carga {$load->department_id} tienen docente.");
    }

    expect($openLoads->filter(fn (AcademicLoad $load): bool => ! $groupsOf($load)->whereNotNull('teacher_id')->exists()))->not->toBeEmpty();

    // Solicitudes de cambio y carga por docente.
    $requests = ChangeRequest::query()->where('term_id', $this->term->id)->get();
    $applied = $requests->where('status', ChangeRequestStatus::Applied);

    expect($requests->where('status', ChangeRequestStatus::Pending))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Approved))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Rejected))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Rejected)->every(fn (ChangeRequest $request): bool => filled($request->decision_comment)))->toBeTrue()
        ->and($applied)->not->toBeEmpty()
        ->and($requests->whereNotNull('teacher_id')->count())->toBe($requests->count())
        ->and($requests->pluck('department_id')->unique()->count())->toBeGreaterThanOrEqual(2);

    // La carga de la solicitud es la de su departamento en su modalidad (M1).
    $loadOf = fn (ChangeRequest $request): AcademicLoad => AcademicLoad::query()->ofDepartment($this->term->id, $request->modality_id, $request->department_id)->sole();
    $appliedLoad = $loadOf($applied->first());
    $approvedLoad = $loadOf($requests->firstWhere('status', ChangeRequestStatus::Approved));

    // La carga de las solicitudes aplicadas regresó sola a asignación; después LoadClosingDemoSeeder pudo cerrarla.
    expect($appliedLoad->transitions()->where('from_status', LoadStatus::Adjusting)->where('to_status', LoadStatus::Staffing)->exists())->toBeTrue()
        ->and($approvedLoad->status)->toBe(LoadStatus::Adjusting);

    // Casos de cierre (tarea 4.1, LoadClosingDemoSeeder en modo mixed): una carga cerrada por su jefe, sin pendientes de
    // cierre, con sus grupos confirmados y justificaciones; sin solicitudes pendientes. SC se queda en asignación.
    $closed = AcademicLoad::query()->where('term_id', $this->term->id)->where('status', LoadStatus::Closed)->with(['department', 'modality', 'term'])->get();

    expect($closed)->not->toBeEmpty()
        ->and($closed->pluck('department.code')->all())->not->toContain('SC')
        ->and($byStatus[LoadStatus::Staffing->value] ?? [])->toContain('SC');

    foreach ($closed as $load) {
        expect(app(ClosingBlockersQuery::class)->of($load)->isClear())->toBeTrue()
            ->and($groupsOf($load)->where('status', '!=', GroupStatus::Confirmed)->exists())->toBeFalse()
            ->and($groupsOf($load)->whereNotNull('close_justification')->exists())->toBeTrue()
            ->and($load->closed_by)->not->toBeNull()
            ->and(ChangeRequest::query()->where('department_id', $load->department_id)->where('status', ChangeRequestStatus::Pending)->exists())->toBeFalse();
    }

    $loads = TeacherTermLoad::query()->where('term_id', $this->term->id)->get();
    $honorarios = TeacherPosition::query()->currentOn($this->term->starts_on)->where('kind', PositionKind::Honorarios)->pluck('teacher_id')->all();

    expect($loads)->not->toBeEmpty()
        ->and($loads->where('remaining_hours', '<', 0))->not->toBeEmpty()
        ->and($loads->where('remaining_hours', 0)->where('assigned_hours', '>', 0))->not->toBeEmpty()
        ->and($loads->whereIn('teacher_id', $honorarios)->where('assigned_hours', '>', 0))->not->toBeEmpty()
        ->and(Setting::query()->where('key', 'staffing.honorarios_max_hours')->value('value'))->toBe(20);

    $seeded = $loads->whereIn('teacher_id', GroupTeacherAssignment::query()->current()->distinct()->pluck('teacher_id')->all());

    expect($seeded)->not->toBeEmpty()
        ->and($seeded->filter(fn (TeacherTermLoad $load): bool => $load->assigned_hours > $load->limit_hours)->pluck('teacher_id')->all())->toBe([])
        ->and($seeded->where('remaining_hours', '<', 0)->every(fn (TeacherTermLoad $load): bool => $load->external_hours > 0))->toBeTrue()
        ->and($loads->where('remaining_hours', '<', 0)->filter(fn (TeacherTermLoad $load): bool => $load->assigned_hours > $load->limit_hours)->pluck('teacher_id')->all())->toBe([]);

    // Volver a correr no duplica nada.
    $snapshot = fn (): array => [
        GroupTeacherAssignment::query()->count(), ChangeRequest::query()->count(), Group::query()->whereNotNull('teacher_id')->count(),
        LoadTransition::query()->count(), Group::query()->whereNotNull('close_justification')->count(),
    ];
    $before = $snapshot();

    (new class extends Seeder
    {
        public function run(): void
        {
            $this->callWith(StaffingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2']]);
            $this->callWith(LoadClosingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2'], 'keepOpen' => ['SC']]);
        }
    })->setContainer(app())->__invoke();

    expect($snapshot())->toBe($before);
});

it('con dos campus siembra traslados justos entre campus (SC-09) y con la misma semilla deja la misma asignación', function () {
    seedTwoCampusWorld();
    $term = Term::query()->where('code', '20271')->firstOrFail();
    $output = new BufferedOutput;
    $seed = function () use ($output): void {
        $command = new Command;
        $command->setLaravel(app());
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        (new StaffingDemoSeeder)->setContainer(app())->setCommand($command)->run('demo', ['C1', 'C2'], '20271', 2027, ['campus_travel' => 4]);
    };

    DB::beginTransaction();
    $seed();
    $first = staffingSnapshot();
    preg_match('/(\d+) con traslado justo/', $output->fetch(), $picked);

    // MS6: la solicitud de horario de un grupo la aplica un coordinador que cubre el campus del grupo, no el del primer campus.
    $appliedSchedules = ChangeRequest::query()->with(['group', 'appliedBy'])->where('status', ChangeRequestStatus::Applied)->where('kind', ChangeRequestKind::Schedule)->get();
    $appliedByOutsider = $appliedSchedules->reject(fn (ChangeRequest $request): bool => $request->appliedBy->can('apply', $request))->count();
    $seededGroups = GroupTeacherAssignment::query()->current()->pluck('group_id')->flip();
    app(ScheduleValidator::class)->forget();
    $travel = GroupTeacherAssignment::query()->current()->distinct()->pluck('teacher_id')
        ->flatMap(fn (string $id): array => app(ScheduleValidator::class)->validateTeacher(Teacher::query()->findOrFail($id), $term)->forRule('SC-09'))
        ->filter(fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Soft && $seededGroups->has($violation->groupId));
    DB::rollBack();

    $seed();

    expect((int) ($picked[1] ?? 0))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4)
        ->and($appliedSchedules)->not->toBeEmpty()
        ->and($appliedByOutsider)->toBe(0)
        ->and($travel)->not->toBeEmpty()
        ->and($first['groups'])->not->toBeEmpty()
        ->and(staffingSnapshot())->toBe($first);
});

it('MS6: la solicitud de horario de un grupo de C2 la aplica el coordinador que cubre C2, aunque el primero de la carrera sea de C1', function () {
    $pres = modalityOf('PRES');
    $c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $department = Department::factory()->create(['code' => 'SC']);
    $career = Career::factory()->create(['code' => 'ISC']);
    $term = Term::factory()->create();
    $group = Group::factory()->create(['term_id' => $term->id, 'campus_id' => $c2->id, 'modality_id' => $pres->id, 'subject_id' => Subject::factory()->create(['department_id' => $department->id])->id, 'owner_career_id' => $career->id]);
    // El de C1 se nombra primero: el seeder de antes tomaba al primer titular del primer campus.
    $ofC1 = User::factory()->withRole(Role::Coordinador, ['modality_id' => $pres->id, 'campus_id' => $c1->id, 'career_id' => $career->id])->create();
    $ofC2 = User::factory()->withRole(Role::Coordinador, ['modality_id' => $pres->id, 'campus_id' => $c2->id, 'career_id' => $career->id])->create();
    $head = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create();
    $schedule = ChangeRequest::factory()->forGroup($group)->schedule()->approved()->create();
    $room = ChangeRequest::factory()->forGroup($group)->approved()->create(['kind' => ChangeRequestKind::Room]);
    $applier = new ReflectionMethod(StaffingDemoSeeder::class, 'applier');
    $seeder = new StaffingDemoSeeder;

    expect($applier->invoke($seeder, $schedule, $head)->is($ofC2))->toBeTrue()
        ->and($ofC1->can('apply', $schedule))->toBeFalse()
        ->and($ofC2->can('apply', $schedule))->toBeTrue()
        ->and($applier->invoke($seeder, $room, $head)->is($head))->toBeTrue();
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new StaffingDemoSeeder)->setContainer(app())->run())->toThrow(RuntimeException::class);
});
