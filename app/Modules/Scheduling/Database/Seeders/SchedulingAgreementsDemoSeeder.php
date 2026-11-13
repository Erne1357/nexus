<?php

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\ProposeGroupQuota;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Actions\Sessions\SyncSessionLoans;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Support\RoomSupply;
use App\Modules\Scheduling\Validation\State\StateGroup;
use App\Modules\Scheduling\Validation\State\StateRoom;
use App\Modules\Scheduling\Validation\State\StateSession;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Acuerdos entre departamentos de prueba (tareas 3.6 y 3.7): préstamos de salón, grupos compartidos, cupos directos y
 * salones sugeridos, para que el editor de horarios, el cajón de cupos y salones, la bandeja «Acuerdos» y la campana
 * se vean llenos con todos los casos. Corre después de SchedulingDemoSeeder. Todo pasa por las Actions reales
 * (AssignSessionRoom, AcceptRoomLoan, RejectRoomLoan, CancelRoomLoan, SetGroupQuotas, ProposeGroupQuota,
 * AcceptSharedGroup, RejectSharedGroup, WithdrawGroupQuotaProposal) y por el jefe que corresponde en cada paso (quien
 * pide, quien decide), para que la bitácora y los avisos digan quién fue; sin jefe en el departamento, la pide `admin`.
 * La cola va en `sync` mientras siembra (los avisos salen en el momento) y los eventos GroupScheduleChanged y
 * GroupQuotasChanged se descartan; al final corre SyncScheduleConflicts una vez por modalidad (M1).
 *
 * Por campus, solo en grupos editables (no cancelados, con la carga del departamento en proposed, staffing o
 * adjusting) y sin tocar los departamentos limpios de SchedulingDemoSeeder (sin salones ajenos ni choques; sus cupos
 * sí se reparten, respetando HC-07):
 *
 * 1. **Préstamos (HC-10).** Las sesiones que SchedulingDemoSeeder dejó en un salón de otro departamento sin préstamo
 *    reciben su préstamo con SyncSessionLoans, lo mismo que hace AssignSessionRoom después de asignar (el validador de
 *    cada asignación tarda segundos con un campus lleno, así que no se reasignan una por una). Si faltan para `loans`
 *    (16 con el perfil demo, 60 con el massive), se agregan con AssignSessionRoom sesiones en un salón ajeno libre, del tipo y capacidad
 *    correctos, prefiriendo los salones de departamentos que también tienen grupos editables y las parejas de
 *    departamentos menos usadas, para que haya préstamos entre varias parejas y cada departamento pida y reciba (en
 *    el demo solo hay un departamento abierto además de los limpios, así que casi todos salen de él). Luego se reparten: 40 % aceptados, 30 %
 *    pendientes, 15 % rechazados con motivo y 15 % cancelados. Los primeros pendientes de cada departamento (uno que
 *    pidió, uno que recibe) se reservan para que cada bandeja tenga «Por decidir» y «Enviados». Uno que no se pueda
 *    aceptar (HC-02, HC-11) se queda pendiente.
 * 2. **Grupos compartidos (HC-07).** Materias de tronco común (la misma materia en el plan de carreras de otros
 *    departamentos ofrecidas en el campus; si no alcanzan, cualquier carrera ofrecida de otro departamento): el
 *    jefe del departamento que imparte baja el cupo de la carrera dueña con SetGroupQuotas y propone con
 *    ProposeGroupQuota hasta `shared` acuerdos (24 con el demo, 120 con el massive): 40 % aceptados por el jefe de la
 *    carrera, 30 % pendientes, 20 % rechazados con motivo y 10 % retirados. En `direct_quota_groups` grupos más se
 *    reparte un cupo directo con una carrera del mismo departamento que imparte.
 * 3. **Salones sugeridos.** En `suggested_rooms` (10 %, con tope `suggested_max`: 10 con el demo, 40 con el massive, por el costo del
 *    validador) de las sesiones sin salón, el primer salón libre de
 *    RoomSuggestionQuery con AssignSessionRoom; se saltan las sesiones de los grupos con un préstamo rechazado o
 *    cancelado, para que sigan sin salón.
 *
 * Con `$mode = 'quotas'` (el paso de Scheduling que lleva al punto 5 «asignacion» de `demo:seed`) solo corre el paso 2,
 * en todos los departamentos editables y con todos los grupos compartidos aceptados: los cupos quedan decididos, sin
 * acuerdos pendientes, sin préstamos ni salones. Lo que una Action rechace lo detiene con el motivo, en vez de dejar el
 * punto a medias.
 *
 * Con `$mode = 'rooms'` (el paso de Scheduling que lleva al punto 7 «cierre») toda sesión presencial de los grupos
 * activos de departamentos editables recibe un salón de RoomSupply, sin crear HC-02, HC-05, HC-06 ni HC-11: primero
 * las prácticas con laboratorio, que tienen menos salones, y en cada una el salón por defecto de la materia si sirve.
 * Si alguna sesión no tiene salón posible, se detiene antes de escribir con cuántas son y ejemplos (el horario no se
 * repara aquí). La primera sesión de cada departamento pasa por AssignSessionRoom con su jefe, para que la bitácora y
 * el editor tengan una asignación real (el validador de cada una tarda segundos con un campus lleno); las demás se
 * escriben en bloque. Los salones de otro departamento piden su préstamo con SyncSessionLoans y el jefe dueño lo
 * acepta con AcceptRoomLoan (HC-10). Un campus que no usa salones se salta.
 *
 * Determinista con `$seed` sobre los mismos datos. Idempotente: si el periodo y el campus ya tienen acuerdos (con
 * `quotas`, grupos compartidos), no hace nada; con `rooms`, solo completa las sesiones sin salón y los préstamos sin
 * aceptar. Se niega a correr en producción.
 */
