<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Target;
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
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Faculty\Enums\AvailabilityKind;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\TeacherTermLoad;
use App\Modules\Staffing\Notifications\ChangeRequestMovedTeacherGroups;
use App\Modules\Staffing\Support\ChangeAlternatives;
use Database\Seeders\DemoSeeder;
use Database\Seeders\MassiveSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| Las solicitudes de cambio de la fase 8 (8.6 y 8.7) en el punto `reanalisis-casos`: el paso de Staffing reabre a ajustes
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí una solicitud en cada estado de la
| pantalla, por las Actions reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza
| como en el masivo: publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay
| docentes con clases en los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en
| Campus I en un grupo de otro departamento, sin choques. Corre la demo real hasta `reanalisis` y solo el paso nuevo.
*/

/**
 * Un docente de SC con horas libres da una hora en Campus I, en un grupo de Ciencias Básicas sin choques (su carrera se
 * ofrece ahí), justo antes de una hora en que uno de sus grupos de SC podría ir sin chocar: su salón está libre, nadie de
 * su semestre tiene clase y el docente está libre y disponible. Así mover ahí esa sesión deja solo el aviso de traslado.
 */
function crossCampusTeacherForChangeRequests(Term $term): void
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
function changeRequestsWorld(): object
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
    crossCampusTeacherForChangeRequests($term);
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
function movedByChangeRequests(Closure $request): array
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
function expectChangeRequestsSettled(Closure $request, Term $term): void
{
    $groups = Group::query()->whereKey(movedByChangeRequests($request))->orderBy('id')->get();
    $codes = $groups->pluck('code', 'id')->all();

    foreach ($groups as $group) {
        app(AssignGroupCode::class)->handle($group);
    }

    $synced = app(SyncScheduleConflicts::class)->handle($term, modalityOf('PRES'));

    expect(Group::query()->whereKey(array_keys($codes))->pluck('code', 'id')->all())->toEqual($codes)
        ->and([$synced['opened'], $synced['resolved']])->toBe([0, 0]);
}

$expectedCases = fn (): array => [
    'CB' => ReanalysisDemoSeeder::CASE_CORRECTED, 'CEA' => ReanalysisDemoSeeder::CASE_CHANGED, 'EE' => ReanalysisDemoSeeder::CASE_RESTARTED,
    'II' => ReanalysisDemoSeeder::CASE_AVAILABLE, 'MM' => ReanalysisDemoSeeder::CASE_REPUBLISHED, 'SC' => ReanalysisDemoSeeder::CASE_CHANGE_REQUESTS,
];

