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
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Models\Room;
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
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
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
 * GroupQuotasChanged se descartan; al final corre SyncScheduleConflicts una vez por campus.
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
 * Determinista con `$seed` sobre los mismos datos. Idempotente: si el periodo y el campus ya tienen acuerdos, no hace
 * nada. Se niega a correr en producción.
 */
final class SchedulingAgreementsDemoSeeder extends Seeder
{
    use RefusesProduction;

    private const array LOANS = ['demo' => 16, 'massive' => 60];

    private const array SHARED = ['demo' => 24, 'massive' => 120];

    private const array SUGGESTED_MAX = ['demo' => 10, 'massive' => 40];

    private const array DIRECT_GROUPS = ['demo' => 4, 'massive' => 25];

    private const array CLEAN_DEPARTMENTS = ['demo' => 3, 'massive' => 4];

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

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  array<string, float|int>  $ratios  sobrescribe `loans`, `shared`, `direct_quota_groups` y `suggested_rooms`
     * @param  array<string, list<string>>|null  $cleanDepartments  códigos de departamento limpios por campus, de
     *                                                              SchedulingDemoSeeder::cleanDepartments(); sin ellos se deducen igual que ese seeder
     *
     * @throws RuntimeException en producción o si el periodo no existe
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027, array $ratios = [], ?array $cleanDepartments = null): void
    {
        $this->refuseProduction();
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
            Event::fakeFor(function () use ($found, $termModel, $profile, $cleanDepartments): void {
                foreach ($found as $campus) {
                    $this->seedCampus($termModel, $campus, $profile, $cleanDepartments[$campus->code] ?? null);
                }
            }, [GroupScheduleChanged::class, GroupQuotasChanged::class]);
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        foreach ($found as $campus) {
            app(SyncScheduleConflicts::class)->handle($termModel, $campus);
        }
    }

    private function seedCampus(Term $term, Campus $campus, string $profile, ?array $cleanCodes): void
    {
        $already = InterdepartmentalAgreement::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->exists();
        $groups = Group::query()->with('subject')
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->orderBy('id')->get();

        if ($already || $groups->isEmpty()) {
            $this->command?->info("Acuerdos en {$campus->code}: ".($already ? 'ya existen, no se hace nada.' : 'sin grupos.'));

            return;
        }

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

        return AcademicLoad::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->get(['department_id', 'status'])
            ->filter(fn (AcademicLoad $load): bool => in_array($load->status, $open, true))
            ->mapWithKeys(fn (AcademicLoad $load): array => [$load->department_id => true])->all();
    }

    /**
     * El jefe con `$permission` en el departamento y campus; sin él, `admin`.
     */
    private function head(AcademicLoadPermission $permission, Campus $campus, string $departmentId): User
    {
        $key = "{$permission->value}|{$campus->id}|{$departmentId}";

        return $this->heads[$key] ??= app(RecipientsQuery::class)->holders($permission, Target::of(campus: $campus->id, unit: $departmentId))[0]['user'] ?? $this->admin;
    }

    // ---------------------------------------------------------------- préstamos

    /**
     * @param  Collection<string, Group>  $open  grupos editables de departamentos no limpios, por id
     * @return array<string, true> ids de los grupos cuyo préstamo se rechazó o canceló
     */
    private function seedLoans(Term $term, Campus $campus, $open): array
    {
        $rooms = Room::query()->where('campus_id', $campus->id)->where('is_active', true)->get()->keyBy('id');

        $foreign = GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)->whereNotNull('room_id')
            ->orderBy('id')->get()
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
                continue;
            }
        }

        $created = InterdepartmentalAgreement::query()->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::RoomLoan->value)->count();
        $created += $this->addLoans(max(0, (int) $this->ratios['loans'] - $created), $campus, $open, $rooms);

        return $this->decideLoans($term, $campus);
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

        $candidates = GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)
            ->orderBy('id')->get()
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
                    continue;
                }
            }
        }

        return $done;
    }

    /**
     * Reparte los préstamos propuestos en aceptados, rechazados y cancelados; el resto se queda pendiente.
     *
     * @return array<string, true> grupos con un préstamo rechazado o cancelado
     */
    private function decideLoans(Term $term, Campus $campus): array
    {
        $loans = InterdepartmentalAgreement::query()
            ->where('term_id', $term->id)->where('campus_id', $campus->id)
            ->where('kind', AgreementKind::RoomLoan->value)->where('status', AgreementStatus::Proposed->value)
            ->orderBy('id')->get();

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
     */
    private function seedSharedGroups(Term $term, Campus $campus, $usable): void
    {
        $offered = Career::query()->whereIn('id', CareerCampus::query()->where('campus_id', $campus->id)->where('is_active', true)->select('career_id'))
            ->where('is_active', true)->get()->keyBy('id');
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
            } catch (ValidationException) {
                continue;
            }

            if ($direct !== null) {
                $directLeft--;
                $this->summary['direct']++;
            }

            foreach ($picked as $career) {
                try {
                    $agreements[] = app(ProposeGroupQuota::class)->handle($group, $career, $seats, $head);
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        $decisions = $this->decisions($agreements, 0.40, 0.20, 0.10, reserve: false);

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
            } catch (ValidationException) {
                continue;
            }
        }
    }

    // ------------------------------------------------------------ salones sugeridos

    /**
     * @param  Collection<string, Group>  $open
     * @param  array<string, true>  $skipGroups
     */
    private function seedSuggestedRooms(Campus $campus, $open, array $skipGroups): int
    {
        $sessions = GroupSession::query()->whereIn('group_id', $open->keys())->where('is_active', true)->whereNull('room_id')
            ->whereNotIn('group_id', array_keys($skipGroups))->with('group')->orderBy('id')->get();

        if ($sessions->isEmpty() || (float) $this->ratios['suggested_rooms'] <= 0) {
            return 0;
        }

        $wanted = min((int) $this->ratios['suggested_max'], (int) ceil($sessions->count() * (float) $this->ratios['suggested_rooms']));
        $picked = array_slice($this->random->shuffleArray($sessions->all()), 0, $wanted);
        $assign = app(AssignSessionRoom::class);
        $suggest = app(RoomSuggestionQuery::class);
        $done = 0;

        foreach ($picked as $session) {
            $first = collect($suggest->for($session, 30)['rooms'])->first(fn (array $row): bool => $row['state']['free']);

            if ($first === null) {
                continue;
            }

            try {
                $assign->handle($session, Room::query()->findOrFail($first['id']), $this->head(AcademicLoadPermission::AgreementRequest, $campus, $open[$session->group_id]->department_id));
                $done++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $done;
    }
}