final class SchedulingAgreementsDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const array LOANS = ['demo' => 16, 'massive' => 60];

    private const array SHARED = ['demo' => 24, 'massive' => 120];

    private const array SUGGESTED_MAX = ['demo' => 10, 'massive' => 40];

    private const array DIRECT_GROUPS = ['demo' => 4, 'massive' => 25];

    private const array CLEAN_DEPARTMENTS = ['demo' => 3, 'massive' => 4];

    /** La demo mezclada de siempre, solo los cupos completos del punto 5 o solo los salones completos del punto 7. */
    public const array MODES = ['mixed', 'quotas', 'rooms'];

    private const array LOAN_REJECTIONS = [
        'El salón está reservado para prácticas de nuestros propios grupos en ese horario.',
        'Ese horario lo tenemos apartado para exámenes y asesorías del departamento.',
        'El equipo del salón está en mantenimiento; no podemos prestarlo este periodo.',
        'Ya comprometimos el salón con otro departamento en esas horas.',
        'Pidan un aula de su edificio: el laboratorio no se presta para teoría.',
    ];

    private const array SHARED_REJECTIONS = [
        'Nuestros alumnos ya están acomodados en otros grupos de esta materia.',
        'El horario del grupo choca con la jornada de la carrera; no podemos aceptar.',
        'No hay alumnos suficientes para ocupar esos lugares este periodo.',
        'La materia la cubrimos con un grupo propio; gracias.',
    ];

    /** @var array<string, float|int> */
    private array $ratios;

    private Randomizer $random;

    private User $admin;

    /** @var array<string, User> `permiso|campus|departamento` => jefe */
    private array $heads = [];

    /** @var array<string, int> */
    private array $summary = [];

    private SyncSessionLoans $loans;

    /** @var array<string, int> intentos que una Action rechazó por sus reglas, por fase, en el campus en curso */
    private array $rejections = [];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  array<string, float|int>  $ratios  sobrescribe `loans`, `shared`, `direct_quota_groups` y `suggested_rooms`
     * @param  array<string, list<string>>|null  $cleanDepartments  códigos de departamento limpios por campus, de
     *                                                              SchedulingDemoSeeder::cleanDepartments(); sin ellos se deducen igual que ese seeder
     * @param  string  $mode  uno de MODES
     *
     * @throws RuntimeException en producción, si el periodo no existe o, con `quotas` o `rooms`, si algo no se puede completar
     * @throws InvalidArgumentException con un modo desconocido
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027, array $ratios = [], ?array $cleanDepartments = null, string $mode = 'mixed'): void
    {
        $this->refuseProduction();

        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Modo desconocido «{$mode}»: usa ".implode(', ', self::MODES).'.');
        }

        $this->random = new Randomizer(new Mt19937($seed));
        $this->ratios = [
            'loans' => self::LOANS[$profile] ?? self::LOANS['demo'],
            'shared' => self::SHARED[$profile] ?? self::SHARED['demo'],
            'direct_quota_groups' => self::DIRECT_GROUPS[$profile] ?? self::DIRECT_GROUPS['demo'],
            'suggested_rooms' => 0.10,
            'suggested_max' => self::SUGGESTED_MAX[$profile] ?? self::SUGGESTED_MAX['demo'],
            ...$ratios,
        ];
        $this->heads = [];
        $this->loans = app(SyncSessionLoans::class);

        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $this->admin = User::query()->where('username', 'admin')->first() ?? User::query()->first()
            ?? throw new RuntimeException('Hace falta un usuario para la bitácora: corre antes AdminUserSeeder.');

        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            Event::fakeFor(function () use ($found, $termModel, $profile, $cleanDepartments, $mode): void {
                foreach ($found as $campus) {
                    match ($mode) {
                        'quotas' => $this->seedCampusQuotas($termModel, $campus),
                        'rooms' => $this->seedCampusRooms($termModel, $campus),
                        default => $this->seedCampus($termModel, $campus, $profile, $cleanDepartments[$campus->code] ?? null),
                    };
                }
            }, [GroupScheduleChanged::class, GroupQuotasChanged::class]);
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $modalities = Modality::query()->withTrashed()
            ->whereIn('id', Group::query()->where('term_id', $termModel->id)->whereIn('campus_id', $found->modelKeys())->select('modality_id'))
            ->orderBy('code')->get();

        foreach ($modalities as $modality) {
            app(SyncScheduleConflicts::class)->handle($termModel, $modality);
        }
    }

    private function seedCampus(Term $term, Campus $campus, string $profile, ?array $cleanCodes): void
    {
        $already = InterdepartmentalAgreement::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->exists();
        $groups = Group::query()->with('subject')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->get()->sortBy(fn (Group $group): string => self::groupKey($group))->values();

        if ($already || $groups->isEmpty()) {
            $this->command?->info("Acuerdos en {$campus->code}: ".($already ? 'ya existen, no se hace nada.' : 'sin grupos.'));

            return;
        }

        $this->rejections = [];
        $clean = $this->cleanIds($groups, $cleanCodes, $profile);
        $editable = $this->editableDepartments($term, $campus);
        $usable = $groups->filter(fn (Group $group): bool => $group->status !== GroupStatus::Cancelled && isset($editable[$group->department_id]))->values();
        $open = $usable->reject(fn (Group $group): bool => isset($clean[$group->department_id]))->keyBy('id');

        $loanGroups = $this->seedLoans($term, $campus, $open);
        $this->seedSharedGroups($term, $campus, $usable);
        $suggested = $this->seedSuggestedRooms($campus, $open, $loanGroups);

        $byStatus = fn (AgreementKind $kind): string => InterdepartmentalAgreement::query()
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('kind', $kind->value)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn (int $total, string $status): string => "{$total} ".AgreementStatus::from($status)->value)->implode(', ');

        $this->command?->info("Acuerdos en {$campus->code}: préstamos de salón [".$byStatus(AgreementKind::RoomLoan).'], grupos compartidos ['.$byStatus(AgreementKind::SharedGroup)."], {$this->summary['direct']} grupos con cupo directo, {$suggested} salones sugeridos.");

        if ($this->rejections !== []) {
            $this->command?->info("Acuerdos en {$campus->code}: intentos rechazados por las reglas [".collect($this->rejections)->map(fn (int $total, string $phase): string => "{$phase}: {$total}")->implode(', ').'].');
        }
    }

    /**
     * Los cupos completos del punto 5: grupos compartidos, todos aceptados, y cupos directos en todos los departamentos
     * editables.
     *
     * @throws RuntimeException si el campus no tiene grupos editables o una Action rechaza un paso
     */
    private function seedCampusQuotas(Term $term, Campus $campus): void
    {
        $already = InterdepartmentalAgreement::query()->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::SharedGroup->value)->exists();

        if ($already) {
            $this->command?->info("Cupos en {$campus->code}: ya hay grupos compartidos, no se hace nada.");

            return;
        }

        $editable = $this->editableDepartments($term, $campus);
        $usable = Group::query()->with('subject')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)->where('status', '!=', GroupStatus::Cancelled->value)
            ->whereIn('department_id', array_keys($editable))
            ->get()->sortBy(fn (Group $group): string => self::groupKey($group))->values();

        if ($usable->isEmpty()) {
            throw new RuntimeException("{$campus->code} no tiene grupos editables: hace falta llegar antes al punto 4 (propuestas aceptadas).");
        }

        $accepted = $this->seedSharedGroups($term, $campus, $usable, complete: true);

        $this->command?->info("Cupos en {$campus->code}: {$accepted} grupos compartidos aceptados, {$this->summary['direct']} grupos con cupo directo.");
    }

    /**
     * Los salones completos del punto 7: un salón para cada sesión presencial sin salón y sus préstamos aceptados.
     *
     * @throws RuntimeException si alguna sesión no tiene salón posible o una Action rechaza un paso
     */
    private function seedCampusRooms(Term $term, Campus $campus): void
    {
        $modalities = $this->roomModalities($term, $campus);

        if ($modalities->isEmpty()) {
            $this->command?->info("Salones en {$campus->code}: ninguna modalidad con grupos aquí usa salones, no se hace nada.");

            return;
        }

        foreach ($modalities as $modality) {
            $this->seedModalityRooms($term, $campus, $modality);
        }
    }

    /**
     * Las modalidades con grupos en el campus que usan salones (su perfil), por clave.
     *
     * @return EloquentCollection<int, Modality>
     */
    private function roomModalities(Term $term, Campus $campus): EloquentCollection
    {
        return Modality::query()->withTrashed()
            ->whereIn('id', Group::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->select('modality_id'))
            ->orderBy('code')->get()
            ->filter(fn (Modality $modality): bool => $modality->profile->usesRooms)
            ->values();
    }

    /**
     * El paso de salones de una modalidad en el campus. Las modalidades van una tras otra: la foto de la siguiente ya
     * trae los salones que esta asignó (ScheduleStateQuery carga los grupos con sesión en salón de toda modalidad).
     *
     * @throws RuntimeException si alguna sesión no tiene salón posible o una Action rechaza un paso
     */
    private function seedModalityRooms(Term $term, Campus $campus, Modality $modality): void
    {
        ['plan' => $plan, 'missing' => $missing] = $this->roomPlan($term, $campus, $modality);

        if ($missing !== []) {
            throw new RuntimeException(count($missing)." sesiones de {$campus->code} no tienen un salón libre que les sirva sin choque (HC-02, HC-05, HC-06, HC-11); por ejemplo: ".implode('; ', array_slice($missing, 0, 5)).'.');
        }
        $first = [];
        $bulk = [];

        foreach ($plan as $item) {
            isset($first[$item['group']->departmentId]) ? $bulk[] = $item : $first[$item['group']->departmentId] = $item;
        }

        $rooms = Room::query()->whereIn('id', array_unique(array_map(fn (array $item): string => $item['room']->id, $plan)))->get()->keyBy('id');

        foreach ($first as $departmentId => $item) {
            $this->orFail(fn () => app(AssignSessionRoom::class)->handle(
                GroupSession::query()->findOrFail($item['session']->id),
                $rooms[$item['room']->id],
                $this->head(AcademicLoadPermission::AgreementRequest, $campus, $departmentId),
            ), "El salón de {$item['label']} no se pudo asignar");
        }

        $table = (new GroupSession)->getTable();

        foreach (array_chunk($bulk, 500) as $chunk) {
            DB::update(
                "update {$table} as gs set room_id = v.room_id::uuid, updated_at = now() from (values ".implode(', ', array_fill(0, count($chunk), '(?, ?)')).') as v(id, room_id) where gs.id = v.id::uuid',
                array_merge(...array_map(fn (array $item): array => [$item['session']->id, $item['room']->id], $chunk)),
            );
        }

        $groups = Group::query()->whereIn('id', array_unique(array_map(fn (array $item): string => $item['group']->id, $bulk)))->get()->keyBy('id');
        $loanKeys = [];

        foreach ($bulk as $item) {
            $room = $item['room'];

            if ($room->departmentId !== null && $room->departmentId !== $item['group']->departmentId) {
                $loanKeys["{$item['group']->id}|{$room->id}|{$item['session']->day}"] = $item;
            }
        }

        foreach ($loanKeys as $item) {
            $this->orFail(fn () => $this->loans->resync(
                $groups[$item['group']->id], $rooms[$item['room']->id], $item['session']->day,
                $this->head(AcademicLoadPermission::AgreementRequest, $campus, $item['group']->departmentId),
            ), "El préstamo del salón {$item['room']->code} para {$item['label']} no se pudo pedir");
        }

        $accepted = 0;

        InterdepartmentalAgreement::query()
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::RoomLoan->value)->where('status', AgreementStatus::Proposed->value)
            ->orderBy('created_at')->orderBy('id')
            ->each(function (InterdepartmentalAgreement $loan) use ($campus, &$accepted): void {
                $this->orFail(fn () => app(AcceptRoomLoan::class)->handle($loan, $this->head(AcademicLoadPermission::AgreementDecide, $campus, $loan->to_department_id)), 'Un préstamo de salón no se pudo aceptar');
                $accepted++;
            });

        $this->command?->info("Salones en {$campus->code}: ".count($plan).' sesiones con salón ('.count($first)." con AssignSessionRoom), {$accepted} préstamos aceptados.");
    }

    /**
     * Qué salón le toca a cada sesión presencial sin salón de los grupos activos de departamentos editables del campus,
     * apartado en RoomSupply: primero las prácticas con laboratorio, luego por día, hora de inicio, la más larga
     * primero y el grupo; el salón por defecto de la materia va primero si sirve, y los del departamento del grupo o
     * sin dueño antes que los que piden préstamo. Si con esas preferencias a alguna sesión no le toca salón, reparte
     * de nuevo solo por el más justo.
     *
     * No escribe nada.
     *
     * @return array{plan: list<array{session: StateSession, group: StateGroup, room: StateRoom, label: string}>, missing: list<string>}
     */
    private function roomPlan(Term $term, Campus $campus, Modality $modality): array
    {
        $editable = $this->editableDepartments($term, $campus);
        $state = app(ScheduleStateQuery::class)->forModality($term, $modality->id);
        $defaults = SubjectRoomDefault::query()->where('campus_id', $campus->id)->orderBy('priority')->orderBy('room_id')->get(['subject_id', 'room_id'])
            ->groupBy('subject_id')->map(fn (Collection $rows): array => $rows->pluck('room_id')->all())->all();
        $pending = [];

        foreach ($state->groups() as $group) {
            if (! $group->isActive || $group->campusId !== $campus->id || $group->modalityId !== $modality->id || ! isset($editable[$group->departmentId])) {
                continue;
            }

            $needsLab = $state->subject($group->subjectId)?->needsLab ?? false;

            foreach ($state->sessionsOf($group->id) as $session) {
                if ($session->roomId === null && $session->kind !== SessionKind::Virtual) {
                    $pending[] = ['session' => $session, 'group' => $group, 'practice' => $needsLab && $session->kind === SessionKind::Practica];
                }
            }
        }

        $order = fn (array $item): array => [$item['practice'] ? 0 : 1, $item['session']->day, $item['session']->start, -$item['session']->end, $item['group']->code, (string) $item['session']->id];
        usort($pending, fn (array $a, array $b): int => $order($a) <=> $order($b));

        // Con preferencias (salón por defecto, propio antes que préstamo) el reparto se ve como en el uso normal; si así
        // no alcanza, se reparte otra vez solo por el salón más justo antes de rendirse.
        foreach ([true, false] as $preferences) {
            $supply = RoomSupply::fromState($state, $campus->id);
            $plan = [];
            $missing = [];

            foreach ($pending as ['session' => $session, 'group' => $group, 'practice' => $practice]) {
                $label = "{$state->labelOf($group->id)} el {$session->describe()}";
                $room = $preferences
                    ? $supply->reserve($session->day, $session->start, $session->end, $group->capacity, $practice, $group->departmentId, $defaults[$group->subjectId] ?? [])
                    : $supply->reserve($session->day, $session->start, $session->end, $group->capacity, $practice);
                $room === null ? $missing[] = $label : $plan[] = ['session' => $session, 'group' => $group, 'room' => $room, 'label' => $label];
            }

            if ($missing === []) {
                break;
            }
        }

        return ['plan' => $plan, 'missing' => $missing];
    }

    /**
     * Las sesiones presenciales sin salón del campus a las que el paso de salones (modo `rooms`) no podría darles uno
     * sin choque, descritas («Cálculo Integral, grupo A3 el lunes de 7:00 a 9:00»). La usa la revisión del punto 5 de
     * la demo, «la oferta de salones alcanza para el horario»; solo de las modalidades que usan salones. No escribe nada.
     *
     * @return list<string>
     */
    public function missingRooms(Term $term, Campus $campus): array
    {
        return $this->roomModalities($term, $campus)
            ->flatMap(fn (Modality $modality): array => $this->roomPlan($term, $campus, $modality)['missing'])
            ->all();
    }

    /**
     * Corre una Action y, si la rechaza, detiene el seeder con sus motivos.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws RuntimeException
     */
    private function orFail(Closure $work, string $what): mixed
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            throw new RuntimeException("{$what}: ".implode(' ', Arr::flatten($exception->errors())), previous: $exception);
        }
    }

    /** Cuenta un intento que una Action rechazó (ValidationException); se informa al final del campus. */
    private function rejected(string $phase): void
    {
        $this->rejections[$phase] = ($this->rejections[$phase] ?? 0) + 1;
    }

    /** Llave natural de un grupo (materia, grupo y estado) para que la misma semilla dé la misma demo aunque cambien los UUID. */
    private static function groupKey(Group $group): string
    {
        return "{$group->subject?->code}|{$group->code}|{$group->status->value}";
    }

    /**
     * Las sesiones en orden natural: grupo, día y hora.
     *
     * @param  Collection<int, GroupSession>  $sessions
     * @param  Collection<string, Group>  $open
     * @return Collection<int, GroupSession>
     */
    private function sessionsInOrder(Collection $sessions, Collection $open): Collection
    {
        return $sessions->sortBy(fn (GroupSession $session): string => self::groupKey($open[$session->group_id]).'|'.$session->day.'|'.$session->starts_at)->values();
    }

    /**
     * @param  list<string>|null  $codes
     * @return array<string, true> ids de los departamentos limpios
     */
    private function cleanIds($groups, ?array $codes, string $profile): array
    {
        if ($codes !== null) {
            return array_fill_keys(Department::query()->withTrashed()->whereIn('code', $codes)->pluck('id')->all(), true);
        }

        $counts = $groups->countBy('department_id')->all();
        $names = Department::query()->withTrashed()->whereIn('id', array_keys($counts))->pluck('code', 'id')->all();
        $wanted = min((int) (self::CLEAN_DEPARTMENTS[$profile] ?? self::CLEAN_DEPARTMENTS['demo']), count($counts) - 1);

        uksort($counts, fn (string $a, string $b): int => [$counts[$b], $names[$a] ?? $a] <=> [$counts[$a], $names[$b] ?? $b]);

        return array_fill_keys(array_slice(array_keys($counts), 0, max(0, $wanted)), true);
    }

    /**
     * @return array<string, true> departamentos con la carga en proposed, staffing o adjusting
     */
    private function editableDepartments(Term $term, Campus $campus): array
    {
        $open = [LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting];

        return AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->get(['department_id', 'status'])
            ->filter(fn (AcademicLoad $load): bool => in_array($load->status, $open, true))
            ->mapWithKeys(fn (AcademicLoad $load): array => [$load->department_id => true])->all();
    }

    /**
     * El jefe con `$permission` en el departamento y campus; sin él, `admin`.
     */
    private function head(AcademicLoadPermission $permission, Campus $campus, string $departmentId): User
    {
        $key = "{$permission->value}|{$campus->id}|{$departmentId}";

        return $this->heads[$key] ??= app(RecipientsQuery::class)->holders($permission, Target::of(modality: $campus->modality_id, campus: in_array(Dimension::Campus, $permission->dimensions(), true) ? $campus->id : null, unit: $departmentId))[0]['user'] ?? $this->admin;
    }

    // ---------------------------------------------------------------- préstamos

    /**
     * @param  Collection<string, Group>  $open  grupos editables de departamentos no limpios, por id
     * @return array<string, true> ids de los grupos cuyo préstamo se rechazó o canceló
     */
    private function seedLoans(Term $term, Campus $campus, $open): array
    {
        $rooms = Room::query()->where('campus_id', $campus->id)->where('is_active', true)->get()->keyBy('id');

        $foreign = $this->sessionsInOrder(GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)->whereNotNull('room_id')->get(), $open)
            ->filter(fn (GroupSession $session): bool => ($room = $rooms->get($session->room_id)) !== null && $room->department_id !== null && $room->department_id !== $open[$session->group_id]->department_id);

        $seen = [];

        foreach ($foreign as $session) {
            $key = "{$session->group_id}|{$session->room_id}|{$session->day}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $group = $open[$session->group_id];

            try {
                $this->loans->resync($group, $rooms[$session->room_id], $session->day, $this->head(AcademicLoadPermission::AgreementRequest, $campus, $group->department_id));
            } catch (ValidationException) {
                $this->rejected('préstamos');

                continue;
            }
        }

        $created = InterdepartmentalAgreement::query()->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::RoomLoan->value)->count();
        $created += $this->addLoans(max(0, (int) $this->ratios['loans'] - $created), $campus, $open, $rooms);

        return $this->decideLoans($term, $campus, $open);
    }

    /**
     * Asigna un salón ajeno libre a sesiones (primero las que ya tienen un salón propio o sin dueño, luego las que no tienen) hasta pedir `$wanted` préstamos más.
     *
     * @param  Collection<string, Group>  $open
     * @param  Collection<string, Room>  $rooms
     */
    private function addLoans(int $wanted, Campus $campus, $open, $rooms): int
    {
        if ($wanted === 0) {
            return 0;
        }

        $codes = Department::query()->withTrashed()->whereIn('id', $rooms->pluck('department_id')->filter()->unique())->pluck('code', 'id')->all();
        $openDepartments = array_flip($open->pluck('department_id')->unique()->all());
        $pairUse = [];

        $candidates = $this->sessionsInOrder(GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)->get(), $open)
            ->reject(fn (GroupSession $session): bool => $session->room_id !== null && ($rooms->get($session->room_id)?->department_id ?? $open[$session->group_id]->department_id) !== $open[$session->group_id]->department_id);
        $candidates = collect($this->random->shuffleArray($candidates->all()))
            ->sortBy(fn (GroupSession $session): int => $session->room_id === null ? 1 : 0)->values();

        $assign = app(AssignSessionRoom::class);
        $suggest = app(RoomSuggestionQuery::class);
        $done = 0;
        $attempts = 0;

        foreach ($candidates as $session) {
            if ($done >= $wanted || $attempts++ >= $wanted * 8) {
                break;
            }

            $group = $open[$session->group_id];
            $options = collect($suggest->for($session, 1000)['rooms'])
                ->filter(fn (array $row): bool => $row['owner'] !== null && $rooms->get($row['id'])?->department_id !== $group->department_id
                    && $row['selectable'] && ! $row['state']['tooSmall'] && ! $row['state']['wrongType'] && $row['state']['blocked'] === null
                    && ! $row['state']['pending'] && ! $row['state']['covered'])
                ->sortBy(fn (array $row): array => [
                    isset($openDepartments[$rooms[$row['id']]->department_id]) ? 0 : 1,
                    $pairUse["{$group->department_id}|{$rooms[$row['id']]->department_id}"] ?? 0,
                ])
                ->values();

            foreach ($options->take(3) as $row) {
                try {
                    $assign->handle($session, $rooms[$row['id']], $this->head(AcademicLoadPermission::AgreementRequest, $campus, $group->department_id));
                    $done++;
                    $pair = "{$group->department_id}|{$rooms[$row['id']]->department_id}";
                    $pairUse[$pair] = ($pairUse[$pair] ?? 0) + 1;

                    break;
                } catch (ValidationException) {
                    $this->rejected('préstamos');

                    continue;
                }
            }
        }

        return $done;
    }

    /**
     * Reparte los préstamos propuestos en aceptados, rechazados y cancelados; el resto se queda pendiente.
     *
     * @param  Collection<string, Group>  $open
     * @return array<string, true> grupos con un préstamo rechazado o cancelado
     */
    private function decideLoans(Term $term, Campus $campus, Collection $open): array
    {
        $loans = InterdepartmentalAgreement::query()
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::RoomLoan->value)->where('status', AgreementStatus::Proposed->value)
            ->with('room')->get()
            ->sortBy(fn (InterdepartmentalAgreement $loan): string => (isset($open[$loan->group_id]) ? self::groupKey($open[$loan->group_id]) : $loan->group_id).'|'.$loan->day.'|'.$loan->starts_at.'|'.$loan->room?->code)->values();

        $decisions = $this->decisions($loans->all(), 0.40, 0.15, 0.15);
        $touched = [];

        foreach ($loans as $index => $loan) {
            $decision = $decisions[$index];
            $owner = $this->head(AcademicLoadPermission::AgreementDecide, $campus, $loan->to_department_id);
            $requester = $this->head(AcademicLoadPermission::AgreementRequest, $campus, $loan->from_department_id);

            try {
                match ($decision) {
                    'accepted' => app(AcceptRoomLoan::class)->handle($loan, $owner),
                    'rejected' => app(RejectRoomLoan::class)->handle($loan, $this->pick(self::LOAN_REJECTIONS), $owner),
                    'cancelled' => app(CancelRoomLoan::class)->handle($loan, $requester),
                    default => null,
                };
            } catch (ValidationException) {
                $this->rejected('decisiones de préstamos');

                continue;
            }

            if (in_array($decision, ['rejected', 'cancelled'], true)) {
                $touched[$loan->group_id] = true;
            }
        }

        return $touched;
    }

    /**
     * Una decisión por acuerdo, en el orden de `$agreements`. Cuota de aceptados, rechazados y el cuarto estado
     * (`cancelled`); los pendientes son el resto, y los primeros acuerdos de cada departamento que pide y que recibe
     * se reservan pendientes para que su bandeja tenga «Enviados» y «Por decidir».
     *
     * @param  list<InterdepartmentalAgreement>  $agreements
     * @return list<string>
     */
    private function decisions(array $agreements, float $accepted, float $rejected, float $cancelled, bool $reserve = true): array
    {
        $total = count($agreements);
        $decisions = array_fill(0, $total, null);
        $order = $this->random->shuffleArray(array_keys($agreements));

        if ($reserve) {
            $seenFrom = [];
            $seenTo = [];

            foreach ($order as $index) {
                $agreement = $agreements[$index];

                if (! isset($seenFrom[$agreement->from_department_id]) || ! isset($seenTo[$agreement->to_department_id])) {
                    $decisions[$index] = 'proposed';
                    $seenFrom[$agreement->from_department_id] = true;
                    $seenTo[$agreement->to_department_id] = true;
                }
            }
        }

        $wanted = [
            'accepted' => (int) round($total * $accepted),
            'rejected' => (int) round($total * $rejected),
            'cancelled' => (int) round($total * $cancelled),
        ];

        foreach ($order as $index) {
            if ($decisions[$index] !== null) {
                continue;
            }

            $decisions[$index] = 'proposed';

            foreach ($wanted as $status => $left) {
                if ($left > 0) {
                    $decisions[$index] = $status;
                    $wanted[$status]--;

                    break;
                }
            }
        }

        return $decisions;
    }

    /**
     * @param  list<string>  $options
     */
    private function pick(array $options): string
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }

    // ---------------------------------------------------------- grupos compartidos

    /**
     * @param  Collection<int, Group>  $usable  grupos editables, limpios o no
     * @param  bool  $complete  todos aceptados, y lo que una Action rechace detiene el seeder
     * @return int los grupos compartidos aceptados
     *
     * @throws RuntimeException con `$complete`, si una Action rechaza un paso
     */
    private function seedSharedGroups(Term $term, Campus $campus, $usable, bool $complete = false): int
    {
        $offered = Career::query()->whereIn('id', CareerCampus::query()->where('campus_id', $campus->id)->where('is_active', true)->select('career_id'))
            ->where('is_active', true)->orderBy('code')->get()->keyBy('id');
        $common = DB::table((new StudyPlanSubject)->getTable().' as sps')
            ->join('study_plans as sp', 'sp.id', '=', 'sps.study_plan_id')
            ->whereIn('sp.career_id', $offered->keys())
            ->select('sps.subject_id', 'sp.career_id')->distinct()->get()
            ->groupBy('subject_id')->map(fn ($rows) => $rows->pluck('career_id')->all());

        $target = (int) $this->ratios['shared'];
        $this->summary['direct'] = 0;
        $proposals = [];
        $total = 0;

        $groups = collect($this->random->shuffleArray($usable->all()))->filter(fn (Group $group): bool => $group->capacity >= 8);
        $directLeft = (int) $this->ratios['direct_quota_groups'];

        // Primero los grupos con tronco común y, si no alcanzan, los que aceptan cualquier otra carrera ofrecida.
        foreach ([true, false] as $strict) {
            foreach ($groups as $group) {
                if ($total >= $target) {
                    break 2;
                }

                if (isset($proposals[$group->id])) {
                    continue;
                }

                $careers = $offered->filter(fn (Career $career): bool => $career->department_id !== $group->department_id && $career->id !== $group->owner_career_id
                    && (! $strict || in_array($career->id, $common[$group->subject_id] ?? [], true)))->values();

                if ($careers->isEmpty()) {
                    continue;
                }

                $take = min($careers->count(), $this->random->getInt(1, 2));
                $picked = array_slice($this->random->shuffleArray($careers->all()), 0, $take);
                $proposals[$group->id] = [$group, $picked];
                $total += $take;
            }
        }

        $agreements = [];

        foreach ($proposals as [$group, $picked]) {
            $seats = max(3, intdiv($group->capacity, 5));
            $direct = $directLeft > 0 ? $offered->first(fn (Career $career): bool => $career->department_id === $group->department_id && $career->id !== $group->owner_career_id) : null;
            $reserved = $seats * count($picked) + ($direct === null ? 0 : $seats);
            $head = $this->head(AcademicLoadPermission::AgreementRequest, $campus, $group->department_id);

            if ($group->capacity - $reserved < 1) {
                continue;
            }

            try {
                app(SetGroupQuotas::class)->handle($group, [$group->owner_career_id => $group->capacity - $reserved] + ($direct === null ? [] : [$direct->id => $seats]), $head);
            } catch (ValidationException $exception) {
                $this->failOrCount($complete, $exception, "Los cupos de {$group->code} no se pudieron repartir", 'cupos');

                continue;
            }

            if ($direct !== null) {
                $directLeft--;
                $this->summary['direct']++;
            }

            foreach ($picked as $career) {
                try {
                    $agreements[] = app(ProposeGroupQuota::class)->handle($group, $career, $seats, $head);
                } catch (ValidationException $exception) {
                    $this->failOrCount($complete, $exception, "El grupo compartido {$group->code} con {$career->code} no se pudo proponer", 'cupos');

                    continue;
                }
            }
        }

        $decisions = $complete ? array_fill(0, count($agreements), 'accepted') : $this->decisions($agreements, 0.40, 0.20, 0.10, reserve: false);
        $accepted = 0;

        foreach ($agreements as $index => $agreement) {
            $decision = $decisions[$index];
            $owner = $this->head(AcademicLoadPermission::AgreementDecide, $campus, $agreement->to_department_id);
            $requester = $this->head(AcademicLoadPermission::AgreementRequest, $campus, $agreement->from_department_id);

            try {
                match ($decision) {
                    'accepted' => app(AcceptSharedGroup::class)->handle($agreement, $owner),
                    'rejected' => app(RejectSharedGroup::class)->handle($agreement, $this->pick(self::SHARED_REJECTIONS), $owner),
                    'cancelled' => app(WithdrawGroupQuotaProposal::class)->handle($agreement, $requester),
                    default => null,
                };
            } catch (ValidationException $exception) {
                $this->failOrCount($complete, $exception, 'Un grupo compartido no se pudo aceptar', 'decisiones de grupos compartidos');

                continue;
            }

            $accepted += $decision === 'accepted' ? 1 : 0;
        }

        return $accepted;
    }

    /**
     * Con `$complete`, detiene el seeder con los motivos de la Action; si no, solo cuenta el intento rechazado.
     *
     * @throws RuntimeException
     */
    private function failOrCount(bool $complete, ValidationException $exception, string $what, string $phase): void
    {
        if ($complete) {
            throw new RuntimeException("{$what}: ".implode(' ', Arr::flatten($exception->errors())), previous: $exception);
        }

        $this->rejected($phase);
    }

    // ------------------------------------------------------------ salones sugeridos

    /**
     * @param  Collection<string, Group>  $open
     * @param  array<string, true>  $skipGroups
     */
    private function seedSuggestedRooms(Campus $campus, $open, array $skipGroups): int
    {
        $sessions = GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)->whereNull('room_id')
            ->whereNotIn('group_id', array_keys($skipGroups))->get();
        $sessions = $this->sessionsInOrder($sessions, $open);

        if ($sessions->isEmpty() || (float) $this->ratios['suggested_rooms'] <= 0) {
            return 0;
        }

        $wanted = min((int) $this->ratios['suggested_max'], (int) ceil($sessions->count() * (float) $this->ratios['suggested_rooms']));
        $picked = array_slice($this->random->shuffleArray($sessions->all()), 0, $wanted);
        $assign = app(AssignSessionRoom::class);
        $suggest = app(RoomSuggestionQuery::class);
        $done = 0;

        foreach ($picked as $session) {
            // Solo salones sin dueño o del propio departamento: un sugerido nunca pide préstamo (los pide addLoans).
            $first = collect($suggest->for($session, 30)['rooms'])->first(fn (array $row): bool => $row['selectable'] && $row['state']['free'] && ! $row['state']['needsLoan']);

            if ($first === null) {
                continue;
            }

            try {
                $assign->handle($session, Room::query()->findOrFail($first['id']), $this->head(AcademicLoadPermission::AgreementRequest, $campus, $open[$session->group_id]->department_id));
                $done++;
            } catch (ValidationException) {
                $this->rejected('salones sugeridos');

                continue;
            }
        }

        return $done;
    }
}
