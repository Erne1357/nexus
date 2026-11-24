<?php

namespace Tests\Support\Staffing;

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Atajos de las pruebas de las solicitudes de cambio de la demo (tests/Feature/Staffing/ChangeRequestsDemo*Test.php):
 * el mundo del punto `reanalisis-casos`, los grupos que movió la demo y la comprobación de que quedó al día.
 */
final class ChangeRequestsWorld
{
    /**
     * Un docente de SC con horas libres da una hora en Campus I, en un grupo de Ciencias Básicas sin choques (su carrera se
     * ofrece ahí), justo antes de una hora en que uno de sus grupos de SC podría ir sin chocar: su salón está libre, nadie de
     * su semestre tiene clase y el docente está libre y disponible. Así mover ahí esa sesión deja solo el aviso de traslado.
     */
    public static function crossCampusTeacher(Term $term): void
    {
        $sc = Department::query()->where('code', 'SC')->sole();
        $teacherId = TeacherTermLoad::query()->where('term_id', $term->id)->where('remaining_hours', '>=', 4)
            ->whereIn('teacher_id', Group::query()->active()->where('term_id', $term->id)->where('department_id', $sc->id)->select('teacher_id'))
            ->join('teachers', 'teachers.id', '=', 'teacher_term_loads.teacher_id')->whereNotNull('teachers.user_id')
            ->orderBy('teachers.rfc')->value('teacher_term_loads.teacher_id');
        $group = Group::query()->active()->with('sessions')->where('term_id', $term->id)->where('department_id', $sc->id)->where('teacher_id', $teacherId)->orderBy('code')->first();
        $session = $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->first();
        $length = (strtotime((string) $session->ends_at) - strtotime((string) $session->starts_at)) / 3600;
        $sessions = GroupSession::query()->with('group')->whereHas('group', fn ($query) => $query->active()->where('term_id', $term->id))->get();
        $unavailable = TeacherAvailability::query()->where('teacher_id', $teacherId)->where('term_id', $term->id)->where('kind', AvailabilityKind::Unavailable->value)->get();
        $overlaps = fn (int $day, int $from, int $to, $rows): bool => $rows->contains(fn ($row): bool => (int) $row->day === $day
            && (int) substr((string) $row->starts_at, 0, 2) < $to && $from < (int) substr((string) $row->ends_at, 0, 2));
        $slot = collect([1, 2, 3, 4, 5])->crossJoin(range(8, 21 - $length))->first(fn (array $slot): bool => ! $overlaps($slot[0], $slot[1] - 1, $slot[1] + $length, $sessions->where('group.teacher_id', $teacherId))
            && ! $overlaps($slot[0], $slot[1] - 1, $slot[1] + $length, $unavailable)
            && ! $overlaps($slot[0], $slot[1], $slot[1] + $length, $sessions->where('room_id', $session->room_id))
            && ! $overlaps($slot[0], $slot[1], $slot[1] + $length, $sessions->filter(fn (GroupSession $other): bool => $other->group->campus_id === $group->campus_id
                && $other->group->study_plan_id === $group->study_plan_id && $other->group->semester === $group->semester)));
        $cb = Department::query()->where('code', 'CB')->sole();
        $c1 = Campus::query()->where('code', 'C1')->value('id');
        $away = Group::factory()->create([
            'term_id' => $term->id, 'campus_id' => $c1, 'modality_id' => modalityOf('PRES')->id,
            'department_id' => $cb->id, 'subject_id' => Subject::factory()->create(['department_id' => $cb->id, 'theory_hours' => 1, 'practice_hours' => 0])->id,
            'teacher_id' => $teacherId, 'code' => 'T1',
        ]);
        CareerCampus::factory()->create(['career_id' => $away->owner_career_id, 'campus_id' => $c1]);
        GroupSession::factory()->for($away)->on($slot[0], sprintf('%02d:00', $slot[1] - 1), sprintf('%02d:00', $slot[1]))->create();
    }

