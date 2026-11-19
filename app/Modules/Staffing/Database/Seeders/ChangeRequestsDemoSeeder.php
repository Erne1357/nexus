<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Database\Seeders\ReanalysisChangesDemoSeeder;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\State\StateSession;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Solver\Enums\PlacementStop;
use App\Modules\Staffing\Actions\ChangeRequests\ApplyChosenAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\AssessChangeImpact;
use App\Modules\Staffing\Actions\ChangeRequests\ChooseChangeAlternative;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Actions\ChangeRequests\ProposeChangeAlternatives;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeAlternativesStatus;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Enums\StaffingPermission;
use App\Modules\Staffing\Jobs\ComputeChangeAlternatives;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Support\ChangeAlternatives;
use App\Modules\Staffing\Support\SessionLabels;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Demo de las solicitudes de cambio de la fase 8 (tareas 8.6 y 8.7) en el punto `reanalisis-casos`, para que «Solicitudes
 * de cambio» salga llena con cada estado de su pantalla: en la carga que ReanalysisDemoSeeder reserva
 * (CASE_CHANGE_REQUESTS; con el masivo, SC, y con el volumen demo ninguna), publicada en su versión 1, Subdirección la
 * reabre a ajustes con su motivo (ReopenLoad) y los docentes y las coordinaciones piden cambios de horario y de salón en
 * sus grupos de los dos campus (RequestChange), unos con el horario o el salón propuesto (8.6: el impacto se calcula al
 * pedirla) y otros solo con texto. El jefe del departamento (`jefe.<clave>`) los decide y quien aplica según el tipo (la
 * coordinación de la carrera dueña con nombramiento en el campus del grupo, o el jefe) los aplica, todo por las Actions
 * reales. Cada escenario de SCENARIOS termina en un estado, en este orden (aprobar pasa la carga a ajustes y aplicar la
 * última aprobada la regresa a asignación):
 *
 * 1. **Lo que ya pasó:** cuatro rechazadas con comentario y cuatro aprobadas como se pidieron (las que traen horario o
 *    salón, quien las aplica las escribe con ReplaceGroupSessions, como el editor).
 * 2. **A mano:** una con choque duro aprobada con una alternativa del motor que la coordinación acomoda en el editor
 *    (ReplaceGroupSessions con las sesiones de la elegida) y marca aplicada: «Aprobada con la alternativa A · aplicada a
 *    mano».
 * 3. **La alternativa aplicada:** una con choque duro y otra que choca con el grupo de esa; el motor calcula las
 *    alternativas de las dos (ProposeChangeAlternatives, con el job en la cola síncrona), el jefe aprueba la primera con
 *    una (ChooseChangeAlternative), se marcan aplicadas las cuatro de antes y al final se aplica la alternativa
 *    (ApplyChosenAlternative), que regresa la carga a asignación y avisa a los docentes. Como su grupo se movió, las
 *    alternativas de la otra ya no valen: al elegir una, el jefe ve «Esto cambió…».
 * 4. **Después:** pendientes sin choques (de horario y de salón), con solo el aviso de traslado entre campus (SC-09, si
 *    un docente del departamento tiene clases en otro campus), con choque duro y sus alternativas listas (de horario y de
 *    salón), con
 *    choque duro sin pedir alternativas, solo con texto, con el cálculo en la cola («En cola»; pasados
 *    ComputeChangeAlternatives::QUEUE_STALE_AFTER segundos se ve colgado), con el cálculo empezado y sin terminar
 *    («Calculando…»; a los STALE_AFTER segundos, «no terminó a tiempo») y con el cálculo fallido (lo que deja el job
 *    cuando se cae, ProposeChangeAlternatives::fail()); y al final, para que la carga se quede en ajustes, dos aprobadas
 *    como se pidieron sin aplicar y una aprobada con una alternativa sin aplicar (la coordinación ve «Aplicar la
 *    alternativa» y «Abrir en el editor»).
 *
 * Las alternativas se calculan y se eligen después de la última escritura de sesiones y de reasignar las claves de los
 * grupos movidos (AssignGroupCode cambia su `updated_at`, que entra en la huella de GroupsScheduleFingerprintQuery): así
 * las pendientes con alternativas listas y la aprobada sin aplicar siguen vigentes al terminar. Las de la cola, la
 * empezada y la fallida no corren su job: se piden con la cola retenida (Bus::fake de ComputeChangeAlternatives), y la
 * empezada lleva `started_at` como lo marcaría el job al empezar (supuesto por confirmar: es el único dato que la demo
 * escribe sin Action).
 *
 * El horario o salón que se propone lo busca la demo con el validador sobre la foto de la modalidad, sin escribir: corre
 * las horas del grupo, lo cambia de día o le da otro salón del campus, y se queda con el primero que deja lo que el
 * escenario pide (nada, solo avisos o choques de docente, salón o alumno tipo), con la misma cuenta que AssessChangeImpact
 * (solo lo que el cambio agrega). Los grupos se eligen por llaves naturales (materia, campus, clave), en un orden que cambia
 * por escenario para repartirlos entre materias; cada uno se usa una vez, un docente pide en hasta MAX_PER_TEACHER grupos y
 * los docentes con clases en otro campus se guardan para el aviso de traslado.
 *
 * Se puede volver a correr y retoma una corrida que se cayó a la mitad: cada escenario tiene su propio motivo (`reason`),
 * con el que se reconoce su solicitud, y cada paso mira su estado en la base antes de escribir (una pendiente con las
 * alternativas calculándose cuando se cayó vuelve a correr su job). Mientras escribe sesiones descarta
 * GroupScheduleChanged y hace lo que harían sus oyentes: reasigna la clave de los grupos movidos
 * (los saca de la base: lo escrito como se pidió y las alternativas aplicadas) después de cada fase que escribe, y al final
 * recalcula los choques de la modalidad si el último recálculo es de antes de la última escritura en la carga. El ranking
 * no se recalcula (supuesto por confirmar: la carga queda en ajustes).
 *
 * En la demo mezclada (`todo`, modo `showcase`, detrás de `demo.showcase`) solo agrega una pendiente con choque duro y sus
 * alternativas listas en la carga en asignación o ajustes con más pendientes: las de StaffingDemoSeeder son solo texto y
 * el motor no tiene qué acomodar en ellas.
 */
final class ChangeRequestsDemoSeeder extends Seeder
{
    use RefusesProduction;

    public const string REOPEN_REASON = 'Se reabre a ajustes para atender las solicitudes de cambio de horario y de salón de docentes y coordinaciones.';

    /** Lo que pide: solo texto, o un horario o salón que no deja nada, que deja solo avisos o que deja choques duros. */
    private const string TEXT = 'text';

    private const string CLEAN = 'clean';

    private const string SOFT = 'soft';

    private const string HARD = 'hard';

    /** Choque duro con el grupo del escenario que se aprueba con una alternativa y se aplica (`applied_alternative`). */
    private const string CLASH = 'clash';

    /** En qué termina. */
    public const string REJECTED = 'rejected';

    public const string APPLIED = 'applied';

    public const string APPLIED_ALTERNATIVE = 'applied_alternative';

    /** Aprobada con una alternativa que la coordinación acomoda en el editor y marca aplicada. */
    public const string APPLIED_BY_HAND = 'applied_by_hand';

    /** Pendiente con alternativas calculadas que dejaron de valer cuando se aplicó la de `applied_alternative`. */
    public const string STALE = 'stale';

    public const string PENDING = 'pending';

    /** Pendiente con las alternativas del motor calculadas y vigentes. */
    public const string READY = 'ready';

    /** Pendiente con el cálculo en la cola, sin empezar. */
    public const string QUEUED = 'queued';

