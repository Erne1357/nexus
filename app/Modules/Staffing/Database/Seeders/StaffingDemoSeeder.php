<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\StartStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Faculty\Actions\AddExternalLoad;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Actions\RankGroups;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Models\TeacherTermLoad;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Process\Pool;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Asignación docente de prueba (tareas 3.8 a 3.12), para revisar con volumen todas las pantallas de Staffing. Corre
 * después de SchedulingDemoSeeder, que deja limpios los departamentos con más grupos. Por cada campus de `$campuses`,
 * en el periodo `$term`:
 *
 * 1. Pasa a asignación (StartStaffing, como el jefe del departamento) cada carga en propuesta; las que Scheduling veta
 *    (choques duros, grupos sin horario) se quedan en propuesta y se imprime el primer motivo.
 * 2. Calcula el ranking (RankGroups) de los departamentos que van a recibir docentes (paso 3); el de los demás con
 *    grupos activos se calcula al final.
 * 3. Reparte papeles entre las cargas en asignación, de más a menos grupos: la primera termina en ajustes, la segunda
 *    con sus solicitudes aplicadas (de vuelta en asignación), las de en medio en asignación con solicitudes pendientes
 *    y, si hay tres o más, la última sin ningún docente. En las demás asigna el 75 % de los grupos (65 % con el perfil
 *    massive): casi todos al primer lugar y uno de cada siete, más o menos, a otro candidato con un motivo (HC-16), con
 *    hasta dos docentes de honorarios; luego reemplaza y quita algunos docentes con su motivo, para que el historial
 *    tenga asignaciones vigentes, reemplazadas y quitadas. Siempre quedan grupos sin docente.
 * 4. Pide cambios de horario y de salón como los docentes de los grupos asignados (RequestChange) y los decide como el
 *    jefe: en la carga que termina en ajustes, pendientes, aprobadas sin aplicar y rechazadas con comentario; en la
 *    segunda, además, aprueba y aplica (MarkChangeRequestApplied, como el coordinador o el jefe) hasta que la carga
 *    regresa a asignación.
 * 5. Agrega horas fuera del ITCJ (AddExternalLoad) a unos docentes ya cargados para que excedan su tope (HC-08) o
 *    queden justos. Antes de asignar crea `staffing.honorarios_max_hours` = 20 si no existe, para que los de
 *    honorarios reciban grupos.
 * 6. Recalcula el ranking (las horas restantes ya cuentan lo asignado), `teacher_term_loads` (RefreshTeacherTermLoads)
 *    y los choques de cada campus (SyncScheduleConflicts), e imprime el resumen con los usuarios para revisar.
 *
 * Mientras siembra, los eventos de Scheduling y de Faculty que encolarían rankings, choques y cargas por docente en
 * cada escritura se descartan, y la cola va en `sync`; los recálculos corren una vez, en los pasos de arriba.
 *
 * Cada asignación se elige contra una foto del campus que se va actualizando (como SchedulingDemoSeeder): el candidato
 * pasa HC-01, HC-08 y HC-09 con el validador antes de escribirse. Las primeras de cada departamento (`actions`) pasan por
 * AssignTeacher, con su bitácora; las demás se escriben en bloque (el docente del grupo y su fila del historial, con el
 * lugar que tendría en un ranking recién calculado), porque AssignTeacher relee la foto completa del campus dos veces
 * por grupo y con el masivo tardaría más de diez minutos. Por lo mismo, con el perfil massive los rankings se calculan
 * en paralelo con `staffing:rank`, un proceso por departamento y campus.
 *
 * Determinista con `$seed` sobre los mismos datos. Si el periodo ya tiene asignaciones en esos campus no hace nada,
 * así que volver a correrlo no duplica. Se niega a correr en producción.
 */
