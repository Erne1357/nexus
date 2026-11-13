<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\StartStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
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
use App\Modules\Scheduling\Enums\ConflictSeverity;
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
use App\Modules\Scheduling\Validation\Violation;
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
 *    tenga asignaciones vigentes, reemplazadas y quitadas. Siempre quedan grupos sin docente. En un campus que se
 *    asigna después de otro (el massive siembra C1 y luego C2), hasta `campus_travel` grupos (10 en el massive, 0 en el
 *    demo) van a un docente que cabe y con quien el grupo da traslado justo entre campus (SC-09, aviso), buscado entre
 *    los primeros 20 del ranking; si no es el primer lugar, con motivo.
 * 4. Pide cambios de horario y de salón como los docentes de los grupos asignados (RequestChange) y los decide como el
 *    jefe: en la carga que termina en ajustes, pendientes, aprobadas sin aplicar y rechazadas con comentario; en la
 *    segunda, además, aprueba y aplica (MarkChangeRequestApplied, como el coordinador o el jefe) hasta que la carga
 *    regresa a asignación.
 * 5. Agrega horas fuera del ITCJ (AddExternalLoad) a unos docentes ya cargados para que excedan su tope (HC-08) o
 *    queden justos. Antes de asignar crea `staffing.honorarios_max_hours` = 20 si no existe, para que los de
 *    honorarios reciban grupos.
 * 6. Recalcula el ranking (las horas restantes ya cuentan lo asignado), `teacher_term_loads` (RefreshTeacherTermLoads)
 *    y los choques de cada modalidad (SyncScheduleConflicts, M1), e imprime el resumen con los usuarios para revisar.
 *
 * Mientras siembra, los eventos de Scheduling y de Faculty que encolarían rankings, choques y cargas por docente en
 * cada escritura se descartan, y la cola va en `sync`; los recálculos corren una vez, en los pasos de arriba.
 *
 * Cada asignación se elige contra una foto del campus que se va actualizando (como SchedulingDemoSeeder): el candidato
 * pasa HC-01, HC-08 y HC-09 con el validador antes de escribirse. Las primeras de cada departamento (`actions`) pasan por
 * AssignTeacher, con su bitácora; las demás se escriben en bloque (el docente del grupo y su fila del historial, con el
 * lugar que tendría en un ranking recién calculado), porque AssignTeacher relee la foto completa del campus dos veces
 * por grupo y con el masivo tardaría más de diez minutos. Por lo mismo, con el perfil massive los rankings se calculan
 * en paralelo con `staffing:rank`, un proceso por departamento y campus, de seis en seis.
 *
 * `$mode` (demo por puntos, docs/arquitectura/testing.md): `mixed` hace todo lo anterior. `start` (lleva al punto 5)
 * solo pasa a asignación cada carga en propuesta, como su jefe (un veto de Scheduling falla con su motivo), y calcula
 * el ranking de todos los departamentos con grupos activos. `staffed` (lleva al punto 6) da docente a todo grupo activo
 * sin docente, campus por campus en orden de clave (cada campus se fotografía después de escribir el anterior, así HC-01
 * y HC-08 cuentan lo de los dos; ver assignAll): los de las horas más saturadas primero, cada uno con el primero de su
 * ranking que pasa HC-01, HC-08 y HC-09, que en un ranking recién calculado sería el primer lugar, sin motivo, y una
 * reparación para los que se quedan sin nadie; luego recalcula `teacher_term_loads` y los choques. El grupo que aun así
 * no tiene candidato que pase se queda sin docente y se imprime.
 *
 * Determinista con `$seed` sobre los mismos datos: todo lo que se ordena antes de barajar o de escoger va por llaves
 * naturales (claves de materia y de grupo, RFC, códigos), no por los UUIDv7, que dentro del mismo milisegundo traen
 * bits al azar. `$overrides` cambia valores del perfil (las pruebas siembran traslados justos con el perfil demo). Si
 * el periodo ya tiene asignaciones en esos campus no hace nada, así que volver a correrlo no duplica. Se niega a
 * correr en producción.
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

    /** Los modos de `run()`. */
    public const array MODES = ['mixed', 'start', 'staffed'];

    /**
     * En el modo `staffed`, a cuántos candidatos de un grupo sin docente se les intenta liberar lugar, y entre cuántos se
     * busca a quién pasarle el grupo que se mueve.
     */
    private const int REPAIR_CANDIDATES = 25;

    /**
     * Por perfil: parte de los grupos que se asignan, asignaciones por AssignTeacher por departamento, reemplazos y
     * docentes quitados por departamento, docentes que se pasan de su tope o quedan justos, grupos con traslado justo
     * entre campus (SC-09) y si los rankings van en paralelo.
     */
    private const array PROFILES = [
        'demo' => ['assigned' => 0.75, 'actions' => 6, 'replaced' => 1, 'removed' => 1, 'exceeding' => 3, 'full' => 2, 'campus_travel' => 0, 'parallel' => false],
        'massive' => ['assigned' => 0.65, 'actions' => 10, 'replaced' => 2, 'removed' => 1, 'exceeding' => 4, 'full' => 3, 'campus_travel' => 10, 'parallel' => true],
    ];

    /** Rankings en paralelo a la vez, para no agotar la memoria ni las conexiones de la base. */
    private const int PARALLEL_RANKINGS = 6;

    /** Candidatos que se revisan por grupo buscando uno con traslado justo (SC-09). */
    private const int TRAVEL_ATTEMPTS = 20;

    /** La exclusión de la base que rechaza dos sesiones empalmadas del mismo docente (HC-01). */
    private const string OVERLAP_CONSTRAINT = 'group_sessions_teacher_no_overlap';

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

    /** @var array{assigned: float, actions: int, replaced: int, removed: int, exceeding: int, full: int, campus_travel: int, parallel: bool} */
    private array $profile;

    /** Grupos con traslado justo (SC-09) que faltan por sembrar en esta corrida. */
    private int $travelLeft = 0;

    /** @var array<string, true> campus con asignaciones de esta corrida */
    private array $assignedCampuses = [];

    /** @var array<string, true> docentes con un traslado justo sembrado */
    private array $travelTeachers = [];

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

    /** @var list<string> las modalidades cuyas cargas ya se pasaron a asignación en esta corrida (startOnce()) */
    private array $startedModalities = [];

    /** @var array<string, array<string, string>> modalidad => departamento => papel: el de la carga, igual en sus campus */
    private array $modalityRoles = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, int> lista de textos => siguiente a usar */
    private array $cursors = [];

    /** @var array<string, float> paso => segundos */
    private array $timings = [];

    /** @var array<string, array<string, int>> campus => departamento => grupos sin candidato que pase (modo `staffed`) */
    private array $unstaffed = [];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  array<string, int|float|bool>  $overrides  sobrescribe valores del perfil (`campus_travel`, `assigned`…)
     * @param  string  $mode  uno de MODES (ver la docblock de la clase)
     *
     * @throws RuntimeException en producción, con un modo que no existe, si el periodo no existe, si falla un ranking en
     *                          paralelo o si Scheduling veta pasar una carga a asignación en el modo `start`
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027, array $overrides = [], string $mode = 'mixed'): void
    {
        $this->refuseProduction();

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Modo desconocido: {$mode}.");
        }

        $started = hrtime(true);
        $this->random = new Randomizer(new Mt19937($seed));
        $this->profile = [...(self::PROFILES[$profile] ?? self::PROFILES['demo']), ...$overrides];
        $this->travelLeft = (int) $this->profile['campus_travel'];
        $this->requestPlan = self::REQUESTS[$profile] ?? self::REQUESTS['demo'];

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $this->admin = User::query()->where('username', 'admin')->first() ?? User::query()->first()
            ?? throw new RuntimeException('Hace falta un usuario para la bitácora: corre antes AdminUserSeeder.');
        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();

        if ($mode !== 'mixed') {
            $this->runProcess($termModel, $found, $mode, $started);

            return;
        }

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
                foreach ($this->modalitiesWithGroups($termModel, $found) as $modality) {
                    app(SyncScheduleConflicts::class)->handle($termModel, $modality);
                }
            });
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $this->summary($termModel, $found, $profile, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Los modos `start` y `staffed` de la demo por puntos (ver la docblock de la clase), con la cola en `sync` y los
     * eventos de recálculo descartados, como el modo `mixed`.
     *
     * @param  Collection<int, Campus>  $campuses
     */
    private function runProcess(Term $term, Collection $campuses, string $mode, int|float $started): void
    {
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            Setting::query()->firstOrCreate(
                ['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.honorarios_max_hours'],
                ['value' => self::HONORARIOS_MAX_HOURS],
            );

            Event::fakeFor(function () use ($term, $campuses, $mode): void {
                if ($mode === 'start') {
                    $this->timed('a asignación', function () use ($term, $campuses): void {
                        foreach ($campuses as $campus) {
                            $this->startAll($term, $campus);
                        }
                    });
                    $this->timed('ranking', fn () => $this->rank($term, $campuses));

                    return;
                }

                $this->timed('asignaciones', function () use ($term, $campuses): void {
                    foreach ($campuses as $campus) {
                        $this->assignAll($term, $campus);
                        $this->command?->info(sprintf('  %s: memoria pico %d MB.', $campus->code, memory_get_peak_usage(true) / 1048576));
                    }
                });
                $this->timed('carga por docente', fn () => app(RefreshTeacherTermLoads::class)->handle($term));
            }, self::FAKED_EVENTS);

            if ($mode === 'staffed') {
                $this->timed('choques', function () use ($term, $campuses): void {
                    foreach ($this->modalitiesWithGroups($term, $campuses) as $modality) {
                        app(SyncScheduleConflicts::class)->handle($term, $modality);
                    }
                });
            }
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $unstaffed = implode('; ', array_map(
            fn (string $campus, array $departments): string => $campus.': '.implode(', ', array_map(fn (string $code, int $total): string => "{$code} {$total}", array_keys($departments), $departments)),
            array_keys($this->unstaffed), $this->unstaffed,
        ));
        $this->command?->info(sprintf('Asignación docente (%s) en %.1f s: %s.', $mode, (hrtime(true) - $started) / 1e9,
            implode(', ', array_map(fn (string $step, float $seconds): string => sprintf('%s %.1f s', $step, $seconds), array_keys($this->timings), $this->timings))));

        if ($mode === 'staffed') {
            $this->command?->info(sprintf('  Asignaciones: %d por AssignTeacher, %d en bloque; sin candidato que pase: %s.',
                $this->counts['por AssignTeacher'] ?? 0, $this->counts['en bloque'] ?? 0, $unstaffed === '' ? 'ninguno' : $unstaffed));
        }
    }

    /**
     * Pasa a asignación cada carga en propuesta del campus, como el jefe de su departamento. Un veto falla con su motivo.
     */
    private function startAll(Term $term, Campus $campus): void
    {
        // M1: la carga es de la modalidad; si otro campus de la misma ya la pasó, no se vuelve a pasar.
        if (! $this->startOnce($campus)) {
            return;
        }

        $loads = AcademicLoad::query()->with('department')
            ->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->where('status', LoadStatus::Proposed)
            ->get()->sortBy('department.code');

        foreach ($loads as $load) {
            try {
                app(StartStaffing::class)->handle($load, $this->head($campus, $load->department), 'Horario sin choques duros: pasa a asignación docente.');
            } catch (ValidationException $exception) {
                throw new RuntimeException(sprintf('La carga de %s en %s no pasa a asignación: %s', $load->department->code, $campus->code, Arr::first(Arr::flatten($exception->errors()))), previous: $exception);
            }
        }

        $this->command?->info(sprintf('Asignación docente en %s: pasan a asignación %s.', $campus->code, $loads->isEmpty() ? 'ninguna carga' : $loads->pluck('department.code')->implode(', ')));
    }

    /**
     * Da docente a cada grupo activo sin docente del campus. Primero lo planea contra una foto del campus que se va
     * actualizando: los grupos de las horas más saturadas de su departamento van antes (byCongestion) y cada uno toma al
     * primero de su ranking que pasa HC-01, HC-08 y HC-09, el primer lugar si el ranking se recalculara en ese momento.
     * Al que se queda sin nadie se le busca lugar moviendo un grupo de un candidato a otro docente (repair). Luego lo
     * escribe: las primeras de cada departamento (`actions`) que van al primer lugar guardado por AssignTeacher, las
     * demás en bloque (el docente del grupo y su fila del historial, sin bitácora, como el modo `mixed`). Solo toca los
     * grupos de las cargas en asignación. Los rankings guardados se quedan como los calculó el punto 5.
     */
    private function assignAll(Term $term, Campus $campus): void
    {
        $this->validator = app(ScheduleValidator::class);
        $this->validator->forget();
        $this->state = $this->validator->stateFor($term->id, $campus->modality_id);

        $groups = Group::query()->active()->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $term->id)->where('groups.campus_id', $campus->id)->whereNull('groups.teacher_id')
            ->whereIn('groups.department_id', AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->where('status', LoadStatus::Staffing)->select('department_id'))
            ->orderBy('subjects.code')->orderBy('groups.code')->select('groups.*')->get();
        $rankings = $this->rankingsOf($term, $groups->modelKeys());
        $departments = Department::query()->withTrashed()->whereIn('id', $groups->pluck('department_id')->unique()->all())->get()->keyBy('id');
        $plan = [];

        foreach ($this->byCongestion($groups, $rankings) as $group) {
            $teacherId = $this->fitting($group->id, $rankings[$group->id] ?? [], 1)[0] ?? null;

            if ($teacherId !== null) {
                $this->state = $this->state->withTeacher($group->id, $teacherId);
                $plan[$group->id] = $teacherId;
            }
        }

        foreach ($groups as $group) {
            if (! isset($plan[$group->id])) {
                $this->repair($group->id, $rankings, $plan);
            }
        }

        $budget = [];

        foreach ($groups as $group) {
            $department = $departments[$group->department_id];
            $teacherId = $plan[$group->id] ?? null;
            $order = $rankings[$group->id] ?? [];
            $head = $this->head($campus, $department);
            $budget[$department->id] ??= (int) $this->profile['actions'];

            if ($teacherId !== null && $budget[$department->id] > 0 && $teacherId === $order[0]) {
                $written = $this->assignWithAction($group, $teacherId, $head, null);
                $budget[$department->id] -= $written ? 1 : 0;
            } else {
                $written = $teacherId !== null && $this->assignDirectly($group, $teacherId, 1, count($order), null, $head);
            }

            if (! $written) {
                $this->unstaffed[$campus->code][$department->code] = ($this->unstaffed[$campus->code][$department->code] ?? 0) + 1;
            }
        }
    }

    /**
     * Los grupos en el orden en que se planea su docente: primero los que caen en las horas en que su departamento tiene
     * más grupos por candidato (en esas horas cada docente sirve para uno solo, HC-01); en empate, el orden de llegada.
     *
     * @param  Collection<int, Group>  $groups
     * @param  array<string, list<string>>  $rankings
     * @return Collection<int, Group>
     */
    private function byCongestion(Collection $groups, array $rankings): Collection
    {
        $busy = [];

        foreach ($groups as $group) {
            foreach ($this->hoursOf($group->id) as $hour) {
                $busy[$group->department_id][$hour] = ($busy[$group->department_id][$hour] ?? 0) + 1;
            }
        }

        return $groups->sortByDesc(function (Group $group) use ($busy, $rankings): float {
            $peak = max([0, ...array_map(fn (string $hour): int => $busy[$group->department_id][$hour], $this->hoursOf($group->id))]);

            return $peak / max(1, count($rankings[$group->id] ?? []));
        })->values();
    }

    /**
     * Las horas de la semana que ocupa el grupo, como «día|hora».
     *
     * @return list<string>
     */
    private function hoursOf(string $groupId): array
    {
        $hours = [];

        foreach ($this->state->sessionsOf($groupId) as $session) {
            for ($hour = intdiv($session->start, 60); $hour * 60 < $session->end; $hour++) {
                $hours[] = "{$session->day}|{$hour}";
            }
        }

        return array_values(array_unique($hours));
    }

    /**
     * Busca lugar para un grupo sin candidato que pase: a uno de sus primeros candidatos que solo choca (HC-01) o no le
     * alcanzan las horas (HC-08) por un grupo planeado en esta corrida, se le quita ese grupo si así le cabe este y otro
     * docente que pasa toma el que se movió. Los dos quedan en el primer lugar que pasa al momento de decidir.
     *
     * @param  array<string, list<string>>  $rankings
     * @param  array<string, string>  $plan  grupo => docente, de esta corrida
     */
    private function repair(string $groupId, array $rankings, array &$plan): bool
    {
        $byTeacher = [];

        foreach ($plan as $planned => $teacherId) {
            $byTeacher[$teacherId][] = $planned;
        }

        foreach (array_slice($rankings[$groupId] ?? [], 0, self::REPAIR_CANDIDATES) as $teacherId) {
            $result = $this->check($groupId, $teacherId);
            $hard = array_unique(array_map(fn (Violation $violation): string => $violation->rule, array_filter($result?->violations() ?? [], fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard)));

            if ($result === null || $hard === [] || array_diff($hard, ['HC-01', 'HC-08']) !== []) {
                continue;
            }

            foreach ($byTeacher[$teacherId] ?? [] as $moved) {
                $without = $this->state->withTeacher($moved, null);

                if (! $this->fits($groupId, $teacherId, $without)) {
                    continue;
                }

                $with = $without->withTeacher($groupId, $teacherId);

                foreach (array_slice($rankings[$moved] ?? [], 0, self::REPAIR_CANDIDATES) as $other) {
                    if ($other !== $teacherId && $this->fits($moved, $other, $with)) {
                        $this->state = $with->withTeacher($moved, $other);
                        $plan[$groupId] = $teacherId;
                        $plan[$moved] = $other;

                        return true;
                    }
                }
            }
        }

        return false;
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
            // Las solicitudes mueven la carga entera (M1): una vez por departamento y modalidad, en el primer campus.
            $requested = [];

            foreach ($campuses as $campus) {
                foreach ($this->roles[$campus->code] ?? [] as $departmentId => $role) {
                    if (isset($requested["{$campus->modality_id}|{$departmentId}"])) {
                        continue;
                    }

                    $requested["{$campus->modality_id}|{$departmentId}"] = true;
                    $this->requestChanges($term, $campus, Department::query()->findOrFail($departmentId), $role, $campuses->where('modality_id', $campus->modality_id)->pluck('id')->all());
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
        // M1: la carga es de la modalidad: se pasa a asignación y se reparten sus papeles una vez, con el primer campus de
        // la demo en ella; los demás campus de la modalidad toman los mismos papeles.
        if (! $this->startOnce($campus)) {
            $this->roles[$campus->code] = $this->modalityRoles[$campus->modality_id] ?? [];

            return;
        }

        $loads = AcademicLoad::query()->with('department')
            ->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->where('status', LoadStatus::Proposed)
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
            ->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->where('status', LoadStatus::Staffing)->get();
        $groups = Group::query()->active()->where('term_id', $term->id)->where('modality_id', $campus->modality_id)
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id')->all();
        $ordered = $staffing->sort(fn (AcademicLoad $a, AcademicLoad $b): int => [$groups[$b->department_id] ?? 0, $a->department->code] <=> [$groups[$a->department_id] ?? 0, $b->department->code])->values();
        $count = $ordered->count();
        $sequence = $count >= 3
            ? [self::ADJUSTING, self::APPLIED, ...array_fill(0, $count - 3, self::STAFFING), self::UNTOUCHED]
            : array_slice([self::ADJUSTING, self::APPLIED], 0, $count);

        foreach ($ordered as $index => $load) {
            $this->modalityRoles[$campus->modality_id][$load->department_id] = $sequence[$index];
        }

        $this->roles[$campus->code] = $this->modalityRoles[$campus->modality_id] ?? [];
    }

    /**
     * Si las cargas de la modalidad del campus todavía no se pasaron a asignación en esta corrida: la primera vez que se
     * pregunta por una modalidad dice que sí y la recuerda (la carga es por departamento y modalidad, M1).
     */
    private function startOnce(Campus $campus): bool
    {
        if (in_array($campus->modality_id, $this->startedModalities, true)) {
            return false;
        }

        $this->startedModalities[] = $campus->modality_id;

        return true;
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
            ->selectRaw('campus_id, department_id, count(*) as total')->groupBy('campus_id', 'department_id')
            ->orderByDesc('total')->orderBy('campus_id')->orderBy('department_id')->get()
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
     * Un `staffing:rank` por departamento y campus contra la misma base que este proceso, hasta `PARALLEL_RANKINGS` a la
     * vez: en cuanto uno termina entra el siguiente, y los de más grupos van primero, así el más largo no espera a nadie.
     *
     * @param  list<array{0: string, 1: string}>  $scopes  campus y departamento
     */
    private function rankInParallel(Term $term, array $scopes): void
    {
        $connection = (string) config('database.default');
        $env = ['DB_CONNECTION' => $connection, 'DB_DATABASE' => (string) config("database.connections.{$connection}.database")];
        $running = [];
        $failures = [];
        $settle = function (int $slots) use (&$running, &$failures): void {
            while (count($running) >= $slots) {
                foreach ($running as $key => $process) {
                    if (! $process->running()) {
                        $result = $process->wait();

                        if ($result->failed()) {
                            $failures[] = trim($result->errorOutput() ?: $result->output());
                        }

                        unset($running[$key]);
                    }
                }

                if (count($running) >= $slots) {
                    usleep(200_000);
                }
            }
        };

        foreach ($scopes as [$campus, $department]) {
            $settle(self::PARALLEL_RANKINGS);
            $running[] = Process::path(base_path())->env($env)->timeout(900)
                ->start([PHP_BINARY, '-d', 'memory_limit=1G', 'artisan', 'staffing:rank', $term->code, "--campus={$campus}", "--department={$department}"]);
        }

        $settle(1);

        if ($failures !== []) {
            throw new RuntimeException('Falló un ranking en paralelo: '.$failures[0]);
        }
    }

    /**
     * Asigna los grupos de los departamentos del campus que no quedan intactos, contra una foto de su modalidad (la del
     * campus por el puente, mientras Staffing siembre por campus).
     */
    private function assignCampus(Term $term, Campus $campus): void
    {
        $this->validator = app(ScheduleValidator::class);
        $this->validator->forget();
        $this->state = $this->validator->stateFor($term->id, $campus->modality_id);

        foreach ($this->roles[$campus->code] ?? [] as $departmentId => $role) {
            if ($role !== self::UNTOUCHED) {
                $this->assignDepartment($term, $campus, Department::query()->findOrFail($departmentId));
            }
        }
    }

    private function assignDepartment(Term $term, Campus $campus, Department $department): void
    {
        $head = $this->head($campus, $department);
        $groups = Group::query()->active()->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $term->id)->where('groups.campus_id', $campus->id)->where('groups.department_id', $department->id)->whereNull('groups.teacher_id')
            ->orderBy('subjects.code')->orderBy('groups.code')->select('groups.*')->get()->keyBy('id');
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

            $travel = $this->travelPick($campus, $groupId, $order);

            [$teacherId, $reason] = match (true) {
                $travel !== null => [$travel[0], $travel[1] === 1 ? null : $this->next(self::OUTSIDE_REASONS)],
                $outside && count($fits) > 1 => $this->outsidePick($groupId, $order, $fits, $honorarios),
                default => [$fits[0], null],
            };
            // El lugar se cuenta contra un ranking recién calculado (freshPosition): en la app cada asignación recalcula
            // el ranking y quien ya no cabe baja al bloque final. Por eso AssignTeacher, que lee el ranking guardado,
            // solo se usa abajo cuando el primero que cabe es también el primero guardado (fits[0] === order[0]).
            $position = $travel[1] ?? (in_array($teacherId, $fits, true) ? (int) array_search($teacherId, $fits, true) + 1 : $this->freshPosition($groupId, $order, $teacherId));

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
            $this->assignedCampuses[$campus->code] = true;

            if ($travel !== null) {
                $this->travelLeft--;
                $this->travelTeachers[$teacherId] = true;
                $this->count('traslado justo');
            } elseif ($reason === null) {
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

    private function fits(string $groupId, string $teacherId, ?ScheduleState $state = null): bool
    {
        $result = $this->check($groupId, $teacherId, $state);

        return $result !== null && ! $result->hasHard();
    }

    /**
     * Lo que diría el validador del grupo si fuera del docente (HC-01, HC-08, HC-09 y SC-09), con la foto actual; null
     * si la foto no conoce al docente.
     */
    private function check(string $groupId, string $teacherId, ?ScheduleState $state = null): ?ValidationResult
    {
        $state ??= $this->state;

        if ($state->teacher($teacherId) === null) {
            return null;
        }

        $result = $this->validator->validate($state->withTeacher($groupId, $teacherId), new ValidationScope([$groupId], [$teacherId]), ScheduleValidator::TEACHER_RULES);

        return new ValidationResult($result->forGroup($groupId));
    }

    /**
     * Con otro campus ya asignado en esta corrida y mientras queden `campus_travel`, un candidato del ranking que cabe
     * y con quien el grupo da traslado justo (SC-09, aviso), entre los primeros `TRAVEL_ATTEMPTS`, con el lugar que
     * tendría en un ranking recién calculado; null si no hay.
     *
     * @param  list<string>  $order
     * @return array{0: string, 1: int}|null
     */
    private function travelPick(Campus $campus, string $groupId, array $order): ?array
    {
        if ($this->travelLeft <= 0 || array_diff_key($this->assignedCampuses, [$campus->code => true]) === []) {
            return null;
        }

        $position = 1;

        foreach (array_slice($order, 0, self::TRAVEL_ATTEMPTS) as $teacherId) {
            $result = $this->check($groupId, $teacherId);

            if ($result === null || $result->hasHard()) {
                continue;
            }

            if ($result->forRule('SC-09') !== []) {
                return [$teacherId, $position];
            }

            $position++;
        }

        return null;
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
                    'modality_id' => $group->modality_id,
                    'group_id' => $group->id,
                    'teacher_id' => $teacherId,
                    'assigned_by' => $head->getKey(),
                    'assigned_at' => now(),
                    'ranking_position_at_assignment' => $position,
                    'ranking_size_at_assignment' => $size,
                    'reason' => $reason,
                ]);
            });
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), self::OVERLAP_CONSTRAINT)) {
                throw $exception;
            }

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
     *
     * @param  list<string>  $campusIds  los campus de la demo en la modalidad de la carga, de cuyos grupos salen las solicitudes
     */
    private function requestChanges(Term $term, Campus $campus, Department $department, string $role, array $campusIds = []): void
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
        $groups = Group::query()->active()->with(['teacher.user', 'subject'])
            ->where('term_id', $term->id)->whereIn('campus_id', $campusIds === [] ? [$campus->id] : $campusIds)->where('department_id', $department->id)->whereNotNull('teacher_id')
            ->whereHas('teacher', fn ($query) => $query->whereNotNull('user_id'))
            ->get()
            ->sort(fn (Group $a, Group $b): int => $this->requestRank($b, $loads) <=> $this->requestRank($a, $loads) ?: [$a->teacher->rfc, $a->subject->code, $a->code] <=> [$b->teacher->rfc, $b->subject->code, $b->code]);

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

    /**
     * Quién pide primero: los docentes con traslado justo sembrado, luego los que dan clase en más campus y los que
     * tienen más grupos.
     *
     * @param  Collection<string, Group>  $loads  grupos por docente: `total` y `campuses`
     * @return array{0: bool, 1: int, 2: int}
     */
    private function requestRank(Group $group, Collection $loads): array
    {
        return [isset($this->travelTeachers[$group->teacher_id]), (int) $loads[$group->teacher_id]?->campuses, (int) $loads[$group->teacher_id]?->total];
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
                    $query->orWhere(fn ($each) => $each->where('modality_id', $load->modality_id)->where('department_id', $load->department_id));
                }
            })
            ->distinct()->pluck('teacher_id')->all();

        $candidates = TeacherTermLoad::query()->join('teachers', 'teachers.id', '=', 'teacher_term_loads.teacher_id')
            ->where('teacher_term_loads.term_id', $term->id)
            ->where('teacher_term_loads.assigned_hours', '>', 0)->whereBetween('teacher_term_loads.remaining_hours', [0, 30])
            ->whereNotIn('teacher_term_loads.teacher_id', $blocked)
            ->whereIn('teacher_term_loads.teacher_id', TeacherDepartment::query()->whereIn('department_id', $staffingDepartments)->select('teacher_id'))
            ->orderBy('teachers.rfc')->select('teacher_term_loads.*')->get();

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
            $this->holders[$key] = app(RecipientsQuery::class)->holders($permission, Target::of(modality: Campus::withTrashed()->whereKey($campusId)->value('modality_id'), campus: in_array(Dimension::Campus, $permission->dimensions(), true) ? $campusId : null, unit: $unit, career: $career))[0]['user'] ?? null;
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
            $loads = AcademicLoad::query()->with('department')->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->get()
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
        $this->command->line(sprintf('  Asignaciones: %d vigentes (%d al primer lugar, %d con motivo), %d reemplazadas, %d quitadas, %d con traslado justo (SC-09); %d por AssignTeacher, %d en bloque, %d grupos sin candidato que pase.',
            $current->count(), $current->where('ranking_position_at_assignment', 1)->whereNull('reason')->count(), $current->whereNotNull('reason')->count(),
            $closed->count() - $removed, $removed, $this->counts['traslado justo'] ?? 0, $this->counts['por AssignTeacher'] ?? 0, $this->counts['en bloque'] ?? 0, $this->counts['sin candidato'] ?? 0));

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

        $pending = ChangeRequest::query()->with('group')
            ->join('groups', 'groups.id', '=', 'change_requests.group_id')->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('change_requests.term_id', $term->id)->whereIn('change_requests.campus_id', $campuses->modelKeys())
            ->where('change_requests.status', ChangeRequestStatus::Approved)->where('change_requests.kind', ChangeRequestKind::Schedule)
            ->orderBy('subjects.code')->orderBy('groups.code')->select('change_requests.*')->first();
        $coordinator = $pending === null ? null : $this->holder(StaffingPermission::ChangeRequestApplySchedule, $pending->campus_id, career: $pending->group->owner_career_id);

        if ($coordinator !== null) {
            $logins[] = "{$coordinator->username} (aplica la solicitud de horario del grupo {$pending->group->code})";
        }

        $requesters = ChangeRequest::query()->where('term_id', $term->id)->whereNotNull('teacher_id')->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');
        $teacher = Group::query()->active()->join('teachers', 'teachers.id', '=', 'groups.teacher_id')
            ->where('groups.term_id', $term->id)->whereIn('groups.teacher_id', [...$requesters->keys()->all(), ...array_keys($this->travelTeachers)])
            ->selectRaw('groups.teacher_id, teachers.rfc, count(*) as total, count(distinct groups.campus_id) as campuses')->groupBy('groups.teacher_id', 'teachers.rfc')
            ->get()
            ->sortBy(fn (Group $row): array => [-(int) (isset($this->travelTeachers[$row->teacher_id]) && $requesters->has($row->teacher_id)), -(int) $requesters->has($row->teacher_id), -$row->campuses, -$row->total, $row->rfc])
            ->first();
        $user = $teacher === null ? null : Teacher::query()->with('user')->find($teacher->teacher_id)?->user;

        if ($user !== null) {
            $logins[] = sprintf('%s (docente: %d grupos en %d campus, %d solicitudes%s)', $user->username, $teacher->total, $teacher->campuses, $requesters[$teacher->teacher_id] ?? 0,
                isset($this->travelTeachers[$teacher->teacher_id]) ? ', con traslado justo' : '');
        }

        return $logins;
    }

    /**
     * Las modalidades con grupos del periodo en esos campus, por clave: los choques se recalculan por modalidad (M1).
     *
     * @param  iterable<Campus>  $campuses
     * @return Collection<int, Modality>
     */
    private function modalitiesWithGroups(Term $term, iterable $campuses): Collection
    {
        $ids = collect($campuses)->map(fn (Campus $campus): string => $campus->id)->all();

        return Modality::query()->withTrashed()
            ->whereIn('id', Group::query()->where('term_id', $term->id)->whereIn('campus_id', $ids)->select('modality_id'))
            ->orderBy('code')->get();
    }
}
