<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Actions\RequestApplySolverRun;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Engines\CpSat\CpSatClient;
use App\Modules\Solver\Engines\CpSat\CpSatEngine;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\RunOrderQuery;
use App\Modules\Solver\Support\RunPayloads;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
| Descomposición con CP-SAT (tarea 7.8; docs/arquitectura/solver.md, «Descomposición del problema»): las cargas se corren
| una por una en el orden que sugiere RunOrderQuery (SV-21), y una parte aceptada entra fija a la corrida siguiente
| (BuildSolverInput: los grupos de otra carga que comparten docente, cohorte o salón van con `movable = false` y sus
| sesiones en `fixed_sessions`). Se prueba de punta a punta: lanzar con el motor `cpsat`, aceptar (RequestApplySolverRun
| y ApplySolverRunJob, con la cola síncrona), lanzar la siguiente y revisar el horario con el validador de la fase 3.
|
| El servicio se simula con respuestas grabadas del servicio real (tests/Fixtures/solver/cpsat/sequence/): el `POST
| /solve` y el `GET /runs/{id}` que terminó en `done` de cada corrida. La base de la prueba genera ids nuevos cada vez,
| así que la grabación guarda los grupos, salones y docentes por su clave de negocio (`<materia>/<grupo>`,
| `<campus>/<salón>`, RFC) y el `run_id` de la salida como `{run_id}`; al reproducir se cambian por los de esta base.
| Las claves se leen al recibir la respuesta, igual al grabar que al reproducir (la clave de un grupo sigue a su horario y
| cambia al aceptar, AssignGroupCode).
|
| La secuencia en la demo encontró que aceptar podía dejar un choque de HC-03: CP-SAT no acomodó un grupo de CEA (se
| queda con su horario de hoy, SV-06) y escribió encima a otros de su cohorte. ApplyPlan ahora también retiene a esos.
|
| Para volver a grabar (si cambia cómo se arma la entrada o el modelo del servicio), con el servicio real arriba y
| SOLVER_ENGINE=cpsat:
|   docker compose exec -e CPSAT_RECORD_URL=http://solver:8000 -e CPSAT_RECORD_TOKEN=<token> app \
|     php artisan test tests/Feature/Solver/CpSatDecompositionTest.php
| Así la prueba manda las corridas al servicio de verdad, espera en tiempo real y reescribe las grabaciones.
*/

/**
 * La ruta de una grabación de tests/Fixtures/solver/cpsat/sequence/.
 */
function cpsatSequencePath(string $file): string
{
    return __DIR__.'/../../Fixtures/solver/cpsat/sequence/'.$file;
}

/**
 * Registra el motor `cpsat` (con la heurística en PHP para la pista, como en producción) y lo conecta con las
 * grabaciones de `$recordings` (por clave del departamento de la corrida): las reproduce o, con CPSAT_RECORD_URL, las
 * graba del servicio real.
 *
 * @param  array<string, string>  $recordings  clave del departamento => archivo de la grabación
 */
function cpsatSequenceService(array $recordings): void
{
    $recordUrl = (string) env('CPSAT_RECORD_URL', '');

    if ($recordUrl !== '') {
        cpsatSequenceRecorder($recordings);
        app()->instance(EngineRegistry::class, new EngineRegistry([
            new CpSatEngine(new CpSatClient($recordUrl, (string) env('CPSAT_RECORD_TOKEN', ''))),
            new PhpHeuristicEngine,
        ]));

        return;
    }

    Sleep::fake(syncWithCarbon: true);
    Http::preventStrayRequests();
    $current = null;

    Http::fake(function (Request $request) use ($recordings, &$current): PromiseInterface {
        if ($request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === '/health') {
            $health = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/cpsat/health.json'), true, flags: JSON_THROW_ON_ERROR);

            return Http::response($health['body'], $health['status']);
        }

        if ($request->method() === 'POST') {
            $department = Department::query()->findOrFail($request['scope']['id'])->code;
            expect($recordings)->toHaveKey($department);
            $current = ['input' => $request->data(), 'recording' => cpsatSequenceRecording($recordings[$department])];

            return Http::response($current['recording']['submitted']['body'], $current['recording']['submitted']['status']);
        }

        $done = $current['recording']['done'];
        $body = cpsatSequenceIds($done['body'], cpsatSequenceKeys(toKeys: false));
        $body['output']['run_id'] = $current['input']['run_id'];

        return Http::response($body, $done['status']);
    });

    app()->instance(EngineRegistry::class, new EngineRegistry([
        new CpSatEngine(new CpSatClient('http://solver.test', 'secreto-de-prueba')),
        new PhpHeuristicEngine,
    ]));
}