final class StaffingDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** El departamento termina en ajustes, con solicitudes aprobadas sin aplicar. */
    private const string ADJUSTING = 'adjusting';

    /** El departamento aplica todas sus solicitudes aprobadas y regresa a asignación. */
    private const string APPLIED = 'applied';

    /** El departamento sigue en asignación con solicitudes pendientes. */
    private const string STAFFING = 'staffing';

    /** El departamento queda en asignación sin ningún docente. */
    private const string UNTOUCHED = 'untouched';

    /**
     * Por perfil: parte de los grupos que se asignan, asignaciones por AssignTeacher por departamento, reemplazos y
     * docentes quitados por departamento, docentes que se pasan de su tope o quedan justos y si los rankings van en
     * paralelo.
     */
    private const array PROFILES = [
        'demo' => ['assigned' => 0.75, 'actions' => 6, 'replaced' => 1, 'removed' => 1, 'exceeding' => 3, 'full' => 2, 'parallel' => false],
        'massive' => ['assigned' => 0.65, 'actions' => 10, 'replaced' => 2, 'removed' => 1, 'exceeding' => 4, 'full' => 3, 'parallel' => true],
    ];

    /** Por perfil y papel del departamento, cuántas solicitudes terminan en cada estado. */
    private const array REQUESTS = [
        'demo' => [
            self::ADJUSTING => ['approved' => 2, 'rejected' => 2, 'pending' => 3],
            self::APPLIED => ['applied' => 3, 'rejected' => 1, 'pending' => 2],
            self::STAFFING => ['rejected' => 1, 'pending' => 3],
        ],
        'massive' => [
            self::ADJUSTING => ['approved' => 3, 'rejected' => 4, 'pending' => 5],
            self::APPLIED => ['applied' => 5, 'rejected' => 2, 'pending' => 3],
            self::STAFFING => ['rejected' => 2, 'pending' => 6],
        ],
    ];

    /** Porcentaje de las asignaciones que van a un candidato fuera del primer lugar (HC-16). */
    private const int OUTSIDE_FIRST_PERCENT = 15;

    /** Docentes de honorarios que reciben grupo por departamento, fuera del primer lugar. */
    private const int HONORARIOS_PER_DEPARTMENT = 2;

    /** Una solicitud por grupo; un docente pide en hasta tantos grupos de un departamento. */
    private const int REQUESTS_PER_TEACHER = 2;

    private const int HONORARIOS_MAX_HOURS = 20;

    /** Los eventos que, mientras siembra, encolarían un recálculo por cada escritura. */
    private const array FAKED_EVENTS = [
        GroupsCreated::class, GroupScheduleChanged::class, GroupTeacherChanged::class, GroupCancelled::class,
        PositionChanged::class, ExternalLoadChanged::class, AvailabilityChanged::class, QualificationChanged::class,
        AdscriptionChanged::class, TeacherUpdated::class, TeacherAccessChanged::class,
    ];

    private const array OUTSIDE_REASONS = [
        'Imparte la misma materia en la otra carrera a la misma hora.',
        'Acuerdo de academia: rotación de la materia.',
        'El primer lugar pidió no tomar grupos de 7:00.',
        'Continuidad con el grupo del semestre anterior.',
        'Perfil preferente en la especialidad.',
    ];

    private const string HONORARIOS_REASON = 'Perfil preferente en la especialidad: es el único con experiencia en la industria en esta materia.';

    private const array REPLACEMENT_REASONS = [
        'El docente asignado pidió el cambio: se le empalma con su plaza en la universidad.',
        'Licencia médica del docente asignado; se reasigna para no dejar al grupo sin clase.',
        'Acuerdo de academia: el grupo pasa a quien lleva la otra sección de la materia.',
    ];

    private const array REMOVAL_REASONS = [
        'El docente renunció a sus horas de asignatura.',
        'Se libera el grupo: se fusionará con el otro grupo de la materia.',
        'El docente pidió licencia sin goce de sueldo por el semestre.',
    ];

    /** Lo que se pide y su motivo, por tipo. */
    private const array CHANGES = [
        'schedule' => [
            ['Mover las sesiones del lunes y el miércoles a las 9:00, en el mismo salón.', 'Tengo clase en el posgrado de 7:00 a 9:00 esos días.'],
            ['Juntar las sesiones en martes y jueves, de 11:00 a 13:00.', 'El viernes tengo comisión en la academia toda la mañana.'],
            ['Pasar el grupo al turno vespertino, de 15:00 a 17:00.', 'La mayoría de los alumnos del semestre trabaja en la maquila por la mañana.'],
            ['Cambiar la sesión del viernes al martes a la misma hora.', 'Los viernes doy asesorías de residencia profesional.'],
            ['Recorrer una hora todas las sesiones: de 8:00 a 10:00.', 'Vengo del otro campus y a las 7:00 no alcanzo a llegar.'],
            ['Separar la práctica en dos sesiones de una hora.', 'El laboratorio solo está libre una hora seguida esos días.'],
        ],
        'room' => [
            ['Un laboratorio de cómputo para las sesiones de práctica.', 'La práctica necesita el software de simulación instalado.'],
            ['Un aula para 40 alumnos en planta baja.', 'Hay un alumno en silla de ruedas inscrito en el grupo.'],
            ['Un aula con proyector y pizarrón blanco.', 'El salón asignado no tiene proyector y la materia es con presentaciones.'],
            ['Cambiar a un salón del edificio del departamento, cerca del laboratorio.', 'Entre esta clase y la siguiente solo hay diez minutos.'],
            ['Un aula más grande: el grupo tiene 38 inscritos.', 'El salón es para 30 y los alumnos están de pie al fondo.'],
        ],
    ];

    private const array REJECT_COMMENTS = [
        'No hay otro horario libre en el semestre sin empalmar a los alumnos; se queda como está.',
        'El laboratorio está ocupado a esa hora por otra carrera; propón otro día.',
        'El cambio saca al grupo de la franja de su turno; lo vemos en la reunión de academia.',
        'Ese salón ya está asignado a otro grupo a esa hora.',
    ];

    private const array APPROVE_COMMENTS = [
        'De acuerdo; el coordinador lo acomoda en el editor de horarios.',
        'Aprobada: hay salón libre a esa hora.',
        null,
    ];

    private const array EXTERNAL_SOURCES = [
        'Universidad Tecnológica de Ciudad Juárez',
        'Bachillerato tecnológico (CBTis)',
        'Consultoría a empresa vinculada',
        'Diplomado de educación continua',
    ];

    private Randomizer $random;

    /** @var array{assigned: float, actions: int, replaced: int, removed: int, exceeding: int, full: int, parallel: bool} */
    private array $profile;

    /** @var array<string, array<string, int>> */
    private array $requestPlan;

    private User $admin;

    private ScheduleValidator $validator;

    private ScheduleState $state;

    /** @var array<string, User> «permiso|campus|unidad|carrera» => primer titular */
    private array $holders = [];

    /** @var array<string, array<string, string>> campus => departamento => papel */
    private array $roles = [];

    /** @var array<string, array<string, string>> campus => departamento vetado => primer motivo */
    private array $vetoed = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> lista de textos => siguiente a usar */
    private array $cursors = [];

    /** @var array<string, float> paso => segundos */
    private array $timings = [];

    /**
     * @param  list<string>  $campuses  claves de campus
     *
     * @throws RuntimeException en producción, si el periodo no existe o si falla un ranking en paralelo
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027): void
    {
        $this->refuseProduction();
        $started = hrtime(true);
        $this->random = new Randomizer(new Mt19937($seed));
        $this->profile = self::PROFILES[$profile] ?? self::PROFILES['demo'];
        $this->requestPlan = self::REQUESTS[$profile] ?? self::REQUESTS['demo'];

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $this->admin = User::query()->where('username', 'admin')->first() ?? User::query()->first()
            ?? throw new RuntimeException('Hace falta un usuario para la bitácora: corre antes AdminUserSeeder.');
        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();

        if (GroupTeacherAssignment::query()->where('term_id', $termModel->id)->whereIn('campus_id', $found->modelKeys())->exists()) {
            $this->command?->info("Asignación docente: {$term} ya tiene asignaciones en ".$found->pluck('code')->implode(', ').'; no se toca.');

            return;
        }

        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            Setting::query()->firstOrCreate(
                ['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.honorarios_max_hours'],
                ['value' => self::HONORARIOS_MAX_HOURS],
            );

            Event::fakeFor(fn () => $this->seed($termModel, $found), self::FAKED_EVENTS);

            $this->timed('choques', function () use ($termModel, $found): void {
                foreach ($found as $campus) {
                    app(SyncScheduleConflicts::class)->handle($termModel, $campus);
                }
            });
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $this->summary($termModel, $found, $profile, (hrtime(true) - $started) / 1e9);
    }

    /**
     * @param  Collection<int, Campus>  $campuses
     */
    private function seed(Term $term, Collection $campuses): void
    {
        $this->timed('a asignación', function () use ($term, $campuses): void {
            foreach ($campuses as $campus) {
                $this->startStaffing($term, $campus);
            }
        });

        $this->timed('ranking', fn () => $this->rank($term, $campuses, assignedOnly: true));

        $this->timed('asignaciones', function () use ($term, $campuses): void {
            foreach ($campuses as $campus) {
                $this->assignCampus($term, $campus);
            }
        });

        $this->timed('solicitudes', function () use ($term, $campuses): void {
            foreach ($campuses as $campus) {
                foreach ($this->roles[$campus->code] ?? [] as $departmentId => $role) {
                    $this->requestChanges($term, $campus, Department::query()->findOrFail($departmentId), $role);
                }
            }
        });

        $this->timed('horas externas', fn () => $this->adjustExternalHours($term));
        $this->timed('re-ranking', fn () => $this->rank($term, $campuses));
        $this->timed('carga por docente', fn () => app(RefreshTeacherTermLoads::class)->handle($term));
    }

    /**
     * Pasa a asignación las cargas en propuesta del campus y reparte los papeles entre las que entraron (ver la docblock).
     */
    private function startStaffing(Term $term, Campus $campus): void
    {
        $loads = AcademicLoad::query()->with('department')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('status', LoadStatus::Proposed)
            ->get()->sortBy('department.code');
        $entered = [];

        foreach ($loads as $load) {
            try {
                app(StartStaffing::class)->handle($load, $this->head($campus, $load->department), 'Horario sin choques duros: pasa a asignación docente.');
                $entered[] = $load->department->code;
            } catch (ValidationException $exception) {
                $this->vetoed[$campus->code][$load->department->code] = (string) Arr::first(Arr::flatten($exception->errors()));
            }
        }

        $this->command?->info(sprintf('Asignación docente en %s: pasan a asignación %s; se quedan en propuesta %s.', $campus->code,
            $entered === [] ? 'ninguno' : implode(', ', $entered),
            isset($this->vetoed[$campus->code]) ? implode('; ', array_map(fn (string $code, string $reason): string => "{$code} («{$reason}»)", array_keys($this->vetoed[$campus->code]), $this->vetoed[$campus->code])) : 'ninguno'));

        $staffing = AcademicLoad::query()->with('department')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('status', LoadStatus::Staffing)->get();
        $groups = Group::query()->active()->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id')->all();
        $ordered = $staffing->sort(fn (AcademicLoad $a, AcademicLoad $b): int => [$groups[$b->department_id] ?? 0, $a->department->code] <=> [$groups[$a->department_id] ?? 0, $b->department->code])->values();
        $count = $ordered->count();
        $sequence = $count >= 3
            ? [self::ADJUSTING, self::APPLIED, ...array_fill(0, $count - 3, self::STAFFING), self::UNTOUCHED]
            : array_slice([self::ADJUSTING, self::APPLIED], 0, $count);

        foreach ($ordered as $index => $load) {
            $this->roles[$campus->code][$load->department_id] = $sequence[$index];
        }
    }

    /**
     * El ranking de cada departamento con grupos activos de los campus (`$assignedOnly`: solo de los que reciben
     * docentes), en serie o, con el perfil massive, en paralelo.
     *
     * @param  Collection<int, Campus>  $campuses
     */
    private function rank(Term $term, Collection $campuses, bool $assignedOnly = false): void
    {
        $byId = $campuses->keyBy('id');
        $scopes = Group::query()->active()->where('term_id', $term->id)->whereIn('campus_id', $campuses->modelKeys())
            ->distinct()->orderBy('campus_id')->orderBy('department_id')->get(['campus_id', 'department_id'])
            ->filter(fn (Group $scope): bool => ! $assignedOnly || ($this->roles[$byId[$scope->campus_id]->code][$scope->department_id] ?? self::UNTOUCHED) !== self::UNTOUCHED)
            ->values();
        $departments = Department::query()->withTrashed()->whereIn('id', $scopes->pluck('department_id')->unique()->all())->get()->keyBy('id');

        if ($this->profile['parallel']) {
            $this->rankInParallel($term, $scopes->map(fn (Group $scope): array => [$byId[$scope->campus_id]->code, $departments[$scope->department_id]->code])->all());

            return;
        }

        foreach ($scopes as $scope) {
            app(RankGroups::class)->handle($term, $byId[$scope->campus_id], $departments[$scope->department_id]);
        }
    }

    /**
     * Un `staffing:rank` por departamento y campus, todos a la vez, contra la misma base que este proceso.
     *
     * @param  list<array{0: string, 1: string}>  $scopes  campus y departamento
     */
    private function rankInParallel(Term $term, array $scopes): void
    {
        $connection = (string) config('database.default');
        $env = ['DB_CONNECTION' => $connection, 'DB_DATABASE' => (string) config("database.connections.{$connection}.database")];

        $results = Process::pool(function (Pool $pool) use ($term, $scopes, $env): void {
            foreach ($scopes as [$campus, $department]) {
                $pool->path(base_path())->env($env)->timeout(900)
                    ->command([PHP_BINARY, '-d', 'memory_limit=1G', 'artisan', 'staffing:rank', $term->code, "--campus={$campus}", "--department={$department}"]);
            }
        })->start()->wait();

        foreach ($results as $result) {
            if ($result->failed()) {
                throw new RuntimeException('Falló un ranking en paralelo: '.trim($result->errorOutput() ?: $result->output()));
            }
        }
    }

    /**
     * Asigna los grupos de los departamentos del campus que no quedan intactos, contra una foto del campus.
     */
    private function assignCampus(Term $term, Campus $campus): void
    {
        $this->validator = app(ScheduleValidator::class);
        $this->validator->forget();
        $this->state = $this->validator->stateFor($term->id, $campus->id);

        foreach ($this->roles[$campus->code] ?? [] as $departmentId => $role) {
            if ($role !== self::UNTOUCHED) {
                $this->assignDepartment($term, $campus, Department::query()->findOrFail($departmentId));
            }
        }
    }

    private function assignDepartment(Term $term, Campus $campus, Department $department): void
    {
        $head = $this->head($campus, $department);
        $groups = Group::query()->active()
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('department_id', $department->id)->whereNull('teacher_id')
            ->orderBy('id')->get()->keyBy('id');
        $ids = $this->random->shuffleArray($groups->keys()->all());
        $planned = array_slice($ids, 0, (int) round(count($ids) * $this->profile['assigned']));
        $rankings = $this->rankingsOf($term, $planned);
        $budget = $this->profile['actions'];
        $honorarios = self::HONORARIOS_PER_DEPARTMENT;
        $firstPlace = [];
        $outsidePicks = [];

        foreach ($planned as $groupId) {
            $order = $rankings[$groupId] ?? [];
            $outside = $this->random->getInt(1, 100) <= self::OUTSIDE_FIRST_PERCENT;
            $fits = $this->fitting($groupId, $order, $outside ? 5 : 1);

            if ($fits === []) {
                $this->count('sin candidato');

                continue;
            }

            [$teacherId, $reason] = $outside && count($fits) > 1 ? $this->outsidePick($groupId, $order, $fits, $honorarios) : [$fits[0], null];
            $position = in_array($teacherId, $fits, true) ? (int) array_search($teacherId, $fits, true) + 1 : $this->freshPosition($groupId, $order, $teacherId);

            if ($budget > 0 && $fits[0] === $order[0]) {
                $written = $this->assignWithAction($groups[$groupId], $teacherId, $head, $reason);
                $budget -= $written ? 1 : 0;
            } else {
                $written = $this->assignDirectly($groups[$groupId], $teacherId, $position, count($order), $reason, $head);
            }

            if (! $written) {
                continue;
            }

            $this->state = $this->state->withTeacher($groupId, $teacherId);

            if ($reason === null) {
                $firstPlace[] = $groupId;
            } elseif ($reason !== self::HONORARIOS_REASON) {
                $outsidePicks[] = $groupId;
            }
        }

        $this->replace($groups, $rankings, array_slice($firstPlace, 0, $this->profile['replaced']), $head);
        $this->remove($groups, array_slice(array_reverse($outsidePicks ?: array_slice($firstPlace, $this->profile['replaced'])), 0, $this->profile['removed']), $head);
    }

    /**
     * El lugar que tendría el docente en un ranking recién calculado: uno más que los candidatos antes de él que pasan
     * (los que ya no pasan bajarían al final).
     *
     * @param  list<string>  $order
     */
    private function freshPosition(string $groupId, array $order, string $teacherId): int
    {
        $position = 1;

        foreach ($order as $candidate) {
            if ($candidate === $teacherId) {
                break;
            }

            $position += $this->fits($groupId, $candidate) ? 1 : 0;
        }

        return $position;
    }

    /**
     * El ranking guardado de cada grupo, en orden, y los candidatos que la foto no traía.
     *
     * @param  list<string>  $groupIds
     * @return array<string, list<string>> grupo => docentes por lugar
     */
    private function rankingsOf(Term $term, array $groupIds): array
    {
        $rankings = [];

        foreach (array_chunk($groupIds, 500) as $chunk) {
            StaffingRanking::query()->whereIn('group_id', $chunk)->orderBy('group_id')->orderBy('rank')->toBase()
                ->get(['group_id', 'teacher_id'])
                ->each(function (object $row) use (&$rankings): void {
                    $rankings[$row->group_id][] = $row->teacher_id;
                });
        }

        $missing = array_values(array_filter(array_unique(array_merge([], ...array_values($rankings))), fn (string $id): bool => $this->state->teacher($id) === null));

        if ($missing !== []) {
            $this->state = $this->state->withTeachers(app(ScheduleStateQuery::class)->teachers($term, $missing));
        }

        return $rankings;
    }

    /**
     * Los primeros `$wanted` candidatos, en el orden del ranking, que pasan HC-01, HC-08 y HC-09 con la foto actual.
     *
     * @param  list<string>  $order
     * @return list<string>
     */
    private function fitting(string $groupId, array $order, int $wanted): array
    {
        $fits = [];

        foreach ($order as $teacherId) {
            if ($this->fits($groupId, $teacherId)) {
                $fits[] = $teacherId;

                if (count($fits) === $wanted) {
                    break;
                }
            }
        }

        return $fits;
    }

    private function fits(string $groupId, string $teacherId): bool
    {
        if ($this->state->teacher($teacherId) === null) {
            return false;
        }

        $result = $this->validator->validate($this->state->withTeacher($groupId, $teacherId), new ValidationScope([$groupId], [$teacherId]), ScheduleValidator::TEACHER_RULES);

        return ! (new ValidationResult($result->forGroup($groupId)))->hasHard();
    }

    /**
     * Un candidato fuera del primer lugar y su motivo: un docente de honorarios que pase, mientras el departamento
     * tenga cupo para ellos, o uno de los siguientes cuatro lugares que pasan.
     *
     * @param  list<string>  $order
     * @param  list<string>  $fits
     * @return array{0: string, 1: string}
     */
    private function outsidePick(string $groupId, array $order, array $fits, int &$honorarios): array
    {
        if ($honorarios > 0) {
            foreach (array_slice($order, 1) as $teacherId) {
                if ($this->state->teacher($teacherId)?->isHonorarios && $teacherId !== $fits[0] && $this->fits($groupId, $teacherId)) {
                    $honorarios--;

                    return [$teacherId, self::HONORARIOS_REASON];
                }
            }
        }

        $others = array_slice($fits, 1);

        return [$others[$this->random->getInt(0, count($others) - 1)], $this->next(self::OUTSIDE_REASONS)];
    }

    private function assignWithAction(Group $group, string $teacherId, User $head, ?string $reason): bool
    {
        try {
            app(AssignTeacher::class)->handle($group, Teacher::query()->findOrFail($teacherId), $head, $reason);
        } catch (ValidationException) {
            return false;
        }

        $this->count('por AssignTeacher');

        return true;
    }

    /**
     * Escribe la asignación sin AssignTeacher (ver la docblock de la clase): el docente del grupo y su fila del
     * historial con el lugar y el tamaño del ranking. HC-01 lo sigue cuidando la exclusión de la base.
     */
    private function assignDirectly(Group $group, string $teacherId, int $position, int $size, ?string $reason, User $head): bool
    {
        try {
            DB::transaction(function () use ($group, $teacherId, $position, $size, $reason, $head): void {
                $group->update(['teacher_id' => $teacherId]);

                GroupTeacherAssignment::query()->create([
                    'term_id' => $group->term_id,
                    'campus_id' => $group->campus_id,
                    'group_id' => $group->id,
                    'teacher_id' => $teacherId,
                    'assigned_by' => $head->getKey(),
                    'assigned_at' => now(),
                    'ranking_position_at_assignment' => $position,
                    'ranking_size_at_assignment' => $size,
                    'reason' => $reason,
                ]);
            });
        } catch (QueryException) {
            return false;
        }

        $this->count('en bloque');

        return true;
    }

    /**
     * Cambia el docente de unos grupos por el siguiente candidato que pase, con su motivo.
     *
     * @param  Collection<string, Group>  $groups
     * @param  array<string, list<string>>  $rankings
     * @param  list<string>  $groupIds
     */
    private function replace(Collection $groups, array $rankings, array $groupIds, User $head): void
    {
        foreach ($groupIds as $groupId) {
            $current = $this->state->group($groupId)?->teacherId;
            $next = Arr::first($rankings[$groupId] ?? [], fn (string $teacherId): bool => $teacherId !== $current && $this->fits($groupId, $teacherId));

            if ($next === null) {
                continue;
            }

            try {
                app(AssignTeacher::class)->handle($groups[$groupId], Teacher::query()->findOrFail($next), $head, $this->next(self::REPLACEMENT_REASONS));
            } catch (ValidationException) {
                continue;
            }

            $this->state = $this->state->withTeacher($groupId, $next);
        }
    }

    /**
     * Quita el docente de unos grupos con su motivo.
     *
     * @param  Collection<string, Group>  $groups
     * @param  list<string>  $groupIds
     */
    private function remove(Collection $groups, array $groupIds, User $head): void
    {
        foreach ($groupIds as $groupId) {
            try {
                app(UnassignTeacher::class)->handle($groups[$groupId], $head, $this->next(self::REMOVAL_REASONS));
            } catch (ValidationException) {
                continue;
            }

            $this->state = $this->state->withTeacher($groupId, null);
        }
    }

    /**
     * Las solicitudes de cambio del departamento según su papel: las piden los docentes de sus grupos asignados (los
     * que tienen más grupos primero, hasta dos solicitudes cada uno) y las decide el jefe; las aplicadas, el
     * coordinador de la carrera dueña (horario) o el jefe (salón).
     */
    private function requestChanges(Term $term, Campus $campus, Department $department, string $role): void
    {
        $plan = $this->requestPlan[$role] ?? [];
        $statuses = array_merge(...array_map(fn (string $status, int $times): array => array_fill(0, $times, $status), array_keys($plan), $plan));

        if ($statuses === []) {
            return;
        }

        $head = $this->head($campus, $department);
        $loads = Group::query()->active()->where('term_id', $term->id)->whereNotNull('teacher_id')
            ->selectRaw('teacher_id, count(*) as total, count(distinct campus_id) as campuses')->groupBy('teacher_id')
            ->get()->keyBy('teacher_id');
        $groups = Group::query()->active()->with('teacher.user')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('department_id', $department->id)->whereNotNull('teacher_id')
            ->whereHas('teacher', fn ($query) => $query->whereNotNull('user_id'))
            ->orderBy('id')->get()
            ->sort(fn (Group $a, Group $b): int => [(int) $loads[$b->teacher_id]?->campuses, (int) $loads[$b->teacher_id]?->total, $a->teacher_id, $a->id]
                <=> [(int) $loads[$a->teacher_id]?->campuses, (int) $loads[$a->teacher_id]?->total, $b->teacher_id, $b->id]);

        $perTeacher = [];
        $requests = [];

        foreach ($groups as $group) {
            if (count($requests) === count($statuses)) {
                break;
            }

            if (($perTeacher[$group->teacher_id] ?? 0) >= self::REQUESTS_PER_TEACHER) {
                continue;
            }

            $kind = count($requests) % 2 === 0 ? ChangeRequestKind::Schedule : ChangeRequestKind::Room;
            $pool = self::CHANGES[$kind->value];
            [$description, $reason] = $this->next($pool);

            try {
                $requests[] = [app(RequestChange::class)->handle($group, $group->teacher->user, $kind, $description, $reason), $statuses[count($requests)]];
                $perTeacher[$group->teacher_id] = ($perTeacher[$group->teacher_id] ?? 0) + 1;
            } catch (ValidationException) {
                continue;
            }
        }

        foreach ($requests as [$request, $status]) {
            $this->decide($campus, $request, $status, $head);
        }
    }

    private function decide(Campus $campus, ChangeRequest $request, string $status, User $head): void
    {
        try {
            if ($status === 'rejected') {
                app(RejectChangeRequest::class)->handle($request, $head, $this->next(self::REJECT_COMMENTS));
            }

            if ($status === 'approved' || $status === 'applied') {
                app(ApproveChangeRequest::class)->handle($request, $head, $this->next(self::APPROVE_COMMENTS));
            }

            if ($status === 'applied') {
                $result = app(MarkChangeRequestApplied::class)->handle($request, $this->applier($campus, $request, $head));

                if ($result->vetoReasons !== []) {
                    $this->command?->warn('La carga se queda en ajustes: '.implode(' ', $result->vetoReasons));
                }
            }
        } catch (ValidationException) {
            // La solicitud ya no admite ese paso: se queda en el estado que alcanzó.
        }
    }

    private function applier(Campus $campus, ChangeRequest $request, User $head): User
    {
        $request->loadMissing('group');

        return $request->kind === ChangeRequestKind::Schedule
            ? $this->holder(StaffingPermission::ChangeRequestApplySchedule, $campus->id, career: $request->group->owner_career_id) ?? $head
            : $head;
    }

    /**
     * Horas fuera del ITCJ para unos docentes ya cargados (adscritos a un departamento en asignación y sin grupos en
     * una carga en ajustes, así no vetan su regreso a asignación): `exceeding` se pasan de su tope (HC-08) y `full`
     * quedan con cero horas restantes, para que el tablero tenga docentes que exceden y con carga completa.
     */
    private function adjustExternalHours(Term $term): void
    {
        app(RefreshTeacherTermLoads::class)->handle($term);

        $openLoads = AcademicLoad::query()->where('term_id', $term->id)->whereIn('status', [LoadStatus::Staffing, LoadStatus::Adjusting])->get();
        $adjusting = $openLoads->where('status', LoadStatus::Adjusting);
        $staffingDepartments = $openLoads->where('status', LoadStatus::Staffing)->pluck('department_id')->unique()->values()->all();
        $blocked = Group::query()->active()->where('term_id', $term->id)->whereNotNull('teacher_id')
            ->where(function ($query) use ($adjusting): void {
                $query->whereRaw('false');

                foreach ($adjusting as $load) {
                    $query->orWhere(fn ($each) => $each->where('campus_id', $load->campus_id)->where('department_id', $load->department_id));
                }
            })
            ->distinct()->pluck('teacher_id')->all();

        $candidates = TeacherTermLoad::query()->where('term_id', $term->id)
            ->where('assigned_hours', '>', 0)->whereBetween('remaining_hours', [0, 30])
            ->whereNotIn('teacher_id', $blocked)
            ->whereIn('teacher_id', TeacherDepartment::query()->whereIn('department_id', $staffingDepartments)->select('teacher_id'))
            ->orderBy('teacher_id')->get();

        $picked = $this->random->shuffleArray($candidates->all());
        $honorarios = TeacherPosition::query()->currentOn($term->starts_on)->where('kind', PositionKind::Honorarios)->pluck('teacher_id')->flip();
        $full = array_filter(array_slice($picked, $this->profile['exceeding']), fn (TeacherTermLoad $load): bool => $load->remaining_hours > 0 && ! $honorarios->has($load->teacher_id));

        foreach (array_slice($picked, 0, $this->profile['exceeding']) as $load) {
            $this->addExternalHours($term, $load, min(40, $load->remaining_hours + $this->random->getInt(2, 5)), 'exceden a propósito');
        }

        foreach (array_slice($full, 0, $this->profile['full']) as $load) {
            $this->addExternalHours($term, $load, $load->remaining_hours, 'completos a propósito');
        }
    }

    private function addExternalHours(Term $term, TeacherTermLoad $load, int $hours, string $counter): void
    {
        try {
            app(AddExternalLoad::class)->handle(Teacher::query()->findOrFail($load->teacher_id), $term, [
                'source' => $this->next(self::EXTERNAL_SOURCES),
                'hours' => $hours,
                'notes' => 'Horas confirmadas después de la asignación de grupos.',
            ]);
        } catch (ValidationException) {
            return;
        }

        $this->count($counter);
    }

    /**
     * El jefe del departamento en el campus (quien decide las solicitudes), o el admin si no hay.
     */
    private function head(Campus $campus, Department $department): User
    {
        return $this->holder(StaffingPermission::ChangeRequestDecide, $campus->id, unit: $department->id) ?? $this->admin;
    }

    private function holder(StaffingPermission $permission, string $campusId, ?string $unit = null, ?string $career = null): ?User
    {
        $key = "{$permission->value}|{$campusId}|{$unit}|{$career}";

        if (! array_key_exists($key, $this->holders)) {
            $this->holders[$key] = app(RecipientsQuery::class)->holders($permission, Target::of(campus: $campusId, unit: $unit, career: $career))[0]['user'] ?? null;
        }

        return $this->holders[$key];
    }

    /**
     * El siguiente de la lista, en rueda desde un lugar al azar, para que salgan todos.
     *
     * @template T
     *
     * @param  list<T>  $pool
     * @return T
     */
    private function next(array $pool): mixed
    {
        $key = serialize($pool);
        $this->cursors[$key] ??= $this->random->getInt(0, count($pool) - 1);

        return $pool[$this->cursors[$key]++ % count($pool)];
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    private function timed(string $step, Closure $callback): void
    {
        $started = hrtime(true);
        $callback();
        $this->timings[$step] = (hrtime(true) - $started) / 1e9;
    }

    /**
     * @param  Collection<int, Campus>  $campuses
     */
    private function summary(Term $term, Collection $campuses, string $profile, float $seconds): void
    {
        if ($this->command === null) {
            return;
        }

        $campusIds = $campuses->modelKeys();
        $this->command->info(sprintf('Asignación docente (%s) en %.1f s: %s.', $profile, $seconds,
            implode(', ', array_map(fn (string $step, float $time): string => sprintf('%s %.1f s', $step, $time), array_keys($this->timings), $this->timings))));

        foreach ($campuses as $campus) {
            $loads = AcademicLoad::query()->with('department')->where('term_id', $term->id)->where('campus_id', $campus->id)->get()
                ->groupBy(fn (AcademicLoad $load): string => $load->status->value)
                ->map(fn (Collection $loads): string => $loads->pluck('department.code')->sort()->implode(', '));
            $vetoed = $this->vetoed[$campus->code] ?? [];

            $this->command->line(sprintf('  %s · asignación: %s · ajustes: %s · propuesta (vetadas): %s', $campus->code,
                $loads[LoadStatus::Staffing->value] ?? 'ninguna', $loads[LoadStatus::Adjusting->value] ?? 'ninguna',
                $vetoed === [] ? 'ninguna' : implode(', ', array_keys($vetoed))));
        }

        $assignments = GroupTeacherAssignment::query()->where('term_id', $term->id)->whereIn('campus_id', $campusIds)
            ->orderBy('assigned_at')->orderBy('id')->get(['id', 'group_id', 'ranking_position_at_assignment', 'reason', 'replaced_at']);
        $latest = $assignments->groupBy('group_id')->map(fn (Collection $rows): string => $rows->last()->id);
        $current = $assignments->whereNull('replaced_at');
        $closed = $assignments->whereNotNull('replaced_at');
        $removed = $closed->filter(fn (GroupTeacherAssignment $row): bool => $latest[$row->group_id] === $row->id)->count();

        $this->command->line(sprintf('  Rankings: %d renglones de %d grupos.',
            StaffingRanking::query()->where('term_id', $term->id)->whereIn('campus_id', $campusIds)->count(),
            StaffingRanking::query()->where('term_id', $term->id)->whereIn('campus_id', $campusIds)->distinct()->count('group_id')));
        $this->command->line(sprintf('  Asignaciones: %d vigentes (%d al primer lugar, %d con motivo), %d reemplazadas, %d quitadas; %d por AssignTeacher, %d en bloque, %d grupos sin candidato que pase.',
            $current->count(), $current->where('ranking_position_at_assignment', 1)->whereNull('reason')->count(), $current->whereNotNull('reason')->count(),
            $closed->count() - $removed, $removed, $this->counts['por AssignTeacher'] ?? 0, $this->counts['en bloque'] ?? 0, $this->counts['sin candidato'] ?? 0));

        $requests = ChangeRequest::query()->where('term_id', $term->id)->whereIn('campus_id', $campusIds)->get(['status'])->countBy(fn (ChangeRequest $request): string => $request->status->value);
        $this->command->line('  Solicitudes de cambio: '.implode(', ', array_map(fn (ChangeRequestStatus $status): string => ($requests[$status->value] ?? 0).' '.mb_strtolower($status->label()).'s', ChangeRequestStatus::cases())).'.');

        $honorarios = TeacherPosition::query()->currentOn($term->starts_on)->where('kind', PositionKind::Honorarios)->pluck('teacher_id')->flip();
        $categories = TeacherTermLoad::query()->where('term_id', $term->id)->get()->countBy(fn (TeacherTermLoad $load): string => match (true) {
            $load->remaining_hours < 0 => 'exceden',
            $honorarios->has($load->teacher_id) => 'honorarios',
            $load->assigned_hours === 0 => 'sin carga',
            $load->remaining_hours === 0 => 'completa',
            default => 'con horas libres',
        });
        $this->command->line('  Carga por docente: '.implode(', ', array_map(fn (string $category): string => ($categories[$category] ?? 0)." {$category}", ['exceden', 'honorarios', 'sin carga', 'completa', 'con horas libres'])).'.');
        $this->command->line('  Para revisar (contraseñas en testing.md): '.implode('; ', $this->reviewLogins($term, $campuses)).'.');
    }

    /**
     * @param  Collection<int, Campus>  $campuses
     * @return list<string>
     */
    private function reviewLogins(Term $term, Collection $campuses): array
    {
        $logins = [];
        $labels = [self::APPLIED => 'asignación, con solicitudes aplicadas', self::STAFFING => 'asignación, con solicitudes pendientes', self::ADJUSTING => 'ajustes', self::UNTOUCHED => 'asignación, sin docentes'];

        foreach ($labels as $role => $label) {
            foreach ($campuses as $campus) {
                $departmentId = array_search($role, $this->roles[$campus->code] ?? [], true);

                if ($departmentId !== false) {
                    $logins[] = sprintf('%s (%s en %s)', $this->head($campus, Department::query()->findOrFail($departmentId))->username, $label, $campus->code);

                    break;
                }
            }
        }

        foreach ($campuses as $campus) {
            $code = array_key_first($this->vetoed[$campus->code] ?? []);

            if ($code !== null) {
                $logins[] = sprintf('%s (vetada en propuesta en %s)', $this->head($campus, Department::query()->where('code', $code)->firstOrFail())->username, $campus->code);

                break;
            }
        }

        $pending = ChangeRequest::query()->with('group')->where('term_id', $term->id)->whereIn('campus_id', $campuses->modelKeys())
            ->where('status', ChangeRequestStatus::Approved)->where('kind', ChangeRequestKind::Schedule)->orderBy('id')->first();
        $coordinator = $pending === null ? null : $this->holder(StaffingPermission::ChangeRequestApplySchedule, $pending->campus_id, career: $pending->group->owner_career_id);

        if ($coordinator !== null) {
            $logins[] = "{$coordinator->username} (aplica la solicitud de horario del grupo {$pending->group->code})";
        }

        $requesters = ChangeRequest::query()->where('term_id', $term->id)->whereNotNull('teacher_id')->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');
        $teacher = Group::query()->active()->where('term_id', $term->id)->whereIn('teacher_id', $requesters->keys()->all())
            ->selectRaw('teacher_id, count(*) as total, count(distinct campus_id) as campuses')->groupBy('teacher_id')
            ->orderByDesc('campuses')->orderByDesc('total')->orderBy('teacher_id')->first();
        $user = $teacher === null ? null : Teacher::query()->with('user')->find($teacher->teacher_id)?->user;

        if ($user !== null) {
            $logins[] = sprintf('%s (docente: %d grupos en %d %s, %d solicitudes)', $user->username, $teacher->total, $teacher->campuses, 'campus', $requesters[$teacher->teacher_id]);
        }

        return $logins;
    }
}