    /**
     * La demo real hasta `reanalisis` con SC como carga reservada y el docente con clases en los dos campus.
     *
     * @return object{term: Term, sc: AcademicLoad, demo: DemoContext, plan: DemoPlan, step: DemoStep, check: DemoCheck, cases: Closure(): array<string, string>, run: Closure(): void, request: Closure(string): ?ChangeRequest}
     */
    public static function world(): object
    {
        $failures = [];

        app(DemoRunner::class)->run(DemoCheckpoint::Reanalysis, DemoContext::for(DemoVolume::Demo), function (DemoCheckpoint $checkpoint, array $results) use (&$failures): void {
            foreach ($results as $result) {
                if (! $result['passed']) {
                    $failures[] = "{$checkpoint->value}: {$result['label']} ({$result['reason']})";
                }
            }
        });

        expect($failures)->toBe([]);

        $term = Term::query()->where('code', '20271')->sole();
        $modality = modalityOf('PRES');
        $department = fn (string $code): string => Department::query()->where('code', $code)->value('id');
        AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $modality->id)->whereIn('department_id', [$department('EE'), $department('MM')])
            ->update(['status' => LoadStatus::Published->value, 'current_version' => 1]);
        self::crossCampusTeacher($term);
        test()->actingAs(User::query()->where('username', 'admin')->sole());

        $sc = AcademicLoad::query()->ofDepartment($term->id, $modality->id, $department('SC'))->sole();
        $demo = DemoContext::for(DemoVolume::Demo);
        $plan = app(DemoPlan::class);
        $step = collect($plan->stepsFor(DemoCheckpoint::ReanalysisCases))->last();

        return (object) [
            'term' => $term,
            'sc' => $sc,
            'demo' => $demo,
            'plan' => $plan,
            'step' => $step,
            'check' => collect($plan->checksAt(DemoCheckpoint::ReanalysisCases))->first(fn (DemoCheck $check): bool => str_contains($check->label, 'solicitudes de cambio')),
            'cases' => fn (): array => collect(ReanalysisDemoSeeder::plan($term->id, $modality->id))
                ->mapWithKeys(fn (string $case, string $id): array => [AcademicLoad::query()->with('department')->find($id)->department->code => $case])->sortKeys()->all(),
            // Como DemoRunner: sin eventos de modelo y con los eventos silenciados de la demo.
            'run' => fn () => Model::withoutEvents(fn () => Event::fakeFor(fn () => ($step->run)($demo), $plan->quietEvents())),
            'request' => fn (string $key): ?ChangeRequest => ChangeRequest::query()->where('department_id', $sc->department_id)->where('reason', ChangeRequestsDemoSeeder::SCENARIOS[$key]['reason'])->first(),
        ];
    }

    /**
     * Los grupos que la demo movió de hora: el de la aplicada como se pidió con horario, y los de las alternativas escritas
     * (la aplicada y la acomodada a mano).
     *
     * @param  Closure(string): ?ChangeRequest  $request
     * @return list<string>
     */
    public static function moved(Closure $request): array
    {
        return [$request('ap1')->group_id, ...array_column(ChangeAlternatives::chosen($request('y')->alternatives)['groups'], 'group_id'),
            ...array_column(ChangeAlternatives::chosen($request('m')->alternatives)['groups'], 'group_id')];
    }

    /**
     * Lo que dejaría el oyente de GroupScheduleChanged: las claves de los grupos movidos ya siguen su hora y los choques de
     * la modalidad están al día (un recálculo no abre ni resuelve nada).
     *
     * @param  Closure(string): ?ChangeRequest  $request
     */
    public static function expectSettled(Closure $request, Term $term): void
    {
        $groups = Group::query()->whereKey(self::moved($request))->orderBy('id')->get();
        $codes = $groups->pluck('code', 'id')->all();

        foreach ($groups as $group) {
            app(AssignGroupCode::class)->handle($group);
        }

        $synced = app(SyncScheduleConflicts::class)->handle($term, modalityOf('PRES'));

        expect(Group::query()->whereKey(array_keys($codes))->pluck('code', 'id')->all())->toEqual($codes)
            ->and([$synced['opened'], $synced['resolved']])->toBe([0, 0]);
    }

    /** @return array<string, string> */
    public static function expectedCases(): array
    {
        return [
            'CB' => ReanalysisDemoSeeder::CASE_CORRECTED, 'CEA' => ReanalysisDemoSeeder::CASE_CHANGED, 'EE' => ReanalysisDemoSeeder::CASE_RESTARTED,
            'II' => ReanalysisDemoSeeder::CASE_AVAILABLE, 'MM' => ReanalysisDemoSeeder::CASE_REPUBLISHED, 'SC' => ReanalysisDemoSeeder::CASE_CHANGE_REQUESTS,
        ];
    }
}
