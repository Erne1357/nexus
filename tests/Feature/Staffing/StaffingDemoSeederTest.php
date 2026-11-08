<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Database\Seeders\StaffingDemoSeeder;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Seeder;

/*
| Asignación docente de prueba (tareas 3.8 a 3.12): el DemoSeeder completo de Campus II, que al final corre
| StaffingDemoSeeder. Se revisa que cada pantalla de Staffing tenga todos sus casos: cargas en asignación, en ajustes y
| vetadas; rankings; asignaciones del primer lugar y fuera de él con motivo (HC-16), reemplazadas y quitadas;
| solicitudes de cambio en sus cuatro estados y la carga por docente con quien excede su tope (HC-08) y honorarios.
*/

function seedStaffingDemo(): void
{
    test()->seed(DemoSeeder::class);
    test()->term = Term::query()->where('code', '20271')->firstOrFail();
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

it('pasa a asignación las cargas sin choques duros, deja una en ajustes, veta las que los tienen y rankea sus grupos', function () {
    seedStaffingDemo();
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
});

it('asigna al primer lugar y fuera de él con motivo (HC-16), con reemplazos, docentes quitados y grupos sin docente, sin duplicar al repetir', function () {
    seedStaffingDemo();
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
    $groupsOf = fn (AcademicLoad $load) => Group::query()->active()->where('term_id', $load->term_id)->where('campus_id', $load->campus_id)->where('department_id', $load->department_id);

    foreach ($openLoads as $load) {
        expect($groupsOf($load)->whereNull('teacher_id')->count())->toBeGreaterThan(0, "Todos los grupos de la carga {$load->department_id} tienen docente.");
    }

    expect($openLoads->filter(fn (AcademicLoad $load): bool => ! $groupsOf($load)->whereNotNull('teacher_id')->exists()))->not->toBeEmpty();

    $before = [GroupTeacherAssignment::query()->count(), ChangeRequest::query()->count(), Group::query()->whereNotNull('teacher_id')->count()];

    (new class extends Seeder
    {
        public function run(): void
        {
            $this->callWith(StaffingDemoSeeder::class, ['profile' => 'demo', 'campuses' => ['C2']]);
        }
    })->setContainer(app())->__invoke();

    expect([GroupTeacherAssignment::query()->count(), ChangeRequest::query()->count(), Group::query()->whereNotNull('teacher_id')->count()])->toBe($before);
});

it('deja solicitudes de cambio en sus cuatro estados (3.11) y la carga por docente con quien excede su tope (HC-08), quien queda justo y honorarios', function () {
    seedStaffingDemo();
    $requests = ChangeRequest::query()->where('term_id', $this->term->id)->get();
    $applied = $requests->where('status', ChangeRequestStatus::Applied);

    expect($requests->where('status', ChangeRequestStatus::Pending))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Approved))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Rejected))->not->toBeEmpty()
        ->and($requests->where('status', ChangeRequestStatus::Rejected)->every(fn (ChangeRequest $request): bool => filled($request->decision_comment)))->toBeTrue()
        ->and($applied)->not->toBeEmpty()
        ->and($requests->whereNotNull('teacher_id')->count())->toBe($requests->count())
        ->and($requests->pluck('department_id')->unique()->count())->toBeGreaterThanOrEqual(2);

    $appliedLoad = AcademicLoad::query()->where('term_id', $this->term->id)->where('department_id', $applied->first()->department_id)->first();
    $approvedLoad = AcademicLoad::query()->where('term_id', $this->term->id)->where('department_id', $requests->firstWhere('status', ChangeRequestStatus::Approved)->department_id)->first();

    expect($appliedLoad->status)->toBe(LoadStatus::Staffing)
        ->and($approvedLoad->status)->toBe(LoadStatus::Adjusting);

    $loads = TeacherTermLoad::query()->where('term_id', $this->term->id)->get();
    $honorarios = TeacherPosition::query()->currentOn($this->term->starts_on)->where('kind', PositionKind::Honorarios)->pluck('teacher_id')->all();

    expect($loads)->not->toBeEmpty()
        ->and($loads->where('remaining_hours', '<', 0))->not->toBeEmpty()
        ->and($loads->where('remaining_hours', 0)->where('assigned_hours', '>', 0))->not->toBeEmpty()
        ->and($loads->whereIn('teacher_id', $honorarios)->where('assigned_hours', '>', 0))->not->toBeEmpty()
        ->and(Setting::query()->where('key', 'staffing.honorarios_max_hours')->value('value'))->toBe(20);
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => (new StaffingDemoSeeder)->setContainer(app())->run())->toThrow(RuntimeException::class);
});