    /** Pendiente con el cálculo empezado que no terminó. */
    public const string STARTED = 'started';

    /** Pendiente con el cálculo fallido. */
    public const string FAILED = 'failed';

    public const string APPROVED = 'approved';

    public const string APPROVED_ALTERNATIVE = 'approved_alternative';

    /** Las reglas duras que la demo cuenta como «choque»: empalme de docente, de salón o del alumno tipo. */
    private const array CLASH_RULES = ['HC-01', 'HC-02', 'HC-03'];

    /** Un docente pide en hasta tantos grupos. */
    private const int MAX_PER_TEACHER = 3;

    /** Cuántos grupos prueba por escenario y cuántos salones por grupo, para que el masivo no recorra la carga entera. */
    private const int MAX_GROUPS = 30;

    private const int MAX_ROOMS = 40;

    /**
     * Los escenarios, en el orden en que se siembran. `phase` es lo que ya pasó (`history`), la aplicada a mano
     * (`manual`), la alternativa aplicada y la pendiente que choca con ella (`alternative`) o lo de después (`after`);
     * `by`, quién pide; `campus`, el campus que prefiere entre los de la carga por clave (el primero o el último: con el
     * masivo C1 o C2, con un solo campus el mismo); `description`, lo que pide cuando es solo texto (con horario o salón,
     * se escribe con lo propuesto); `reason` es único y es como se reconoce la solicitud al volver a correr.
     *
     * @var array<string, array{phase: 'history'|'manual'|'alternative'|'after', kind: 'schedule'|'room', shape: string, by: 'teacher'|'coordinator', campus: 'first'|'last', target: string, description: ?string, reason: string, comment: ?string}>
     */
    public const array SCENARIOS = [
        'r1' => ['phase' => 'history', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'first', 'target' => self::REJECTED, 'description' => null,
            'reason' => 'A la hora del grupo doy clase en la maestría.',
            'comment' => 'A esa hora el salón y los alumnos del semestre ya tienen clase; el grupo se queda como está.'],
        'r2' => ['phase' => 'history', 'kind' => 'room', 'shape' => self::TEXT, 'by' => 'coordinator', 'campus' => 'last', 'target' => self::REJECTED,
            'description' => 'Un aula con proyector y pizarrón blanco para las sesiones de teoría.',
            'reason' => 'Los alumnos reportan que el proyector del salón asignado no prende.',
            'comment' => 'Mantenimiento cambia el proyector esta semana; el grupo se queda en su salón.'],
        'r3' => ['phase' => 'history', 'kind' => 'schedule', 'shape' => self::TEXT, 'by' => 'teacher', 'campus' => 'last', 'target' => self::REJECTED,
            'description' => 'Pasar el grupo al otro turno, con las mismas horas a la semana.',
            'reason' => 'Buena parte del grupo trabaja en la maquila y pide cambiar de turno.',
            'comment' => 'El cambio saca al grupo de la franja de su turno; lo vemos en la reunión de academia.'],
        'r4' => ['phase' => 'history', 'kind' => 'room', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'first', 'target' => self::REJECTED, 'description' => null,
            'reason' => 'Prefiero un salón en planta baja, cerca de la sala de maestros.',
            'comment' => 'El salón actual queda junto al laboratorio que usa el grupo; se queda como está.'],
        'ap1' => ['phase' => 'history', 'kind' => 'schedule', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'last', 'target' => self::APPLIED, 'description' => null,
            'reason' => 'A la hora actual se me empalma con mi plaza en la universidad.',
            'comment' => 'De acuerdo; la coordinación lo acomoda en el editor de horarios.'],
        'ap2' => ['phase' => 'history', 'kind' => 'room', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'first', 'target' => self::APPLIED, 'description' => null,
            'reason' => 'Hay un alumno en silla de ruedas inscrito y el salón asignado está en el segundo piso.',
            'comment' => 'Aprobada: el salón está libre a esas horas.'],
        'ap3' => ['phase' => 'history', 'kind' => 'schedule', 'shape' => self::TEXT, 'by' => 'coordinator', 'campus' => 'last', 'target' => self::APPLIED,
            'description' => 'Recorrer una hora la última sesión de la semana.',
            'reason' => 'Los alumnos de residencia tienen junta con su asesor esa mañana.',
            'comment' => null],
        'ap4' => ['phase' => 'history', 'kind' => 'room', 'shape' => self::TEXT, 'by' => 'teacher', 'campus' => 'first', 'target' => self::APPLIED,
            'description' => 'Un laboratorio de cómputo para las sesiones de práctica.',
            'reason' => 'Las prácticas usan el simulador que solo está instalado en los laboratorios.',
            'comment' => 'De acuerdo; se cambia el salón de las prácticas.'],
        'm' => ['phase' => 'manual', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'first', 'target' => self::APPLIED_BY_HAND, 'description' => null,
            'reason' => 'A esa hora tengo la reunión semanal del cuerpo académico.',
            'comment' => 'Se aprueba con la alternativa del motor; la coordinación la acomoda en el editor.'],
        'y' => ['phase' => 'alternative', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'last', 'target' => self::APPLIED_ALTERNATIVE, 'description' => null,
            'reason' => 'Me cambiaron el horario de mi plaza en la preparatoria y a la hora actual ya no llego.',
            'comment' => 'La hora que pediste choca; se aprueba con la alternativa del motor, que no deja choques.'],
        'x' => ['phase' => 'alternative', 'kind' => 'schedule', 'shape' => self::CLASH, 'by' => 'teacher', 'campus' => 'last', 'target' => self::STALE, 'description' => null,
            'reason' => 'Así la clase queda seguida de mi otra materia y no tengo horas muertas ese día.',
            'comment' => null],
        'p1' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'first', 'target' => self::PENDING, 'description' => null,
            'reason' => 'Esos días doy asesorías de residencia profesional a la hora actual.',
            'comment' => null],
        'p2' => ['phase' => 'after', 'kind' => 'room', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'last', 'target' => self::PENDING, 'description' => null,
            'reason' => 'Entre esta clase y la siguiente hay diez minutos y el salón asignado está en el otro edificio.',
            'comment' => null],
        'p3' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::SOFT, 'by' => 'teacher', 'campus' => 'first', 'target' => self::PENDING, 'description' => null,
            'reason' => 'Así junto mis clases del día y salgo antes al otro campus.',
            'comment' => null],
        'p4' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'last', 'target' => self::READY, 'description' => null,
            'reason' => 'Tengo comisión en la academia a la hora actual del grupo.',
            'comment' => null],
        'p5' => ['phase' => 'after', 'kind' => 'room', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'first', 'target' => self::READY, 'description' => null,
            'reason' => 'Necesito un salón con mesas de trabajo para las prácticas en equipo.',
            'comment' => null],
        'p6' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'coordinator', 'campus' => 'last', 'target' => self::PENDING, 'description' => null,
            'reason' => 'Así los alumnos de semestres atrasados pueden llevar la materia sin empalmes.',
            'comment' => null],
        'p7' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::TEXT, 'by' => 'teacher', 'campus' => 'first', 'target' => self::PENDING,
            'description' => 'Pasar la última sesión de la semana a otro día, a la misma hora.',
            'reason' => 'Ese día acompaño a los alumnos a las visitas industriales.',
            'comment' => null],
        'p8' => ['phase' => 'after', 'kind' => 'room', 'shape' => self::TEXT, 'by' => 'teacher', 'campus' => 'last', 'target' => self::PENDING,
            'description' => 'Un aula más grande: el grupo tiene 38 inscritos.',
            'reason' => 'El salón es para 30 y los alumnos toman la clase de pie al fondo.',
            'comment' => null],
        'p9' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::TEXT, 'by' => 'coordinator', 'campus' => 'first', 'target' => self::PENDING,
            'description' => 'Juntar las sesiones en martes y jueves, de 11:00 a 13:00.',
            'reason' => 'Los alumnos del semestre tienen prácticas en planta los lunes y miércoles.',
            'comment' => null],
        'q' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'first', 'target' => self::QUEUED, 'description' => null,
            'reason' => 'A esa hora recojo a mis hijos de la escuela.',
            'comment' => null],
        's' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'last', 'target' => self::STARTED, 'description' => null,
            'reason' => 'Me asignaron una comisión sindical en ese horario.',
            'comment' => null],
        'f' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'coordinator', 'campus' => 'first', 'target' => self::FAILED, 'description' => null,
            'reason' => 'Los alumnos llevan otra materia del semestre a esa hora en el otro edificio.',
            'comment' => null],
        'a1' => ['phase' => 'after', 'kind' => 'room', 'shape' => self::CLEAN, 'by' => 'teacher', 'campus' => 'first', 'target' => self::APPROVED, 'description' => null,
            'reason' => 'El salón asignado no tiene proyector y la materia es con presentaciones.',
            'comment' => 'Aprobada: el salón que pides está libre a esas horas.'],
        'a2' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::TEXT, 'by' => 'coordinator', 'campus' => 'last', 'target' => self::APPROVED,
            'description' => 'Partir la sesión de dos horas en dos de una hora, en días distintos.',
            'reason' => 'El laboratorio solo está libre una hora seguida esos días.',
            'comment' => 'De acuerdo; la coordinación lo acomoda en el editor de horarios.'],
        'z' => ['phase' => 'after', 'kind' => 'schedule', 'shape' => self::HARD, 'by' => 'teacher', 'campus' => 'last', 'target' => self::APPROVED_ALTERNATIVE, 'description' => null,
            'reason' => 'Tengo junta de academia a esa hora durante todo el semestre.',
            'comment' => 'La hora que pediste deja choques; se aprueba con la alternativa del motor.'],
    ];

    /** La pendiente con alternativas de la demo mezclada (modo `showcase`). */
    public const string SHOWCASE_REASON = 'Esa hora se me empalma con la junta semanal de la academia.';

    private AcademicLoad $load;

    private User $head;

    private ModalityProfile $profile;

    /** @var list<string> códigos de campus de la carga, por clave */
    private array $campuses = [];

    /** @var array<string, ?User> «permiso|campus|carrera» => primer titular */
    private array $holders = [];

    /** @var array<string, Collection<int, Room>> */
    private array $rooms = [];

    /** @var array<string, true> docentes de la carga con clases del periodo en más de un campus */
    private array $travellers = [];

    /** @var array<string, array<string, true>> grupo => llaves de lo que ya tenía mal, con la foto de este escenario */
    private array $existing = [];

    public function __construct(
        private readonly ScheduleValidator $validator,
        private readonly AssessChangeImpact $assess,
        private readonly RecipientsQuery $recipients,
    ) {}

    /**
     * @param  'cases'|'showcase'  $mode
     */
    public function run(string $term = '20271', string $modality = 'PRES', string $mode = 'cases'): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->firstOrFail();
        $modalityModel = Modality::query()->where('code', $modality)->firstOrFail();

        if ($mode === 'showcase') {
            $this->showcase($termModel, $modalityModel);

            return;
        }

        if ($mode !== 'cases') {
            throw new InvalidArgumentException("Modo desconocido de la demo de solicitudes de cambio: {$mode}.");
        }

        $loads = ReanalysisDemoSeeder::loadsIn($termModel->getKey(), $modalityModel->getKey(), [ReanalysisDemoSeeder::CASE_CHANGE_REQUESTS]);

        if ($loads->isEmpty()) {
            $this->command?->info("Solicitudes de cambio: ninguna carga reservada en {$modality} (con el volumen demo no la hay).");
        }

        $loads->each(fn (AcademicLoad $load) => $this->seedLoad($load, $termModel, $modalityModel));
    }

    /**
     * Lo que falta o no vale en la carga reservada según SCENARIOS, para la revisión del punto (StaffingServiceProvider):
     * la carga en ajustes y cada escenario en su estado (las alternativas listas y la elegida sin aplicar, vigentes según
     * la huella barata que compara la pantalla; la de `x`, vencida). Vacío si todo está.
     *
     * @return list<string>
     */
    public static function problems(AcademicLoad $load): array
    {
        $choose = app(ChooseChangeAlternative::class);
        $requests = ChangeRequest::query()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('department_id', $load->department_id)
            ->whereIn('reason', array_column(self::SCENARIOS, 'reason'))->get()->keyBy('reason');
        $problems = $load->status === LoadStatus::Adjusting ? [] : ["la carga está en {$load->status->label()}"];

        foreach (self::SCENARIOS as $key => $scenario) {
            $request = $requests[$scenario['reason']] ?? null;

            if ($request === null) {
                $problems[] = "{$key} sin pedir";

                continue;
            }

            $alternatives = $request->alternatives;
            $status = ChangeAlternativesStatus::of($alternatives);
            $chosen = ChangeAlternatives::chosen($alternatives);
            $items = $status === ChangeAlternativesStatus::Ready ? ($alternatives['items'] ?? []) : [];
            $fresh = fn (array $item): bool => $choose->sessionsUnchanged($item);
            $expected = match ($scenario['target']) {
                self::REJECTED => ChangeRequestStatus::Rejected,
                self::APPLIED, self::APPLIED_ALTERNATIVE, self::APPLIED_BY_HAND => ChangeRequestStatus::Applied,
                self::APPROVED, self::APPROVED_ALTERNATIVE => ChangeRequestStatus::Approved,
                default => ChangeRequestStatus::Pending,
            };
            $wrong = match (true) {
                $request->status !== $expected => "está {$request->status->label()}",
                $scenario['target'] === self::APPLIED_ALTERNATIVE => $chosen === null || ! isset($alternatives['applied']) ? 'sin alternativa aplicada' : null,
                $scenario['target'] === self::APPLIED_BY_HAND => $chosen === null || isset($alternatives['applied']) ? 'sin alternativa acomodada a mano' : null,
                $scenario['target'] === self::APPROVED_ALTERNATIVE => $chosen === null || ! $fresh($chosen) ? 'sin alternativa elegida vigente' : null,
                $scenario['target'] === self::READY => $items === [] || ! array_all($items, $fresh) ? 'sin alternativas listas y vigentes' : null,
                $scenario['target'] === self::STALE => $items === [] || array_all($items, $fresh) ? 'sin alternativas vencidas' : null,
                $scenario['target'] === self::QUEUED => ($alternatives['status'] ?? null) !== ChangeAlternativesStatus::Computing->value || isset($alternatives['started_at']) ? 'sin cálculo en la cola' : null,
                $scenario['target'] === self::STARTED => ($alternatives['status'] ?? null) !== ChangeAlternativesStatus::Computing->value || ! isset($alternatives['started_at']) ? 'sin cálculo empezado' : null,
                $scenario['target'] === self::FAILED => ($alternatives['status'] ?? null) !== ChangeAlternativesStatus::Failed->value ? 'sin cálculo fallido' : null,
                $scenario['shape'] === self::SOFT => ($request->impact['hard'] ?? null) !== [] || array_column($request->impact['soft'] ?? [], 'rule') !== ['SC-09'] ? 'sin solo el aviso de traslado' : null,
                $scenario['target'] === self::PENDING && $scenario['shape'] === self::HARD => ($request->impact['hard'] ?? []) === [] || $alternatives !== null ? 'sin choque duro y sin alternativas' : null,
                default => null,
            };

            if ($wrong !== null) {
                $problems[] = "{$key} {$wrong}";
            }
        }

        return $problems;
    }

    /**
     * Los escenarios de la carga, en el orden de la docblock de la clase; cada paso revisa el estado antes de escribir.
     */
    private function seedLoad(AcademicLoad $load, Term $term, Modality $modality): void
    {
        $started = hrtime(true);
        $this->prepare($load, $modality);

        if ($load->status === LoadStatus::Published) {
            app(ReopenLoad::class)->handle($load, ReanalysisDemoSeeder::subdireccion(), self::REOPEN_REASON);
        }

        if (! in_array($load->fresh()->status, [LoadStatus::Staffing, LoadStatus::Adjusting], true)) {
            $this->command?->warn("Solicitudes de cambio: la carga de {$load->department->code} está en {$load->fresh()->status->label()}; no se siembran.");

            return;
        }

        $requests = $this->requestPhase('history');
        $this->decideHistory($requests);
        $this->settleCodes();

        $requests += $this->requestPhase('manual');
        $this->applyByHand($requests['m'] ?? null);
        $this->settleCodes();

        $requests += $this->requestPhase('alternative');
        $this->decideAlternative($requests);
        $this->settleCodes();

        $requests += $this->requestPhase('after');
        $this->decideAfter($requests);
        $this->syncConflicts($term, $modality);

        $counts = $this->requests()->get(['status'])->countBy(fn (ChangeRequest $request): string => $request->status->value);
        $this->command?->info(sprintf('Solicitudes de cambio de %s (%s): %s; la carga queda en %s (%.1f s).',
            $load->department->code, implode(' y ', $this->campuses),
            implode(', ', array_map(fn (ChangeRequestStatus $status): string => ($counts[$status->value] ?? 0).' '.mb_strtolower($status->label()).'s', ChangeRequestStatus::cases())),
            $load->fresh()->status->label(), (hrtime(true) - $started) / 1e9));
    }

    /**
     * Las solicitudes de los escenarios de una fase, por clave (null si ningún grupo sirvió). La de `x` (CLASH) choca con
     * el grupo de `y`, que va antes en la misma fase.
     *
     * @param  'history'|'manual'|'alternative'|'after'  $phase
     * @return array<string, ?ChangeRequest>
     */
    private function requestPhase(string $phase): array
    {
        $requests = [];

        foreach (self::SCENARIOS as $key => $scenario) {
            if ($scenario['phase'] === $phase) {
                $requests[$key] = $this->ensureRequested($key, $scenario, $scenario['shape'] === self::CLASH ? ($requests['y'] ?? null) : null);
            }
        }

        return $requests;
    }

    /**
     * Lo que ya pasó: el jefe rechaza con su comentario y aprueba lo pedido de las que se aplican; quien las aplica
     * escribe lo que traen horario o salón, como en el editor (las marca aplicadas decideAlternative()).
     *
     * @param  array<string, ?ChangeRequest>  $requests
     */
    private function decideHistory(array $requests): void
    {
        foreach (self::having($requests, self::REJECTED) as $key => $request) {
            $this->attempt($key, function () use ($key, $request): void {
                if ($this->pending($request)) {
                    app(RejectChangeRequest::class)->handle($request->fresh(), $this->head, (string) self::SCENARIOS[$key]['comment']);
                }
            });
        }

        foreach (self::having($requests, self::APPLIED) as $key => $request) {
            $this->attempt($key, function () use ($key, $request): void {
                if ($this->pending($request)) {
                    app(ApproveChangeRequest::class)->handle($request->fresh(), $this->head, self::SCENARIOS[$key]['comment']);
                }

                if ($request->fresh()->status === ChangeRequestStatus::Approved && AssessChangeImpact::isAssessable($request)) {
                    $sessions = array_map(fn (SessionCandidate $session): SessionCandidate => new SessionCandidate($session->day, $session->startsAt, $session->endsAt, $session->roomId, $session->kind),
                        $this->assess->proposal($request->fresh()));
                    $this->write([$request->group_id => $sessions], $request, 'con la solicitud de cambio');
                }
            });
        }
    }

    /**
     * La aplicada a mano: el jefe la aprueba con la primera alternativa del motor y la coordinación la acomoda en el
     * editor (escribe las sesiones de la elegida) y la marca aplicada. Las cuatro de antes siguen aprobadas, así que la
     * carga no regresa todavía a asignación.
     */
    private function applyByHand(?ChangeRequest $request): void
    {
        if ($request === null) {
            return;
        }

        $this->ensureAlternatives('m', $request, ChangeAlternativesStatus::Ready);
        $this->choose('m', $request);

        $chosen = ChangeAlternatives::chosen($request->fresh()->alternatives);

        if ($request->fresh()->status !== ChangeRequestStatus::Approved || $chosen === null) {
            return;
        }

        $this->attempt('m', function () use ($request, $chosen): void {
            $letter = ChangeAlternatives::letter($request->fresh()->alternatives, $chosen['id']);
            $this->write(ChangeAlternatives::candidates($chosen, replacing: false), $request, "a mano en el editor, con la alternativa {$letter} de la solicitud de cambio");
            $this->warnVeto(app(MarkChangeRequestApplied::class)->handle($request->fresh(), $this->applier($request))->vetoReasons);
        });
    }

    /**
     * La alternativa aplicada: las alternativas de `y` y de `x` se calculan después de lo escrito (así la de `y` sigue
     * vigente al aplicarla); el jefe aprueba `y` con la primera, quien aplica marca aplicadas las de lo pedido y aplica
     * `y` al final, que es la que regresa la carga a asignación. `x` choca con el grupo de `y`: al moverse, sus
     * alternativas dejan de valer («Esto cambió…»).
     *
     * @param  array<string, ?ChangeRequest>  $requests
     */
    private function decideAlternative(array $requests): void
    {
        $this->ensureAlternatives('y', $requests['y'] ?? null, ChangeAlternativesStatus::Ready);
        $this->ensureAlternatives('x', $requests['x'] ?? null, ChangeAlternativesStatus::Ready);
        $this->choose('y', $requests['y'] ?? null);

        foreach (self::having($requests, self::APPLIED) as $key => $request) {
            $this->attempt($key, function () use ($request): void {
                if ($request->fresh()->status === ChangeRequestStatus::Approved) {
                    $this->warnVeto(app(MarkChangeRequestApplied::class)->handle($request->fresh(), $this->applier($request))->vetoReasons);
                }
            });
        }

        $this->applyAlternative($requests['y'] ?? null);
    }

    /**
     * Después: las alternativas de las que las piden (las listas y la de `z`) y las tres del cálculo que no
     * termina (en la cola, empezado y fallido), y al final las aprobadas sin aplicar, que dejan la carga en ajustes.
     *
     * @param  array<string, ?ChangeRequest>  $requests
     */
    private function decideAfter(array $requests): void
    {
        foreach ([...self::having($requests, self::READY), ...self::having($requests, self::APPROVED_ALTERNATIVE)] as $key => $request) {
            $this->ensureAlternatives($key, $request, ChangeAlternativesStatus::Ready);
        }

        foreach ([...self::having($requests, self::QUEUED), ...self::having($requests, self::STARTED), ...self::having($requests, self::FAILED)] as $key => $request) {
            $this->unfinished($key, $request);
        }

        foreach (self::having($requests, self::APPROVED) as $key => $request) {
            $this->attempt($key, function () use ($key, $request): void {
                if ($this->pending($request)) {
                    app(ApproveChangeRequest::class)->handle($request->fresh(), $this->head, self::SCENARIOS[$key]['comment']);
                }
            });
        }

        foreach (self::having($requests, self::APPROVED_ALTERNATIVE) as $key => $request) {
            $this->choose($key, $request);
        }
    }

    /**
     * Un cálculo de alternativas que no termina: el jefe las pide con la cola retenida (el job no corre). En la cola se
     * queda así; empezado, lleva `started_at` como lo marca el job al empezar (ProposeChangeAlternatives::compute) y se
     * queda sin terminar; fallido, termina como lo deja el job cuando se cae (ProposeChangeAlternatives::fail()).
     */
    private function unfinished(string $key, ChangeRequest $request): void
    {
        $target = self::SCENARIOS[$key]['target'];

        if (! $this->pending($request)) {
            return;
        }

        if ($request->fresh()->alternatives === null) {
            $this->attempt($key, fn () => $this->holdingQueue(fn () => app(ProposeChangeAlternatives::class)->handle($request->fresh(), $this->head)));
        }

        $alternatives = $request->fresh()->alternatives;

        if (ChangeAlternativesStatus::of($alternatives) !== ChangeAlternativesStatus::Computing) {
            return;
        }

        if ($target === self::STARTED && ! isset($alternatives['started_at'])) {
            $request->forceFill(['alternatives' => [...$alternatives, 'started_at' => now()->toIso8601String()]])->save();
        }

        if ($target === self::FAILED) {
            app(ProposeChangeAlternatives::class)->fail($request->id, (string) $alternatives['calculation_id'], ComputeChangeAlternatives::FAILED_MESSAGE);
        }
    }

    /**
     * Las solicitudes que existen de los escenarios con ese destino.
     *
     * @param  array<string, ?ChangeRequest>  $requests
     * @return array<string, ChangeRequest>
     */
    private static function having(array $requests, string $target): array
    {
        return array_filter($requests, fn (?ChangeRequest $request, string $key): bool => $request !== null && self::SCENARIOS[$key]['target'] === $target, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * La solicitud del escenario: la que ya tiene su motivo en la carga o una nueva, con el grupo, quien pide y lo que
     * propone que encuentre pick(). Null si ningún grupo sirve (se avisa). `$applied` es la de `y`, con cuyo grupo choca
     * la de `x`.
     *
     * @param  array<string, mixed>  $scenario
     */
    private function ensureRequested(string $key, array $scenario, ?ChangeRequest $applied = null): ?ChangeRequest
    {
        $existing = $this->requests()->where('reason', $scenario['reason'])->first();

        if ($existing !== null) {
            if ($existing->impact === null) {
                $this->assess->handle($existing);
            }

            return $existing;
        }

        $kind = ChangeRequestKind::from($scenario['kind']);
        $pick = $this->pick($key, $scenario, $kind, $applied);

        if ($pick === null) {
            $this->command?->warn("Solicitudes de cambio: ningún grupo de {$this->load->department->code} sirve para el escenario «{$key}».");

            return null;
        }

        [$group, $requester, $structured, $description] = $pick;

        try {
            return app(RequestChange::class)->handle($group, $requester, $kind, $description, $scenario['reason'], $structured);
        } catch (ValidationException $exception) {
            $this->command?->warn("Solicitudes de cambio: «{$key}» no se pidió: ".collect($exception->errors())->flatten()->first());

            return null;
        }
    }

    /**
     * El primer grupo de la carga que sirve para el escenario, con quien pide y lo que propone: grupos sin solicitud de
     * la demo, con sesiones, con quien pide (su docente con usuario y menos de MAX_PER_TEACHER solicitudes, o la
     * coordinación de su carrera en su campus) y, si es de horario, con quien la aplicaría; los del campus que prefiere
     * primero.
     *
     * @param  array<string, mixed>  $scenario
     * @return array{0: Group, 1: User, 2: array<string, mixed>, 3: string}|null
     */
    private function pick(string $key, array $scenario, ChangeRequestKind $kind, ?ChangeRequest $applied): ?array
    {
        $shape = $scenario['shape'] === self::CLASH && $applied === null ? self::HARD : $scenario['shape'];

        if ($kind === ChangeRequestKind::Room && ! $this->profile->usesRooms && $shape !== self::TEXT) {
            return null;
        }

        if ($shape !== self::TEXT) {
            // Lo escrito o pedido antes cambió la foto: se lee otra vez una sola vez por escenario.
            $this->validator->forget();
            $this->existing = [];
        }

        $used = $this->requests()->pluck('group_id')->flip();
        $perTeacher = $this->requests()->whereNotNull('teacher_id')->selectRaw('teacher_id, count(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');
        $preferred = $scenario['campus'] === 'first' ? $this->campuses[0] : $this->campuses[count($this->campuses) - 1];
        $target = $applied === null ? null : Group::query()->with('sessions')->find($applied->group_id);

        $groups = $this->groups()
            ->reject(fn (Group $group): bool => $used->has($group->id) || $group->sessions->isEmpty())
            ->when($shape === self::CLASH, fn (Collection $groups) => $groups->where('campus_id', $target->campus_id))
            // Los docentes con clases en otro campus se guardan para el aviso de traslado (SOFT) y van al final en los demás.
            ->sortBy(fn (Group $group): array => [isset($this->travellers[$group->teacher_id]) !== ($shape === self::SOFT), $group->campus->code !== $preferred,
                crc32($key.'|'.implode('|', ReanalysisChangesDemoSeeder::stableKey($group)))])
            ->values();
        $tried = 0;

        foreach ($groups as $group) {
            $requester = $this->requester($scenario['by'], $group, $perTeacher->all());

            if ($requester === null || ($kind === ChangeRequestKind::Schedule && $this->scheduleApplier($group) === null)) {
                continue;
            }

            if ($shape === self::TEXT) {
                return [$group, $requester, [], (string) $scenario['description']];
            }

            if (++$tried > self::MAX_GROUPS) {
                break;
            }

            $option = $kind === ChangeRequestKind::Room ? $this->roomOption($key, $group, $shape) : $this->scheduleOption($group, $shape, $target);

            if ($option !== null) {
                return [$group, $requester, ...$option];
            }
        }

        return null;
    }

    /**
     * Quien pide en el grupo: su docente (con usuario y sin pasar de MAX_PER_TEACHER solicitudes) o la coordinación de la
     * carrera dueña con nombramiento en su campus.
     *
     * @param  array<string, int>  $perTeacher
     */
    private function requester(string $by, Group $group, array $perTeacher): ?User
    {
        if ($by === 'coordinator') {
            return $this->holder(StaffingPermission::ChangeRequestRequest, $group->campus_id, $group->owner_career_id);
        }

        $user = $group->teacher?->user;

        return $user !== null && ($perTeacher[$group->teacher_id] ?? 0) < self::MAX_PER_TEACHER ? $user : null;
    }

    /**
     * Un horario para el grupo que deja lo que pide `$shape`, y su descripción: las horas corridas, de otro día o, con
     * solo avisos, una sesión seguida de una del mismo docente en otro campus (SC-09); con CLASH, una sesión a la hora de
     * una del grupo de `$target`, con el que tiene que chocar.
     *
     * @return array{0: array{sessions: list<array{day: int, starts_at: string, ends_at: string}>}, 1: string}|null
     */
    private function scheduleOption(Group $group, string $shape, ?Group $target): ?array
    {
        $proposals = match ($shape) {
            self::SOFT => $this->travelProposals($group),
            self::CLASH => $this->clashProposals($group, $target),
            default => $this->shiftedProposals($group),
        };

        foreach ($proposals as $sessions) {
            $candidates = $this->assess->proposal($this->transient($group, ChangeRequestKind::Schedule, ['sessions' => $sessions]));

            if ($this->fits($group, $candidates, $shape, $target)) {
                $lines = array_map(fn (array $line): string => "{$line['days']} {$line['time']}", SessionLabels::snapshot($sessions));
                $rooms = $group->sessions->pluck('room_id')->filter()->unique()->count();

                return [['sessions' => $sessions], 'Mover el grupo a '.self::join($lines).($rooms > 1 ? ', en los mismos salones.' : ($rooms === 1 ? ', en el mismo salón.' : '.'))];
            }
        }

        return null;
    }

    /**
     * Otro salón activo del campus del grupo para todas sus sesiones que deja lo que pide `$shape` (con HARD, solo un
     * empalme de salón, HC-02), y su descripción. Los salones van en un orden por clave que cambia con el escenario.
     *
     * @return array{0: array{room: string}, 1: string}|null
     */
    private function roomOption(string $key, Group $group, string $shape): ?array
    {
        $current = $group->sessions->pluck('room_id')->filter()->unique()->all();
        $rooms = ($this->rooms[$group->campus_id] ??= Room::query()->where('campus_id', $group->campus_id)->where('is_active', true)->get(['id', 'code']))
            ->reject(fn (Room $room): bool => in_array($room->id, $current, true))
            ->sortBy(fn (Room $room): int => crc32("{$key}|{$room->code}"));

        foreach ($rooms->take(self::MAX_ROOMS) as $room) {
            $candidates = $this->assess->proposal($this->transient($group, ChangeRequestKind::Room, ['room' => $room->code, 'room_id' => $room->id]));
            [$hard, $soft] = $this->added($group, $candidates);
            $fits = $shape === self::HARD
                ? $hard !== [] && array_diff(array_unique(array_map(fn (Violation $violation): string => $violation->rule, $hard)), ['HC-02']) === []
                : $hard === [] && $soft === [];

            if ($fits) {
                return [['room' => $room->code], "Pasar todas las sesiones al salón {$room->code}."];
            }
        }

        return null;
    }

    /**
     * Si las sesiones propuestas dejan lo que pide `$shape`, contando solo lo que agregan.
     *
     * @param  list<SessionCandidate>  $candidates
     */
    private function fits(Group $group, array $candidates, string $shape, ?Group $target): bool
    {
        [$hard, $soft] = $this->added($group, $candidates);
        $rules = array_unique(array_map(fn (Violation $violation): string => $violation->rule, $hard));

        return match ($shape) {
            self::CLEAN => $hard === [] && $soft === [],
            self::SOFT => $hard === [] && $soft !== [],
            self::HARD => $hard !== [] && array_diff($rules, self::CLASH_RULES) === [],
            self::CLASH => $hard !== [] && array_diff($rules, self::CLASH_RULES) === []
                && array_any($hard, fn (Violation $violation): bool => in_array($target?->id, [$violation->groupId, $violation->related['other_group_id'] ?? null], true)),
            default => false,
        };
    }

    /**
     * Lo que agregan las sesiones propuestas al grupo, duro y blando, como AssessChangeImpact (menos lo que el grupo ya
     * tenía mal, que se calcula una vez por grupo y escenario), sobre la foto que el validador ya leyó.
     *
     * @param  list<SessionCandidate>  $candidates
     * @return array{0: list<Violation>, 1: list<Violation>}
     */
    private function added(Group $group, array $candidates): array
    {
        $existing = $this->existing[$group->id] ??= array_fill_keys(array_map(fn (Violation $violation): string => $violation->key(), $this->validator->validateGroup($group)->violations()), true);
        $added = array_values(array_filter($this->validator->checkGroupSessions($group, $candidates)->violations(), fn (Violation $violation): bool => ! isset($existing[$violation->key()])));

        return [
            array_values(array_filter($added, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard)),
            array_values(array_filter($added, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Soft)),
        ];
    }

    /**
     * Las sesiones del grupo corridas una a seis horas antes o después, en otro día, o las dos cosas; solo las que caen
     * en los días y bloques de la modalidad.
     *
     * @return iterable<list<array{day: int, starts_at: string, ends_at: string}>>
     */
    private function shiftedProposals(Group $group): iterable
    {
        $block = $this->profile->blockMinutes;

        foreach ([[0, 1], [0, -1], [0, 2], [0, -2], [1, 0], [-1, 0], [0, 3], [0, -3], [1, 1], [-1, -1], [0, 4], [0, -4], [0, 6], [0, -6]] as [$days, $blocks]) {
            $sessions = $this->live($group)->map(fn (array $session): array => [$this->rotate($session['day'], $days), $session['start'] + $blocks * $block, $session['end'] + $blocks * $block])->all();

            if (($proposal = $this->proposal($sessions)) !== null) {
                yield $proposal;
            }
        }
    }

    /**
     * Una sesión del grupo seguida (sin minutos libres) de una del mismo docente en otro campus, antes o después: el
     * traslado justo es un aviso (SC-09) y no bloquea. Las del docente van por día, hora y clave de su grupo, para que la
     * misma semilla dé la misma propuesta.
     *
     * @return iterable<list<array{day: int, starts_at: string, ends_at: string}>>
     */
    private function travelProposals(Group $group): iterable
    {
        if ($group->teacher_id === null) {
            return;
        }

        $state = $this->validator->stateFor($group->term_id, $group->modality_id);
        $live = $this->live($group);
        $others = collect($state->teacherSessions($group->teacher_id))
            ->reject(fn (StateSession $other): bool => $state->group($other->groupId)?->campusId === $group->campus_id)
            ->sortBy(fn (StateSession $other): array => [$other->day, $other->start, $state->group($other->groupId)?->code]);

        foreach ($others as $other) {
            foreach ($live as $index => $session) {
                $length = $session['end'] - $session['start'];

                foreach ([$other->end, $other->start - $length] as $start) {
                    $sessions = $live->all();
                    $sessions[$index] = [$other->day, $start, $start + $length];

                    if (($proposal = $this->proposal($sessions)) !== null) {
                        yield $proposal;
                    }
                }
            }
        }
    }

    /**
     * Una sesión del grupo a la hora de una del grupo de `$target` de la misma duración, con lo demás igual.
     *
     * @return iterable<list<array{day: int, starts_at: string, ends_at: string}>>
     */
    private function clashProposals(Group $group, Group $target): iterable
    {
        $live = $this->live($group);

        foreach ($target->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']]) as $theirs) {
            [$start, $end] = [Minutes::of((string) $theirs->starts_at), Minutes::of((string) $theirs->ends_at)];

            foreach ($live as $index => $session) {
                if ($end - $start !== $session['end'] - $session['start']) {
                    continue;
                }

                $sessions = $live->all();
                $sessions[$index] = [$theirs->day, $start, $end];

                if (($proposal = $this->proposal($sessions)) !== null) {
                    yield $proposal;
                }
            }
        }
    }

    /**
     * Las sesiones vivas del grupo en el orden de la foto de la solicitud (día y hora), en minutos.
     *
     * @return Collection<int, array{day: int, start: int, end: int}>
     */
    private function live(Group $group): Collection
    {
        return $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->values()
            ->map(fn (GroupSession $session): array => ['day' => $session->day, 'start' => Minutes::of((string) $session->starts_at), 'end' => Minutes::of((string) $session->ends_at)]);
    }

    /**
     * La propuesta como la recibe RequestChange, si cae en los días y bloques de la modalidad y sus sesiones no se empalman
     * entre sí; null si no. Una igual al horario de hoy no agrega nada, así que fits() no la escoge.
     *
     * @param  array<int, array{0: int, 1: int, 2: int}|array{day: int, start: int, end: int}>  $sessions
     * @return list<array{day: int, starts_at: string, ends_at: string}>|null
     */
    private function proposal(array $sessions): ?array
    {
        $dayStart = Minutes::of($this->profile->dayStart);
        $dayEnd = Minutes::of($this->profile->dayEnd);
        $slots = array_values(array_map(fn (array $session): array => array_values($session), $sessions));

        foreach ($slots as $i => [$day, $start, $end]) {
            if (! in_array($day, $this->profile->days, true) || $start < $dayStart || $end > $dayEnd || $end <= $start
                || ($start - $dayStart) % $this->profile->blockMinutes !== 0 || ($end - $dayStart) % $this->profile->blockMinutes !== 0) {
                return null;
            }

            foreach (array_slice($slots, 0, $i) as [$otherDay, $otherStart, $otherEnd]) {
                if ($otherDay === $day && Minutes::overlap($start, $end, $otherStart, $otherEnd)) {
                    return null;
                }
            }
        }

        return array_map(fn (array $slot): array => ['day' => $slot[0], 'starts_at' => Minutes::format($slot[1]), 'ends_at' => Minutes::format($slot[2])], $slots);
    }

    /**
     * El día del perfil que queda `$steps` lugares después (o antes) de `$day`, dando la vuelta.
     */
    private function rotate(int $day, int $steps): int
    {
        $days = array_values($this->profile->days);
        $index = array_search($day, $days, true);

        return $index === false ? $day : $days[(($index + $steps) % count($days) + count($days)) % count($days)];
    }

    /**
     * Una solicitud sin guardar con la foto de las sesiones de hoy, como la arma RequestChange, para preguntarle a
     * AssessChangeImpact qué sesiones simularía.
     *
     * @param  array<string, mixed>  $proposed
     */
    private function transient(Group $group, ChangeRequestKind $kind, array $proposed): ChangeRequest
    {
        return new ChangeRequest([
            'group_id' => $group->id,
            'kind' => $kind,
            'current' => $group->sessions->sortBy([['day', 'asc'], ['starts_at', 'asc']])->map(fn (GroupSession $session): array => [
                'day' => $session->day, 'starts_at' => substr((string) $session->starts_at, 0, 5), 'ends_at' => substr((string) $session->ends_at, 0, 5),
            ])->values()->all(),
            'proposed' => ['description' => '', ...$proposed],
        ]);
    }

    /**
     * Pide las alternativas del motor de una pendiente que no las tiene (el job corre en la cola síncrona). Retoma una
     * corrida que se cayó: un cálculo que se quedó en `computing` vuelve a correr su job, y uno fallido se pide otra vez.
     * Avisa si no queda como `$expected`, y si el motor paró por tiempo (la primera alternativa puede cambiar de una
     * máquina a otra).
     */
    private function ensureAlternatives(string $key, ?ChangeRequest $request, ChangeAlternativesStatus $expected): void
    {
        if ($request === null || ! $this->pending($request)) {
            return;
        }

        $alternatives = $request->fresh()->alternatives;

        if (ChangeAlternativesStatus::of($alternatives) === ChangeAlternativesStatus::Computing) {
            ComputeChangeAlternatives::dispatchSync($request->id, (string) $alternatives['calculation_id']);
            $alternatives = $request->fresh()->alternatives;
        }

        if (in_array(ChangeAlternativesStatus::of($alternatives), [null, ChangeAlternativesStatus::Failed], true)) {
            $this->attempt($key, fn () => app(ProposeChangeAlternatives::class)->handle($request->fresh(), $this->head));
        }

        $alternatives = $request->fresh()->alternatives;
        $status = ChangeAlternativesStatus::of($alternatives);

        if ($status !== $expected) {
            $this->command?->warn("Solicitudes de cambio: el motor dejó «{$key}» en ".($status?->value ?? 'sin pedir').': '.ChangeAlternativesStatus::messageOf($alternatives));
        }

        if (($alternatives['stop'] ?? null) === PlacementStop::TimeLimit->value) {
            $this->command?->warn("Solicitudes de cambio: el motor paró por tiempo en «{$key}»; sus alternativas pueden cambiar de una máquina a otra.");
        }
    }

    /**
     * El jefe aprueba la pendiente con la primera alternativa del motor (CR-03).
     */
    private function choose(string $key, ?ChangeRequest $request): void
    {
        if ($request === null || ! $this->pending($request) || ChangeAlternativesStatus::of($request->fresh()->alternatives) !== ChangeAlternativesStatus::Ready) {
            return;
        }

        $this->attempt($key, fn () => app(ChooseChangeAlternative::class)->handle($request->fresh(), $this->head, (string) $request->fresh()->alternatives['items'][0]['id'], self::SCENARIOS[$key]['comment']));
    }

    /**
     * Quien aplica escribe la alternativa elegida de una vez (CR-04): la carga regresa a asignación si ya no queda otra
     * aprobada y se avisa a los docentes. Retoma la corrida que se cayó entre aplicarla y regresar la carga.
     */
    private function applyAlternative(?ChangeRequest $request): void
    {
        if ($request === null) {
            return;
        }

        $request->refresh();

        if ($request->status === ChangeRequestStatus::Approved && ChangeAlternatives::chosen($request->alternatives) !== null) {
            $this->attempt('y', function () use ($request): void {
                $result = Event::fakeFor(fn () => app(ApplyChosenAlternative::class)->handle($request, $this->applier($request)), [GroupScheduleChanged::class]);
                $this->validator->forget();
                $this->warnVeto($result->vetoReasons);
            });
        }

        $approved = $this->requests()->where('status', ChangeRequestStatus::Approved)->exists();

        if ($request->fresh()->status === ChangeRequestStatus::Applied && ! $approved && $this->load->fresh()->status === LoadStatus::Adjusting) {
            $this->attempt('y', fn () => app(ReturnToStaffing::class)->handle($this->load->fresh(), $this->applier($request), 'Se aplicaron los cambios aprobados'));
        }
    }

    /**
     * Quien aplica escribe sesiones como en el editor (ReplaceGroupSessions, con GroupScheduleChanged descartado; sus
     * oyentes los hacen settleCodes() y syncConflicts()). Si ya están escritas, no cambia nada.
     *
     * @param  array<string, list<SessionCandidate>>  $schedules
     */
    private function write(array $schedules, ChangeRequest $request, string $origin): void
    {
        Event::fakeFor(fn () => app(ReplaceGroupSessions::class)->handle($schedules, $this->applier($request), $origin, ['change_request_id' => $request->id]), [GroupScheduleChanged::class]);
        $this->validator->forget();
    }

    /**
     * Lo que haría AssignCodeOnScheduleChange, descartado al escribir: la clave de los grupos que la demo movió de hora
     * sigue a su hora de inicio más temprana. Los saca de la base (las aplicadas o aprobadas de horario con lo que pedían
     * y los grupos de las alternativas elegidas que ya se escribieron), así también lo hace al retomar; AssignGroupCode
     * no escribe si la clave no cambia. Va después de cada fase que escribe y antes de calcular o elegir alternativas: la
     * clave toca el `updated_at` del grupo, que entra en la huella de las alternativas.
     */
    private function settleCodes(): void
    {
        $byHand = array_column(array_filter(self::SCENARIOS, fn (array $scenario): bool => $scenario['target'] === self::APPLIED_BY_HAND), 'reason');
        $ids = [];

        foreach ($this->requests()->whereIn('status', [ChangeRequestStatus::Approved, ChangeRequestStatus::Applied])->whereIn('reason', array_column(self::SCENARIOS, 'reason'))->get() as $request) {
            $chosen = ChangeAlternatives::chosen($request->alternatives);

            if ($chosen !== null && (isset($request->alternatives['applied']) || in_array($request->reason, $byHand, true))) {
                array_push($ids, ...array_column($chosen['groups'], 'group_id'));
            } elseif ($chosen === null && $request->kind === ChangeRequestKind::Schedule && AssessChangeImpact::isAssessable($request)) {
                $ids[] = $request->group_id;
            }
        }

        foreach (Group::query()->whereKey(array_unique($ids))->orderBy('id')->get() as $group) {
            app(AssignGroupCode::class)->handle($group);
        }

        $this->validator->forget();
        $this->existing = [];
    }

    /**
     * Lo que haría QueueConflictRecompute, descartado al escribir: los choques de la modalidad, una vez, si el último
     * recálculo (SyncScheduleConflicts::stampKey) no es posterior a la última escritura en los grupos de la carga o en sus
     * sesiones. Así una corrida que se cayó antes de recalcular lo hace al retomar, y una que no escribió nada no lo repite.
     */
    private function syncConflicts(Term $term, Modality $modality): void
    {
        $groups = Group::query()->where('term_id', $this->load->term_id)->where('modality_id', $this->load->modality_id)->where('department_id', $this->load->department_id);
        $written = collect([(clone $groups)->max('updated_at'), GroupSession::query()->whereIn('group_id', (clone $groups)->select('id'))->max('updated_at')])->filter()->max();
        $stamp = Cache::get(SyncScheduleConflicts::stampKey($term->id, $modality->id));

        // Las fechas de la base son al segundo, redondeadas: un segundo de margen.
        if ($written !== null && $stamp !== null && CarbonImmutable::parse($stamp)->greaterThanOrEqualTo(CarbonImmutable::parse($written)->subSecond())) {
            return;
        }

        $this->validator->forget();
        app(SyncScheduleConflicts::class)->handle($term, $modality);
    }

    /**
     * Modo `showcase` (la demo mezclada): una pendiente de horario con choque duro y sus alternativas listas, pedida por
     * el docente de un grupo de la carga en asignación o ajustes con más pendientes. Se puede volver a correr: si ya está,
     * solo termina sus alternativas.
     */
    private function showcase(Term $term, Modality $modality): void
    {
        $pending = ChangeRequest::query()->where('term_id', $term->id)->where('modality_id', $modality->id)->where('status', ChangeRequestStatus::Pending)
            ->selectRaw('department_id, count(*) as total')->groupBy('department_id')->pluck('total', 'department_id');
        $load = AcademicLoad::query()->with(['department', 'modality', 'term'])->where('term_id', $term->id)->where('modality_id', $modality->id)
            ->whereIn('status', [LoadStatus::Staffing, LoadStatus::Adjusting])->get()
            ->sortBy(fn (AcademicLoad $load): array => [-($pending[$load->department_id] ?? 0), (string) $load->department?->code])->first();

        if ($load === null) {
            return;
        }

        $this->prepare($load, $modality);
        $scenario = [...self::SCENARIOS['p4'], 'reason' => self::SHOWCASE_REASON, 'campus' => 'last'];
        $request = $this->ensureRequested('showcase', $scenario);
        $this->ensureAlternatives('showcase', $request, ChangeAlternativesStatus::Ready);

        if ($request !== null) {
            $this->command?->info("Solicitud de cambio con alternativas del motor en {$load->department->code}: el grupo {$request->group->code}.");
        }
    }

    private function prepare(AcademicLoad $load, Modality $modality): void
    {
        $this->load = $load->loadMissing(['department', 'modality', 'term']);
        $this->head = ReanalysisDemoSeeder::headOf($load);
        $this->profile = $modality->valid_profile ?? ModalityProfile::fromArray([]);
        $this->campuses = $this->groups()->map(fn (Group $group): string => $group->campus->code)->unique()->sort()->values()->all() ?: ['—'];
        $this->travellers = array_fill_keys(Group::query()->active()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->whereNotNull('teacher_id')
            ->whereIn('teacher_id', Group::query()->active()->where('term_id', $load->term_id)->where('department_id', $load->department_id)->select('teacher_id'))
            ->groupBy('teacher_id')->havingRaw('count(distinct campus_id) > 1')->pluck('teacher_id')->all(), true);
        $this->existing = [];
        $this->validator->forget();
    }

    /**
     * @return Builder<ChangeRequest>
     */
    private function requests(): Builder
    {
        return ChangeRequest::query()->where('term_id', $this->load->term_id)->where('modality_id', $this->load->modality_id)->where('department_id', $this->load->department_id);
    }

    /**
     * Los grupos activos de la carga, con su docente, campus y sesiones.
     *
     * @return Collection<int, Group>
     */
    private function groups(): Collection
    {
        return Group::query()->active()
            ->where('term_id', $this->load->term_id)->where('modality_id', $this->load->modality_id)->where('department_id', $this->load->department_id)
            ->with(['teacher.user', 'subject:id,code', 'campus:id,code', 'sessions'])
            ->get();
    }

    private function pending(ChangeRequest $request): bool
    {
        return $request->fresh()->status === ChangeRequestStatus::Pending;
    }

    /**
     * Quien aplica según el tipo: la de horario, la coordinación de la carrera dueña con nombramiento en el campus del
     * grupo (MS6; el admin si no hay); la de salón, el jefe del departamento.
     */
    private function applier(ChangeRequest $request): User
    {
        $request->loadMissing('group');

        return $request->kind === ChangeRequestKind::Schedule
            ? $this->scheduleApplier($request->group) ?? User::query()->where('username', 'admin')->firstOrFail()
            : $this->head;
    }

    private function scheduleApplier(Group $group): ?User
    {
        return $this->holder(StaffingPermission::ChangeRequestApplySchedule, $group->campus_id, $group->owner_career_id);
    }

    private function holder(StaffingPermission $permission, string $campusId, ?string $careerId): ?User
    {
        $key = "{$permission->value}|{$campusId}|{$careerId}";

        if (! array_key_exists($key, $this->holders)) {
            $campus = in_array(Dimension::Campus, $permission->dimensions(), true) ? $campusId : null;
            $this->holders[$key] = $this->recipients->holders($permission, Target::of(campus: $campus, career: $careerId, modality: $this->load->modality_id))[0]['user'] ?? null;
        }

        return $this->holders[$key];
    }

    /**
     * Pide alternativas con la cola retenida: ComputeChangeAlternatives no se despacha (como con el worker ocupado), así
     * que el cálculo se queda en `computing`.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function holdingQueue(Closure $work): mixed
    {
        $dispatcher = Bus::getFacadeRoot();
        Bus::fake([ComputeChangeAlternatives::class]);

        try {
            return $work();
        } finally {
            Bus::swap($dispatcher);
        }
    }

    /**
     * Corre un paso de un escenario; si la Action lo rechaza (ValidationException), lo avisa y sigue.
     */
    private function attempt(string $key, callable $step): void
    {
        try {
            $step();
        } catch (ValidationException $exception) {
            $this->command?->warn("Solicitudes de cambio: «{$key}» se quedó a medias: ".collect($exception->errors())->flatten()->first());
        }
    }

    /**
     * @param  list<string>  $reasons
     */
    private function warnVeto(array $reasons): void
    {
        if ($reasons !== []) {
            $this->command?->warn('Solicitudes de cambio: la carga se queda en ajustes: '.implode(' ', $reasons));
        }
    }

    /**
     * «A, B y C».
     *
     * @param  list<string>  $items
     */
    private static function join(array $items): string
    {
        return count($items) <= 1 ? implode('', $items) : implode(', ', array_slice($items, 0, -1)).' y '.end($items);
    }
}