/**
 * Graba lo que responde el servicio real: el envío y la consulta que termina en `done`, con los ids cambiados por sus
 * claves.
 *
 * @param  array<string, string>  $recordings
 */
function cpsatSequenceRecorder(array $recordings): void
{
    $submitted = null;

    Event::listen(ResponseReceived::class, function (ResponseReceived $event) use ($recordings, &$submitted): void {
        $path = (string) parse_url($event->request->url(), PHP_URL_PATH);

        if ($event->request->method() === 'POST' && $path === '/solve') {
            $submitted = ['status' => $event->response->status(), 'body' => $event->response->json()];

            return;
        }

        $body = $event->response->json();

        if ($event->request->method() !== 'GET' || ($body['status'] ?? null) !== 'done') {
            return;
        }

        $department = Department::query()->findOrFail(
            SolverRun::query()->findOrFail($body['output']['run_id'])->department_id,
        )->code;
        $body = cpsatSequenceIds($body, cpsatSequenceKeys(toKeys: true));
        $body['output']['run_id'] = '{run_id}';

        file_put_contents(cpsatSequencePath($recordings[$department]), json_encode(
            ['submitted' => $submitted, 'done' => ['status' => $event->response->status(), 'body' => $body]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");
    });
}

/**
 * @return array{submitted: array{status: int, body: array<string, mixed>}, done: array{status: int, body: array<string, mixed>}}
 */
function cpsatSequenceRecording(string $file): array
{
    return json_decode(file_get_contents(cpsatSequencePath($file)), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Los ids de grupos, salones y docentes de esta base y sus claves de negocio: id => clave (`$toKeys`) o clave => id.
 *
 * @return array<string, string>
 */
function cpsatSequenceKeys(bool $toKeys): array
{
    $keys = [
        ...Group::query()->with('subject:id,code')->get(['id', 'subject_id', 'code'])
            ->mapWithKeys(fn (Group $group): array => [$group->id => "{$group->subject->code}/{$group->code}"])->all(),
        ...Room::query()->with('campus:id,code')->get(['id', 'campus_id', 'code'])
            ->mapWithKeys(fn (Room $room): array => [$room->id => "{$room->campus->code}/{$room->code}"])->all(),
        ...Teacher::query()->pluck('rfc', 'id')->all(),
    ];

    return $toKeys ? $keys : array_flip($keys);
}

/**
 * Cambia en una respuesta del servicio cada `group_id`, `room_id`, `teacher_id` y `group_ids` según `$map`. Un valor
 * sin traducción detiene la prueba: la grabación no es de esta base.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $map
 * @return array<string, mixed>
 */
function cpsatSequenceIds(array $data, array $map): array
{
    $translate = fn (string $value): string => $map[$value] ?? throw new RuntimeException("La grabación trae «{$value}», que no está en esta base.");

    foreach ($data as $key => $value) {
        $data[$key] = match (true) {
            in_array($key, ['group_id', 'room_id', 'teacher_id'], true) && is_string($value) => $translate($value),
            $key === 'group_ids' && is_array($value) => array_map($translate, $value),
            is_array($value) => cpsatSequenceIds($value, $map),
            default => $value,
        };
    }

    return $data;
}

/**
 * Los bloques de una hora que ocupan unas sesiones, como «día hh:mm» (con el salón si `$withRoom`), ordenados: dos
 * sesiones pegadas y una larga ocupan lo mismo.
 *
 * @param  iterable<object{day: int, startsAt?: string, endsAt?: string, starts_at?: string, ends_at?: string, roomId?: ?string, room_id?: ?string}>  $sessions
 * @return list<string>
 */
function cpsatSequenceBlocks(iterable $sessions, bool $withRoom = true): array
{
    $blocks = [];

    foreach ($sessions as $session) {
        $from = strtotime('1970-01-01 '.($session->startsAt ?? $session->starts_at).' UTC');
        $to = strtotime('1970-01-01 '.($session->endsAt ?? $session->ends_at).' UTC');
        $room = $session->roomId ?? $session->room_id ?? '-';

        for ($at = $from; $at < $to; $at += 3600) {
            $blocks[] = "{$session->day} ".gmdate('H:i', $at).($withRoom ? " {$room}" : '');
        }
    }

    sort($blocks);

    return $blocks;
}

/**
 * Las violaciones duras que el validador de la fase 3 encuentra hoy en la modalidad, como «regla: mensaje».
 *
 * @return list<string>
 */
function cpsatSequenceHardViolations(Term $term, string $modalityCode): array
{
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    return array_values(array_map(
        fn (Violation $violation): string => "{$violation->rule}: {$violation->message}",
        array_filter($validator->validateLoad($term, modalityOf($modalityCode))->violations(), fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard),
    ));
}

/**
 * Lanza la corrida `cpsat` de la carga del departamento, la acepta con la cola síncrona y devuelve la corrida aplicada.
 */
function cpsatSequenceRunAndApply(Term $term, Department $department, User $head, int $timeLimit): SolverRun
{
    $run = app(LaunchSolverRun::class)->handle($term, modalityOf('PRES'), RunScope::Department, $department->id, CpSatEngine::KEY, $timeLimit, $head)->refresh();

    expect($run->status)->toBe(SolverRunStatus::Done, (string) $run->error);

    app(RequestApplySolverRun::class)->handle($run, $head);

    return $run->refresh();
}

it('7.8: dos cargas en secuencia con CP-SAT: la segunda trae fijas las sesiones aceptadas de la primera y no las mueve ni choca con ellas', function () {
    $term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18']);
    $pres = modalityOf('PRES');
    // Una jornada corta (15 bloques de lunes a viernes) para que las dos cargas, de una misma cohorte, se estorben.
    $pres->update(['settings' => [...$pres->profile->toArray(), 'day_start' => '07:00', 'day_end' => '10:00', 'days' => [1, 2, 3, 4, 5]]]);
    $campus = Campus::factory()->create(['code' => 'C2']);
    $building = Building::factory()->create(['campus_id' => $campus->id, 'code' => 'A']);
    Room::factory()->create(['building_id' => $building->id, 'code' => 'A-01', 'capacity' => 40]);
    Room::factory()->create(['building_id' => $building->id, 'code' => 'A-02', 'capacity' => 40]);

    $basicas = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);
    $sistemas = Department::factory()->create(['code' => 'SC', 'name' => 'Sistemas y Computación']);
    $isc = Career::factory()->create(['code' => 'ISC', 'department_id' => $sistemas->id]);
    CareerCampus::factory()->create(['career_id' => $isc->id, 'campus_id' => $campus->id]);
    $plan = StudyPlan::factory()->create(['career_id' => $isc->id]);

    foreach ([$basicas, $sistemas] as $department) {
        AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['term_id' => $term->id, 'modality_id' => $pres->id, 'department_id' => $department->id]);
    }

    // Docentes de medio tiempo (20 h): les caben sus grupos (HC-08).
    $teacher = function (string $rfc): Teacher {
        $teacher = Teacher::factory()->create(['rfc' => $rfc]);
        TeacherPosition::factory()->for($teacher)->medioTiempo()->create();

        return $teacher;
    };
    $shared = $teacher('DOCE800101AAA');
    $group = fn (Department $department, string $subject, Teacher $teacher): Group => Group::factory()->create([
        'term_id' => $term->id, 'campus_id' => $campus->id, 'modality_id' => $pres->id,
        'subject_id' => Subject::factory()->create(['department_id' => $department->id, 'code' => $subject, 'theory_hours' => 3, 'practice_hours' => 0])->id,
        'owner_career_id' => $isc->id, 'study_plan_id' => $plan->id, 'semester' => 1, 'code' => '1A', 'capacity' => 30, 'teacher_id' => $teacher->id,
    ]);

    // Las cuatro son el único grupo de su materia en el semestre 1 de ISC: una sola cohorte (HC-03). El docente de
    // Cálculo también da Programación, y las dos cargas usan los mismos dos salones.
    $calculo = $group($basicas, 'ACF-0901', $shared);
    $algebra = $group($basicas, 'ACF-0903', $teacher('DOCE800101BBB'));
    $programacion = $group($sistemas, 'SCD-1008', $shared);
    $discretas = $group($sistemas, 'AEF-1041', $teacher('DOCE800101CCC'));

    $heads = [
        'CB' => User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $basicas->id])->create(),
        'SC' => User::factory()->withRole(Role::JefeDepartamento, ['campus_id' => $campus->id, 'department_id' => $sistemas->id])->create(),
    ];

    cpsatSequenceService(['CB' => 'cb.json', 'SC' => 'sc.json']);

    // El orden lo sugiere RunOrderQuery (SV-21): comparten la cohorte y empatan en grupos, así que va primero Ciencias Básicas.
    $order = collect(app(RunOrderQuery::class)->forTerm($term, $pres))->map(fn (array $row): Department => $row['department'])->all();
    expect(array_map(fn (Department $department): string => $department->code, $order))->toBe(['CB', 'SC']);

    $first = cpsatSequenceRunAndApply($term, $order[0], $heads['CB'], 10);
    $firstOutput = RunPayloads::output($first);

    expect($first->status)->toBe(SolverRunStatus::Applied)
        ->and($firstOutput->status)->toBe(RunStatus::Feasible)
        ->and($firstOutput->unscheduled)->toBe([])
        ->and($firstOutput->hardViolations)->toBe([]);

    $accepted = [];
    foreach ([$calculo, $algebra] as $fixed) {
        $accepted[$fixed->id] = cpsatSequenceBlocks($fixed->sessions()->get());

        expect($accepted[$fixed->id])->toHaveCount(3)
            ->toBe(cpsatSequenceBlocks(array_filter($firstOutput->sessions, fn ($session): bool => $session->groupId === $fixed->id)));
    }

    $second = cpsatSequenceRunAndApply($term, $order[1], $heads['SC'], 10);
    $input = collect(RunPayloads::input($second)->groups)->keyBy('id');
    $output = RunPayloads::output($second);

    // La entrada de la segunda trae la parte aceptada como fija, con sus sesiones de la base.
    expect($input[$programacion->id]->movable)->toBeTrue()
        ->and($input[$discretas->id]->movable)->toBeTrue();

    foreach ($accepted as $groupId => $blocks) {
        expect($input[$groupId]->movable)->toBeFalse()
            ->and(cpsatSequenceBlocks($input[$groupId]->fixedSessions))->toBe($blocks);
    }

    // Su salida no mueve lo fijo y no choca con ello: ni el docente compartido, ni la cohorte, ni los salones.
    $acceptedTimes = cpsatSequenceBlocks(GroupSession::query()->whereIn('group_id', array_keys($accepted))->get(), withRoom: false);
    $proposed = collect($output->sessions);

    expect($output->status)->toBe(RunStatus::Feasible)
        ->and($output->unscheduled)->toBe([])
        ->and($output->hardViolations)->toBe([])
        ->and($proposed->pluck('groupId')->unique()->sort()->values()->all())->toEqualCanonicalizing([$programacion->id, $discretas->id])
        ->and(array_intersect(cpsatSequenceBlocks($output->sessions, withRoom: false), $acceptedTimes))->toBe([]);

    // Aceptada la segunda, lo de la primera sigue igual y el validador de la fase 3 no encuentra choques duros.
    expect($second->status)->toBe(SolverRunStatus::Applied)
        ->and(cpsatSequenceBlocks($calculo->sessions()->get()))->toBe($accepted[$calculo->id])
        ->and(cpsatSequenceBlocks($algebra->sessions()->get()))->toBe($accepted[$algebra->id])
        ->and(cpsatSequenceBlocks($programacion->sessions()->get()))->toHaveCount(3)
        ->and(cpsatSequenceBlocks($discretas->sessions()->get()))->toHaveCount(3)
        ->and(cpsatSequenceHardViolations($term, 'PRES'))->toBe([]);
});

it('7.8: en la demo, las dos primeras cargas del orden sugerido, corridas con CP-SAT y aceptadas en secuencia, no dejan choques duros en el validador de la fase 3', function () {
    $demo = DemoContext::for(DemoVolume::Demo);
    $report = app(DemoRunner::class)->run(DemoCheckpoint::Staffing, $demo);
    $failed = array_values(array_filter($report->checks, fn (array $check): bool => ! $check['passed']));
    $term = $demo->term();
    $pres = modalityOf('PRES');

    expect($failed)->toBe([], 'La demo no llegó a la asignación con cero choques duros.')
        ->and(cpsatSequenceHardViolations($term, 'PRES'))->toBe([]);

    // La clave del grupo sigue a su hora de inicio (AssignCodeOnScheduleChange); sin ese aviso, las claves de la
    // grabación no cambian entre corridas, como en HeuristicRunApplyDemoTest.
    Event::fake([GroupScheduleChanged::class]);

    $order = array_slice(array_map(fn (array $row): Department => $row['department'], app(RunOrderQuery::class)->forTerm($term, $pres)), 0, 2);
    cpsatSequenceService(collect($order)->mapWithKeys(fn (Department $department): array => [$department->code => 'demo-'.strtolower($department->code).'.json'])->all());

    $applied = [];

    foreach ($order as $department) {
        $head = app(RecipientsQuery::class)->holders(SolverPermission::DepartmentRunLaunch, Target::of(modality: $pres->id, unit: $department->id))[0]['user'];
        $run = cpsatSequenceRunAndApply($term, $department, $head, 60);
        $input = RunPayloads::input($run);
        $output = RunPayloads::output($run);
        $movable = collect($input->groups)->where('movable', true)->pluck('id')->all();

        // Lo aceptado de la carga anterior entra fijo con sus sesiones de la base, y la salida no lo toca.
        foreach ($input->groups as $group) {
            if (isset($applied[$group->id])) {
                expect($group->movable)->toBeFalse()
                    ->and(cpsatSequenceBlocks($group->fixedSessions))->toBe($applied[$group->id]);
            }
        }

        expect($run->status)->toBe(SolverRunStatus::Applied, (string) ($run->metrics['apply_error'] ?? ''))
            ->and($output->hardViolations)->toBe([])
            ->and(array_diff(collect($output->sessions)->pluck('groupId')->unique()->all(), $movable))->toBe([]);

        foreach (Group::query()->with('sessions')->whereIn('id', $movable)->get() as $group) {
            $applied[$group->id] = cpsatSequenceBlocks($group->sessions);
        }
    }

    $hardConflicts = ScheduleConflict::query()->where('term_id', $term->id)->where('modality_id', $pres->id)
        ->where('severity', ConflictSeverity::Hard)->whereNull('resolved_at')->pluck('message')->all();

    expect(cpsatSequenceHardViolations($term, 'PRES'))->toBe([])
        ->and($hardConflicts)->toBe([]);
});
