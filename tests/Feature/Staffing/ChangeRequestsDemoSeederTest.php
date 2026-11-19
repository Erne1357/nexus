<?php

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
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Database\Seeders\ChangeRequestsDemoSeeder;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
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
| la carga que ReanalysisDemoSeeder reserva (CASE_CHANGE_REQUESTS) y deja ahí solicitudes en cada estado, por las Actions
| reales. Con el volumen demo no hay carga reservada (son 4 cargas), así que la prueba la fuerza como en el masivo:
| publica las cargas sin grupos de EE y MM y SC queda sexta, la reservada. Como con el masivo hay docentes con clases en
| los dos campus (lo que da el aviso de traslado, SC-09), un docente de SC da además una hora en Campus I en un grupo de
| otro departamento. Corre la demo real hasta `reanalisis` y solo el paso nuevo, cayéndose a propósito a la mitad.
*/

/**
 * Un docente de SC con horas libres da una hora en Campus I, en un grupo de Ciencias Básicas, justo antes de una hora en
 * que uno de sus grupos de SC podría ir sin chocar: su salón está libre, nadie de su semestre tiene clase y el docente
 * está libre y disponible. Así mover ahí esa sesión deja solo el aviso de traslado (SC-09).
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
    $away = Group::factory()->create([
        'term_id' => $term->id, 'campus_id' => Campus::query()->where('code', 'C1')->value('id'), 'modality_id' => modalityOf('PRES')->id,
        'department_id' => $cb->id, 'subject_id' => Subject::factory()->create(['department_id' => $cb->id, 'theory_hours' => 1, 'practice_hours' => 0])->id,
        'teacher_id' => $teacherId, 'code' => 'T1',
    ]);
    GroupSession::factory()->for($away)->on($slot[0], sprintf('%02d:00', $slot[1] - 1), sprintf('%02d:00', $slot[1]))->create();
}

it('deja en la carga reservada solicitudes de cambio en cada estado, retoma tras una caída y repetirlo no duplica nada', function () {
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
    $sc = AcademicLoad::query()->ofDepartment($term->id, $modality->id, $department('SC'))->sole();

    expect(ReanalysisDemoSeeder::plan($term->id, $modality->id)[$sc->id])->toBe(ReanalysisDemoSeeder::CASE_CHANGE_REQUESTS);

    crossCampusTeacherForChangeRequests($term);

    $this->actingAs(User::query()->where('username', 'admin')->sole());
    $demo = DemoContext::for(DemoVolume::Demo);
    $plan = app(DemoPlan::class);
    $step = collect($plan->stepsFor(DemoCheckpoint::ReanalysisCases))->last();
    // Como DemoRunner: sin eventos de modelo y con los eventos silenciados de la demo.
    $run = fn () => Model::withoutEvents(fn () => Event::fakeFor(fn () => ($step->run)($demo), $plan->quietEvents()));
    $crash = fn (string $class) => app()->bind($class, fn () => throw new RuntimeException("caída en {$class}"));
    $recover = fn (string $class) => app()->offsetUnset($class);
    $request = fn (string $key): ?ChangeRequest => ChangeRequest::query()->where('department_id', $sc->department_id)->where('reason', ChangeRequestsDemoSeeder::SCENARIOS[$key]['reason'])->first();
    $status = fn (string $key): ?ChangeRequestStatus => $request($key)?->status;

    $check = collect($plan->checksAt(DemoCheckpoint::ReanalysisCases))->first(fn (DemoCheck $check): bool => str_contains($check->label, 'solicitudes de cambio'));

    expect($step)->toBeInstanceOf(DemoStep::class)
        ->and($step->label)->toContain('Solicitudes de cambio')
        ->and(($check->passes)($demo))->toBe('Falta: SC en PRES: pendiente, aprobada, rechazada, aplicada, pendiente con alternativas listas, aprobada con alternativa, aplicada con alternativa, la carga en ajustes.');

    // Se cae al aplicar la alternativa: ya rechazó, aplicó lo pedido y aprobó `y` con una alternativa.
    $crash(ApplyChosenAlternative::class);
    expect(fn () => $run())->toThrow(RuntimeException::class, 'caída');
    expect($sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(collect(['r1', 'r2', 'r3', 'r4'])->map($status)->all())->toBe(array_fill(0, 4, ChangeRequestStatus::Rejected))
        ->and(collect(['ap1', 'ap2', 'ap3', 'ap4'])->map($status)->all())->toBe(array_fill(0, 4, ChangeRequestStatus::Applied))
        ->and($status('y'))->toBe(ChangeRequestStatus::Approved)
        ->and($status('p1'))->toBeNull();
    $recover(ApplyChosenAlternative::class);

    // Aplica `y` (la carga regresa a asignación), pide lo de después y se cae al pedir las primeras alternativas.
    $crash(ProposeChangeAlternatives::class);
    expect(fn () => $run())->toThrow(RuntimeException::class, 'caída');
    expect($status('y'))->toBe(ChangeRequestStatus::Applied)
        ->and($sc->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($status('p1'))->toBe(ChangeRequestStatus::Pending)
        ->and($request('p4')->alternatives)->toBeNull()
        ->and($status('a1'))->toBe(ChangeRequestStatus::Pending);
    $recover(ProposeChangeAlternatives::class);

    $run();

    expect(($check->passes)($demo))->toBeTrue();

    $choose = app(ChooseChangeAlternative::class);
    $items = fn (string $key): array => $request($key)->alternatives['items'];

    // La carga: reabierta a ajustes por Subdirección, de vuelta a asignación al aplicar `y` y otra vez en ajustes al aprobar.
    expect($sc->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($sc->transitions()->where('from_status', LoadStatus::Published->value)->where('to_status', LoadStatus::Adjusting->value)->value('comment'))->toBe(ChangeRequestsDemoSeeder::REOPEN_REASON);

    // Cada escenario en su estado, con el comentario del jefe en las decididas.
    foreach (ChangeRequestsDemoSeeder::SCENARIOS as $key => $scenario) {
        $expected = match ($scenario['target']) {
            ChangeRequestsDemoSeeder::REJECTED => ChangeRequestStatus::Rejected,
            ChangeRequestsDemoSeeder::APPLIED, ChangeRequestsDemoSeeder::APPLIED_ALTERNATIVE => ChangeRequestStatus::Applied,
            ChangeRequestsDemoSeeder::APPROVED, ChangeRequestsDemoSeeder::APPROVED_ALTERNATIVE => ChangeRequestStatus::Approved,
            default => ChangeRequestStatus::Pending,
        };

        expect($status($key))->toBe($expected, "Escenario {$key}");

        if ($scenario['comment'] !== null && $expected !== ChangeRequestStatus::Pending) {
            expect($request($key)->decision_comment)->toBe($scenario['comment'], "Comentario de {$key}");
        }
    }

    // Pendientes: sin choques, con solo el aviso de traslado (SC-09), con choque duro sin pedir alternativas y solo texto.
    foreach (['p1', 'p2'] as $key) {
        expect($request($key)->impact)->toMatchArray(['assessable' => true, 'hard' => [], 'soft' => []]);
    }

    expect($request('p3')->impact['hard'])->toBe([])
        ->and(array_column($request('p3')->impact['soft'], 'rule'))->toBe(['SC-09'])
        ->and($request('p6')->impact['hard'])->not->toBeEmpty()
        ->and($request('p6')->alternatives)->toBeNull()
        ->and(collect(['p7', 'p8', 'p9'])->map(fn (string $key): bool => $request($key)->impact['assessable'])->all())->toBe([false, false, false])
        ->and($request('p9')->teacher_id)->toBeNull();

    // Con choque duro y alternativas listas, vigentes; la de `x` choca con el grupo de `y` y, al moverse, ya no vale.
    expect(ChangeAlternativesStatus::of($request('p4')->alternatives))->toBe(ChangeAlternativesStatus::Ready)
        ->and(count($items('p4')))->toBeGreaterThanOrEqual(2)
        ->and(collect($items('p4'))->every(fn (array $item): bool => $choose->sessionsUnchanged($item)))->toBeTrue()
        ->and(ChangeAlternativesStatus::of($request('p5')->alternatives))->toBeIn([ChangeAlternativesStatus::Ready, ChangeAlternativesStatus::None])
        ->and(collect($request('x')->impact['affected_groups'])->pluck('id')->all())->toContain($request('y')->group_id)
        ->and(ChangeAlternativesStatus::of($request('x')->alternatives))->toBe(ChangeAlternativesStatus::Ready)
        ->and(collect($items('x'))->contains(fn (array $item): bool => ! $choose->sessionsUnchanged($item)))->toBeTrue();

    // Aplicada con alternativa: el horario de sus grupos es el de la elegida y sus docentes tienen el aviso.
    $applied = ChangeAlternatives::chosen($request('y')->alternatives);
    $sessions = fn (string $groupId): array => GroupSession::query()->where('group_id', $groupId)->orderBy('day')->orderBy('starts_at')->get()
        ->map(fn (GroupSession $session): string => $session->day.' '.substr((string) $session->starts_at, 0, 5))->all();

    expect($applied)->not->toBeNull();

    foreach ($applied['groups'] as $entry) {
        expect($sessions($entry['group_id']))->toBe(collect($entry['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
            ->map(fn (array $session): string => $session['day'].' '.substr($session['starts_at'], 0, 5))->values()->all());
    }

    expect(DB::table('notifications')->where('type', ChangeRequestMovedTeacherGroups::class)->count())->toBeGreaterThan(0)
        // Aplicada como se pidió con horario: el coordinador escribió lo que pedía.
        ->and($sessions($request('ap1')->group_id))->toBe(collect($request('ap1')->proposed['sessions'])->sortBy([['day', 'asc'], ['starts_at', 'asc']])
        ->map(fn (array $session): string => $session['day'].' '.$session['starts_at'])->values()->all())
        // Aprobada con alternativa sin aplicar (la coordinación ve «Aplicar la alternativa») y aprobadas como se pidieron.
        ->and(ChangeAlternatives::chosen($request('z')->alternatives))->not->toBeNull()
        ->and($choose->sessionsUnchanged(ChangeAlternatives::chosen($request('z')->alternatives)))->toBeTrue()
        ->and(ChangeAlternatives::chosen($request('a1')->alternatives))->toBeNull();

    $counts = fn (): array => [
        ChangeRequest::query()->count(), ChangeRequest::query()->where('status', ChangeRequestStatus::Approved)->count(),
        DB::table('load_transitions')->count(), GroupSession::query()->count(), DB::table('notifications')->count(), DB::table('activity_log')->count(),
    ];
    $before = $counts();

    // Una segunda corrida, con los eventos de modelo encendidos, no escribe nada.
    Event::fakeFor(fn () => ($step->run)($demo), $plan->quietEvents());

    expect($counts())->toBe($before);
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