it('deja en la carga reservada una solicitud de cambio en cada estado de la pantalla, con sus alternativas vigentes, y repetirlo no duplica nada', function () use ($expectedCases) {
    $w = changeRequestsWorld();
    $request = $w->request;
    $status = fn (string $key): ?ChangeRequestStatus => $request($key)?->status;
    $alternatives = fn (string $key): ?ChangeAlternativesStatus => ChangeAlternativesStatus::of($request($key)->alternatives);

    expect(($w->cases)())->toBe($expectedCases())
        ->and($w->step->label)->toContain('Solicitudes de cambio')
        ->and(($w->check->passes)($w->demo))->toStartWith('SC en PRES: la carga está en Publicada, r1 sin pedir');

    ($w->run)();

    expect(($w->check->passes)($w->demo))->toBeTrue()
        ->and(($w->cases)())->toBe($expectedCases());

    // La carga: reabierta a ajustes por Subdirección, de vuelta a asignación al aplicar `y` y otra vez en ajustes al aprobar.
    expect($w->sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($w->sc->transitions()->where('from_status', LoadStatus::Published->value)->where('to_status', LoadStatus::Adjusting->value)->value('comment'))->toBe(ChangeRequestsDemoSeeder::REOPEN_REASON)
        ->and($w->sc->transitions()->where('from_status', LoadStatus::Adjusting->value)->where('to_status', LoadStatus::Staffing->value)->exists())->toBeTrue();

    // Las decididas llevan el comentario del jefe.
    foreach (ChangeRequestsDemoSeeder::SCENARIOS as $key => $scenario) {
        if ($scenario['comment'] !== null && $status($key) !== ChangeRequestStatus::Pending) {
            expect($request($key)->decision_comment)->toBe($scenario['comment'], "Comentario de {$key}");
        }
    }

    // Pendientes sin alternativas: sin choques, con solo el aviso de traslado, con choque duro sin pedir y solo texto.
    foreach (['p1', 'p2'] as $key) {
        expect($request($key)->impact)->toMatchArray(['assessable' => true, 'hard' => [], 'soft' => []]);
    }

    expect(collect(['p7', 'p8', 'p9'])->map(fn (string $key): bool => $request($key)->impact['assessable'])->all())->toBe([false, false, false])
        ->and($request('p9')->teacher_id)->toBeNull();

    // El motor: sin otro acomodo (el docente solo está disponible a sus horas de clase), en la cola, empezado y fallido.
    $locked = TeacherAvailability::query()->where('teacher_id', $request('n')->group->teacher_id)->where('kind', AvailabilityKind::Unavailable->value)->count();

    expect($alternatives('n'))->toBe(ChangeAlternativesStatus::None)
        ->and(collect($request('n')->impact['hard'])->pluck('rule')->all())->toContain('HC-09')
        ->and($locked)->toBeGreaterThan(0)
        ->and(ChangeAlternativesStatus::isQueued($request('q')->alternatives))->toBeTrue()
        ->and($alternatives('s'))->toBe(ChangeAlternativesStatus::Computing)
        ->and(ChangeAlternativesStatus::isQueued($request('s')->alternatives))->toBeFalse()
        ->and($alternatives('f'))->toBe(ChangeAlternativesStatus::Failed)
        ->and($request('f')->alternatives['message'])->toBe(ComputeChangeAlternatives::FAILED_MESSAGE);

    // Pasado el plazo, la empezada y la de la cola se ven colgadas.
    $this->travel(ComputeChangeAlternatives::QUEUE_STALE_AFTER + 60)->seconds();
    expect(ChangeAlternativesStatus::messageOf($request('s')->alternatives))->toBe(ChangeAlternativesStatus::STALE_MESSAGE)
        ->and(ChangeAlternativesStatus::messageOf($request('q')->alternatives))->toBe(ChangeAlternativesStatus::STALE_MESSAGE);
    $this->travelBack();

    // `x` chocaba con el grupo de `y`; `z` y `p4` siguen vigentes también con la revisión pesada de la pantalla.
    $choose = app(ChooseChangeAlternative::class);

    expect(collect($request('x')->impact['affected_groups'])->pluck('id')->all())->toContain($request('y')->group_id)
        ->and($choose->isCurrent($request('z'), ChangeAlternatives::chosen($request('z')->alternatives)))->toBeTrue()
        ->and(collect($request('p4')->alternatives['items'])->every(fn (array $item): bool => $choose->isCurrent($request('p4'), $item)))->toBeTrue();

    // Aplicada con alternativa y acomodada a mano: el horario de sus grupos es el de la elegida; la aplicada avisa.
    $sessions = fn (string $groupId): array => GroupSession::query()->where('group_id', $groupId)->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => $session->day.' '.substr((string) $session->starts_at, 0, 5))->all();

    foreach (['y', 'm'] as $key) {
        foreach (ChangeAlternatives::chosen($request($key)->alternatives)['groups'] as $entry) {
            expect($sessions($entry['group_id']))->toBe(collect($entry['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
                ->map(fn (array $session): string => $session['day'].' '.substr($session['starts_at'], 0, 5))->values()->all(), "Sesiones de {$key}");
        }
    }

    expect(DB::table('notifications')->where('type', ChangeRequestMovedTeacherGroups::class)->count())->toBeGreaterThan(0)
        ->and($sessions($request('ap1')->group_id))->toBe(collect($request('ap1')->proposed['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
        ->map(fn (array $session): string => $session['day'].' '.$session['starts_at'])->values()->all());

    expectChangeRequestsSettled($request, $w->term);

    $counts = fn (): array => [
        ChangeRequest::query()->count(), ChangeRequest::query()->where('status', ChangeRequestStatus::Approved)->count(),
        DB::table('load_transitions')->count(), GroupSession::query()->count(), DB::table('notifications')->count(),
        DB::table('activity_log')->count(), TeacherAvailability::query()->count(),
    ];
    $before = $counts();

    // Una segunda corrida, con los eventos de modelo encendidos, no escribe nada.
    Event::fakeFor(fn () => ($w->step->run)($w->demo), $w->plan->quietEvents());

    expect($counts())->toBe($before)
        ->and(($w->check->passes)($w->demo))->toBeTrue();

    // En el recorrido, la coordinación aplica la alternativa de `z` sin «Esto cambió…».
    $z = $request('z');
    $coordinator = app(RecipientsQuery::class)->holders(StaffingPermission::ChangeRequestApplySchedule, Target::of(campus: $z->group->campus_id, career: $z->group->owner_career_id, modality: $z->modality_id))[0]['user'];
    $applied = app(ApplyChosenAlternative::class)->handle($z, $coordinator);

    expect($applied->request->status)->toBe(ChangeRequestStatus::Applied);
});

it('retoma tras caerse a la mitad y deja lo mismo: claves, choques y alternativas al día', function () use ($expectedCases) {
    $w = changeRequestsWorld();
    $request = $w->request;
    $status = fn (string $key): ?ChangeRequestStatus => $request($key)?->status;
    $crash = fn (string $class) => app()->bind($class, fn () => throw new RuntimeException("caída en {$class}"));
    $recover = fn (string $class) => app()->offsetUnset($class);

    // Se cae al aplicar la alternativa: ya rechazó, aplicó lo pedido, acomodó a mano `m` y aprobó `y` con una alternativa.
    $crash(ApplyChosenAlternative::class);
    expect(fn () => ($w->run)())->toThrow(RuntimeException::class, 'caída');
    expect($w->sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(collect(['r1', 'r2', 'r3', 'r4'])->map($status)->all())->toBe(array_fill(0, 4, ChangeRequestStatus::Rejected))
        ->and(collect(['ap1', 'ap2', 'ap3', 'ap4', 'm'])->map($status)->all())->toBe(array_fill(0, 5, ChangeRequestStatus::Applied))
        ->and($status('y'))->toBe(ChangeRequestStatus::Approved)
        ->and($status('n'))->toBeNull();
    $recover(ApplyChosenAlternative::class);

    // Aplica `y` (la carga regresa a asignación), pide lo de después y se cae al pedir las primeras alternativas.
    $crash(ProposeChangeAlternatives::class);
    expect(fn () => ($w->run)())->toThrow(RuntimeException::class, 'caída');
    expect($status('y'))->toBe(ChangeRequestStatus::Applied)
        ->and($w->sc->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($status('p1'))->toBe(ChangeRequestStatus::Pending)
        ->and($request('n')->alternatives)->toBeNull()
        ->and($request('p4')->alternatives)->toBeNull()
        ->and($status('a1'))->toBe(ChangeRequestStatus::Pending);
    $recover(ProposeChangeAlternatives::class);

    ($w->run)();

    expect(($w->check->passes)($w->demo))->toBeTrue()
        ->and(($w->cases)())->toBe($expectedCases());

    expectChangeRequestsSettled($request, $w->term);
});

it('en la demo mezclada agrega una pendiente con choque duro y sus alternativas listas, una sola vez', function () {
    app(DemoRunner::class)->run(DemoCheckpoint::Rooms, DemoContext::for(DemoVolume::Demo));
    $this->actingAs(User::query()->where('username', 'admin')->sole());

    $seed = fn () => app(ChangeRequestsDemoSeeder::class)->setContainer(app())->__invoke(['mode' => 'showcase']);
    $seed();
    $seed();

    $requests = ChangeRequest::query()->where('reason', ChangeRequestsDemoSeeder::SHOWCASE_REASON)->get();

    expect($requests)->toHaveCount(1)
        ->and($requests->sole()->status)->toBe(ChangeRequestStatus::Pending)
        ->and($requests->sole()->impact['hard'])->not->toBeEmpty()
        ->and(ChangeAlternativesStatus::of($requests->sole()->alternatives))->toBe(ChangeAlternativesStatus::Ready);
});

it('DemoSeeder y MassiveSeeder siembran la pendiente con alternativas solo con demo.showcase', function () {
    $calls = new ArrayObject;
    app()->instance(ChangeRequestsDemoSeeder::class, new class($calls) extends Seeder
    {
        public function __construct(private ArrayObject $calls) {}

        public function run(string $term = '20271', string $modality = 'PRES', string $mode = 'cases'): void
        {
            $this->calls[] = $mode;
        }
    });

    app(DemoSeeder::class)->setContainer(app())->changeAlternatives();
    app(MassiveSeeder::class)->setContainer(app())->changeAlternatives();
    expect($calls->getArrayCopy())->toBe([]);

    config(['demo.showcase' => true]);
    app(DemoSeeder::class)->setContainer(app())->changeAlternatives();
    app(MassiveSeeder::class)->setContainer(app())->changeAlternatives();

    expect($calls->getArrayCopy())->toBe(['showcase', 'showcase']);
});
