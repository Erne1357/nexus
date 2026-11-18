<?php

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\AcademicLoad\Database\Seeders\ReanalysisDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Queries\ReanalysisChangesQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisCycleQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisDiffQuery;
use App\Modules\AcademicLoad\Queries\ReanalysisRow;
use App\Modules\Core\Database\Seeders\Concerns\RefusesProduction;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Actions\Sessions\PlaceGroupSession;
use App\Modules\Scheduling\Actions\Sessions\UpdateGroupSession;
use App\Modules\Scheduling\Actions\SetGroupCapacity;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupQuotasChanged;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\RoomSuggestionQuery;
use App\Modules\Scheduling\Rules\GroupCode;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;
use Throwable;

/**
 * Demo del re-análisis (fase 8, #614), la parte de Scheduling: el jefe ajusta cada carga en re-análisis cuyo caso cambia
 * (ReanalysisDemoSeeder::CHANGING) con las Actions del editor, viendo el diff de demanda (ReanalysisDiffQuery), para que la
 * pantalla diga «Ya ajustaste» y la versión 2 guarde cada tipo de cambio de SnapshotDiff:
 *
 * - **cupos** (`career_quotas`): amplía el cupo de otra carrera en un grupo compartido, con los lugares libres o tomándolos
 *   de la dueña (SetGroupQuotas, HC-07); así la republicación avisa al jefe del otro departamento (RA-05);
 * - **grupo abierto** (`added`): en la materia con más faltante, un grupo para esa carrera en ese campus, con el plan y
 *   el semestre de su fila de la propuesta y las sesiones de otro grupo de la materia a otra hora, en salón propio o sin
 *   dueño libre (PlaceGroupSession, AssignSessionRoom), su clave (AssignGroupCode) y la justificación de HC-15 mientras
 *   no tiene docente (JustifyGroup; Staffing se lo asigna);
 * - **capacidad** (`quota`): sube la capacidad de grupos hasta el salón más chico de sus sesiones (SetGroupCapacity, HC-05) y
 *   el cupo de la carrera dueña con ella, primero en las materias que faltan;
 * - **grupo cerrado** (`cancelled`): en la materia con más sobrante, uno de los grupos de la carrera sin docente que no es
 *   compartido ni ocupa salón prestado (CancelGroup, que no cancela uno con docente: si no hay, Staffing quita primero al
 *   docente), y solo si su plan y semestre siguen teniendo combinación sin empalmes (HC-03, cancelGuarded());
 * - **sesión movida** (`schedule`): una sesión de un grupo con docente a otro día, a la misma hora y en su salón
 *   (UpdateGroupSession; la clave sigue igual porque la hora de inicio no cambia);
 * - **salón cambiado** (`room`): como PublicationShowcaseDemoSeeder, al primer salón sugerido libre, propio o sin dueño
 *   (RoomSuggestionQuery, AssignSessionRoom).
 *
 * El cambio de docente (`teacher`) es de Staffing (ReanalysisRepublicationDemoSeeder). Abrir un grupo no tiene Action
 * todavía (la 8.3b, que depende del motor) y CreateGroupsFromProposal no sirve: crea los grupos que le faltan a una fila de
 * propuesta aceptada, que ya no se edita. Es la excepción de la demo: este seeder escribe el grupo nuevo y su cupo como
 * CreateGroupsFromProposal, con su bitácora y GroupsCreated después del commit, y sus sesiones, salones, clave y
 * justificación van por las Actions.
 *
 * Cuántos de cada uno depende del tamaño de la carga: `min(6, max(2, grupos / 40))` cupos, sesiones y salones, la mitad
 * (1 o 2) capacidades y grupos abiertos, y hasta 3 cerrados. Solo cambia una carga en re-análisis que todavía no cambió ni se cerró, así
 * que se puede volver a correr. Mientras escribe descarta los eventos que recalculan choques y ranking en cada cambio, y
 * al final recalcula los choques una vez; si un cambio dejara un choque duro en la carga, se detiene con su mensaje.
 */
final class ReanalysisChangesDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** La justificación de HC-15 del grupo abierto mientras no tiene docente; Staffing la quita al asignarlo. */
    public const string OPENED_JUSTIFICATION = 'Grupo abierto en el re-análisis por la demanda: el docente se asigna al contratarlo.';

    /** @var list<string> */
    public const array KINDS = ['quotas', 'capacity', 'open', 'cancel', 'move', 'rooms'];

    /** Los eventos que recalcularían choques y ranking en cada cambio; se recalcula una vez al final. */
    public const array FAKED_EVENTS = [GroupScheduleChanged::class, GroupCancelled::class, GroupQuotasChanged::class];

    /** Lugares que se amplían en un cupo. */
    private const int WIDEN_SEATS = 5;

    public function __construct(private readonly ScheduleValidator $validator) {}

    public function run(string $term = '20271', string $modality = 'PRES'): void
    {
        $this->refuseProduction();

        $termModel = Term::query()->where('code', $term)->firstOrFail();
        $modalityModel = Modality::query()->where('code', $modality)->firstOrFail();
        $done = [];

        Event::fakeFor(function () use ($termModel, $modalityModel, &$done): void {
            foreach (ReanalysisDemoSeeder::loadsIn($termModel->getKey(), $modalityModel->getKey(), ReanalysisDemoSeeder::CHANGING) as $load) {
                if ($load->status !== LoadStatus::Reanalysis || app(ReanalysisCycleQuery::class)->closedFromReanalysis($load) || app(ReanalysisChangesQuery::class)->hasChanges($load)) {
                    continue;
                }

                $counts = $this->change($load, ReanalysisDemoSeeder::headOf($load));
                $done[] = $load->department->code.' ('.implode(', ', array_map(fn (string $kind, int $count): string => "{$kind} {$count}", array_keys($counts), $counts)).')';
            }
        }, self::FAKED_EVENTS);

        if ($done !== []) {
            app(SyncScheduleConflicts::class)->handle($termModel, $modalityModel);
        }

        $this->command?->info('Ajustes del re-análisis: '.($done === [] ? 'ninguna carga nueva' : implode('; ', $done)).'.');
    }

    /**
     * Hace en la carga los cambios pedidos, como su jefe, y da cuántos de cada tipo hizo. `$round` es la vuelta (0 en el
     * re-análisis, 1 en la corrección): los salones de otra vuelta cambian en otras sesiones. Quien llama descarta
     * FAKED_EVENTS y recalcula los choques al final.
     *
     * @param  list<string>  $kinds
     * @return array<string, int>
     *
     * @throws RuntimeException si los cambios dejan un choque duro en la carga
     */
    public function change(AcademicLoad $load, User $head, array $kinds = self::KINDS, int $round = 0): array
    {
        $perKind = min(6, max(2, intdiv($this->groups($load)->count(), 40)));
        $counts = [];

        foreach ($kinds as $kind) {
            $counts[$kind] = match ($kind) {
                'quotas' => $this->widenQuotas($load, $head, $perKind),
                'capacity' => $this->widenCapacity($load, $head, min(2, max(1, intdiv($perKind, 2)))),
                'open' => $this->openGroups($load, $head, min(2, max(1, intdiv($perKind, 2)))),
                'cancel' => $this->cancelGroups($load, $head, min(3, max(1, intdiv($perKind, 2)))),
                'move' => $this->moveSessions($load, $head, $perKind),
                'rooms' => $this->changeRooms($load, $head, $perKind, $round),
                default => throw new RuntimeException("Cambio desconocido de la demo del re-análisis: {$kind}."),
            };
        }

        $this->validator->forget();
        $hard = array_filter(
            $this->validator->validateLoad($load->term, $load->modality, $load->department)->violations(),
            fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard,
        );
        $this->validator->forget();

        if ($hard !== []) {
            throw new RuntimeException("Los ajustes del re-análisis dejaron choques duros en la carga de {$load->department->code}: ".implode(' · ', array_slice(array_map(fn (Violation $violation): string => "{$violation->rule} {$violation->message}", $hard), 0, 5)));
        }

        return $counts;
    }

    /**
     * Amplía el cupo de otra carrera en los grupos compartidos (los lugares libres o, si no hay, los toma de la dueña) y,
     * si no alcanzan, el de la dueña hasta la capacidad.
     */
    private function widenQuotas(AcademicLoad $load, User $head, int $wanted): int
    {
        $done = 0;
        $groups = $this->groups($load)->load('quotas');
        $shared = $groups->filter(fn (Group $group): bool => $group->quotas->count() > 1);
        $owned = $groups->filter(fn (Group $group): bool => $group->quotas->count() === 1 && $group->quotas->sum('max_seats') < $group->capacity);

        foreach ([...$shared->values(), ...$owned->values()] as $group) {
            if ($done === $wanted) {
                break;
            }

            $seats = $group->quotas->mapWithKeys(fn (GroupQuota $quota): array => [$quota->career_id => $quota->max_seats])->all();
            $owner = $group->owner_career_id;
            $other = $group->quotas->reject(fn (GroupQuota $quota): bool => $quota->is_owner)->sortBy('max_seats')->first()?->career_id ?? $owner;
            $free = $group->capacity - array_sum($seats);
            $widen = min(self::WIDEN_SEATS, $free > 0 ? $free : ($other === $owner ? 0 : $seats[$owner] - 1));

            if ($widen < 1) {
                continue;
            }

            $seats[$other] += $widen;

            if ($free <= 0) {
                $seats[$owner] -= $widen;
            }

            try {
                app(SetGroupQuotas::class)->handle($group, $seats, $head);
                $done++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $done;
    }

    /**
     * Abre un grupo en las materias con más faltante del diff, para la carrera de la fila: con el plan y el semestre de
     * su fila de la propuesta, y las horas y el tipo de cada sesión de un grupo de la misma materia (el de esa carrera
     * en ese campus si lo hay; si no, el de otra carrera o de otro campus), con sus sesiones a otra hora. Una fila sin
     * grupo de su materia que copiar, o sin acomodo posible, se salta sin dejar nada.
     */
    private function openGroups(AcademicLoad $load, User $head, int $wanted): int
    {
        $rows = collect(app(ReanalysisDiffQuery::class)->forLoad($load)->rows)
            ->filter(fn (ReanalysisRow $row): bool => $row->balance === DemandBalance::Shortfall && $row->campusId !== null)
            ->sortBy(fn (ReanalysisRow $row): array => [-$row->gap(), $row->campusCode, $row->careerCode, $row->subjectCode])
            ->values();
        $groups = $this->groups($load)->filter(fn (Group $group): bool => $group->sessions->isNotEmpty()
            && $group->sessions->every(fn (GroupSession $session): bool => $session->room_id !== null));
        $done = 0;

        foreach ($rows as $row) {
            if ($done === $wanted) {
                break;
            }

            $item = ProposalItem::query()
                ->whereIn('career_proposal_id', CareerProposal::query()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('career_id', $row->careerId)->select('id'))
                ->where('subject_id', $row->subjectId)
                ->where('campus_id', $row->campusId)
                ->first(['study_plan_id', 'semester']);
            $sibling = $groups
                ->filter(fn (Group $group): bool => $group->subject_id === $row->subjectId)
                ->sortBy(fn (Group $group): array => [$group->campus_id === $row->campusId ? 0 : 1, $group->owner_career_id === $row->careerId ? 0 : 1, $group->code])
                ->first();

            if ($item !== null && $sibling !== null && $this->openLike($sibling, $row, $item, $head)) {
                $done++;
            }
        }

        return $done;
    }

    private function openLike(Group $sibling, ReanalysisRow $row, ProposalItem $item, User $head): bool
    {
        // El grupo nace en una transacción propia: si sus sesiones no caben, se revierte y no queda nada.
        DB::beginTransaction();

        try {
            $group = $this->newGroup($sibling, $row, $item);
            $this->validator->forget();
            $placements = $this->placementsFor($group, $sibling);
            $placements === null ? DB::rollBack() : DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $this->validator->forget();
        }

        if ($placements === null) {
            return false;
        }

        foreach ($placements as [$day, $startsAt, $endsAt, $kind, $roomId]) {
            $session = app(PlaceGroupSession::class)->handle($group, $day, $startsAt, $endsAt, $kind, $head)->session;
            app(AssignSessionRoom::class)->handle($session, Room::query()->findOrFail($roomId), $head);
            $this->validator->forget();
        }

        $group = app(AssignGroupCode::class)->handle($group->fresh());
        app(JustifyGroup::class)->handle($group, self::OPENED_JUSTIFICATION, $head);

        activity()
            ->causedBy($head)
            ->performedOn($group)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => ['code' => $group->code, 'term_id' => $group->term_id, 'campus_id' => $group->campus_id, 'modality_id' => $group->modality_id, 'subject_id' => $group->subject_id, 'owner_career_id' => $group->owner_career_id, 'study_plan_id' => $group->study_plan_id, 'semester' => $group->semester, 'capacity' => $group->capacity]])
            ->log("Abrió el grupo {$group->code} de {$row->subjectName} para {$row->careerCode} en el re-análisis: faltan {$row->gap()} lugares con las calificaciones nuevas");

        // Como CreateGroupsFromProposal: avisa después del commit (choques y ranking). En la demo por puntos es un evento
        // silenciado (demoQuietEvents) y Scheduling recalcula los choques al final.
        GroupsCreated::dispatch($group->term_id, $group->modality_id, [$group->getKey()]);

        return true;
    }

    /**
     * El grupo nuevo, planeado y sin sesiones, como lo crea CreateGroupsFromProposal (con el cupo de la carrera dueña igual
     * a la capacidad), pero sin fila de propuesta: nace en el re-análisis. La capacidad es la del grupo que copia.
     */
    private function newGroup(Group $sibling, ReanalysisRow $row, ProposalItem $item): Group
    {
        $taken = Group::query()->active()->where('term_id', $sibling->term_id)->where('subject_id', $sibling->subject_id)->pluck('code')->all();

        $group = Group::query()->create([
            'term_id' => $sibling->term_id,
            'campus_id' => $row->campusId,
            'modality_id' => $sibling->modality_id,
            'subject_id' => $sibling->subject_id,
            'department_id' => $sibling->department_id,
            'owner_career_id' => $row->careerId,
            'study_plan_id' => $item->study_plan_id,
            'semester' => $item->semester,
            'code' => GroupCode::provisional($taken),
            'capacity' => $sibling->capacity,
            'status' => GroupStatus::Planned,
        ]);

        GroupQuota::query()->create(['group_id' => $group->getKey(), 'career_id' => $row->careerId, 'max_seats' => $sibling->capacity, 'is_owner' => true]);

        return $group;
    }

    /**
     * Dónde va cada sesión del grupo nuevo, con las horas y el tipo de las del grupo hermano: el primer día y hora,
     * desde el día de la sesión hermana y después de su hora, en que un salón del mismo tipo, del campus, propio o sin
     * dueño y donde cabe el grupo está libre y el validador no ve ningún choque duro, salvo HC-12: mientras faltan sus
     * otras sesiones al grupo le faltan horas. Null si alguna no se acomoda.
     *
     * @return list<array{int, string, string, SessionKind, string}>|null
     */
    private function placementsFor(Group $group, Group $sibling): ?array
    {
        $profile = $group->modality->profile;
        $days = $profile->days;
        $dayStart = Minutes::of($profile->dayStart);
        $dayEnd = Minutes::of($profile->dayEnd);
        $placed = [];

        foreach ($sibling->sessions->sortBy(['day', 'starts_at'])->values() as $model) {
            $siblingStart = Minutes::of((string) $model->starts_at);
            $length = Minutes::of((string) $model->ends_at) - $siblingStart;
            $rooms = $this->candidateRooms($group, $model->room);
            $spot = null;

            foreach ($this->rotate($days, (int) $model->day) as $day) {
                if (in_array($day, array_column($placed, 0), true)) {
                    continue;
                }

                foreach ($this->startsAfter($siblingStart, $dayStart, $dayEnd - $length, max(30, $profile->blockMinutes)) as $start) {
                    [$startsAt, $endsAt] = [Minutes::format($start), Minutes::format($start + $length)];

                    foreach ($rooms as $room) {
                        $candidate = new SessionCandidate($day, $startsAt, $endsAt, $room->getKey(), $model->kind);

                        if (! self::hardBesidesHours($this->validator->checkSession($group, $candidate)->violations())) {
                            $spot = [$day, $startsAt, $endsAt, $model->kind, $room->getKey()];

                            break 3;
                        }
                    }
                }
            }

            if ($spot === null) {
                return null;
            }

            $placed[] = $spot;
        }

        return $placed;
    }

    /**
     * Si hay un choque duro que no sea HC-12 (las horas de la semana del grupo, que no completa hasta tener todas sus
     * sesiones).
     *
     * @param  list<Violation>  $violations
     */
    private static function hardBesidesHours(array $violations): bool
    {
        return array_any($violations, fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard && $violation->rule !== 'HC-12');
    }

    /**
     * Los salones donde puede ir una sesión del grupo nuevo: del campus, activos, del tipo del salón de la sesión hermana,
     * donde cabe el grupo y del departamento o sin dueño (sin préstamo, HC-10); el más justo primero.
     *
     * @return Collection<int, Room>
     */
    private function candidateRooms(Group $group, ?Room $siblingRoom): Collection
    {
        return Room::query()
            ->where('campus_id', $group->campus_id)
            ->where('is_active', true)
            ->when($siblingRoom !== null, fn ($query) => $query->where('type', $siblingRoom->type))
            ->where('capacity', '>=', $group->capacity)
            ->where(fn ($query) => $query->whereNull('department_id')->orWhere('department_id', $group->department_id))
            ->orderBy('capacity')
            ->orderBy('code')
            ->get();
    }

    /**
     * Cierra grupos sin docente en las materias con más sobrante del diff (CancelGroup no cancela un grupo con docente):
     * de surplusCandidates(), los que no tienen docente, uno por materia. Con docente los cierra Staffing, que primero se
     * lo quita (ReanalysisRepublicationDemoSeeder).
     */
    private function cancelGroups(AcademicLoad $load, User $head, int $wanted): int
    {
        $done = 0;
        $subjects = [];

        foreach ($this->surplusCandidates($load) as $group) {
            if ($done === $wanted) {
                break;
            }

            if ($group->teacher_id !== null || isset($subjects[$group->subject_id.'|'.$group->campus_id])) {
                continue;
            }

            if ($this->cancelGuarded($group, $head)) {
                $subjects[$group->subject_id.'|'.$group->campus_id] = true;
                $done++;
            }
        }

        return $done;
    }

    /**
     * Los grupos que se pueden cerrar porque sobran lugares: de cada fila sobrante del diff (la de más sobrante primero)
     * cuya carrera tiene más de un grupo de la materia en ese campus, los de esa carrera que no son compartidos ni ocupan un
     * salón de otro departamento (sus acuerdos se cancelarían), el último por clave primero. Con o sin docente: quien
     * llama decide.
     *
     * @return Collection<int, Group>
     */
    public function surplusCandidates(AcademicLoad $load): Collection
    {
        $rows = collect(app(ReanalysisDiffQuery::class)->forLoad($load)->rows)
            ->filter(fn (ReanalysisRow $row): bool => $row->balance === DemandBalance::Surplus && $row->campusId !== null && $row->offeredGroups > 1)
            ->sortBy(fn (ReanalysisRow $row): array => [$row->gap(), $row->campusCode, $row->careerCode, $row->subjectCode])
            ->values();
        $groups = $this->groups($load)->load(['quotas', 'sessions.room']);

        return $rows->flatMap(function (ReanalysisRow $row) use ($groups): Collection {
            $mine = $groups->filter(fn (Group $group): bool => $group->subject_id === $row->subjectId && $group->campus_id === $row->campusId && $group->owner_career_id === $row->careerId);

            return $mine->count() < 2 ? new Collection : $mine
                ->filter(fn (Group $group): bool => $group->quotas->count() === 1
                    && $group->sessions->every(fn (GroupSession $session): bool => $session->room === null || $session->room->department_id === null || $session->room->department_id === $group->department_id))
                ->sortByDesc('code')
                ->values();
        })->unique(fn (Group $group): string => $group->getKey())->values();
    }

    /**
     * Cancela el grupo con CancelGroup (bitácora, sesiones, acuerdos, GroupCancelled), en una transacción que se revierte
     * sin dejar nada si al alumno tipo de su plan y semestre en su campus le falta una combinación sin empalmes (HC-03):
     * quitar un grupo quita una opción, y CancelGroup no lo revisa. Falso si se revirtió o la Action lo rechazó.
     */
    public function cancelGuarded(Group $group, User $head): bool
    {
        DB::beginTransaction();

        try {
            app(CancelGroup::class)->handle($group->fresh(), $head);

            $this->validator->forget();
            $peers = Group::query()->active()
                ->where('term_id', $group->term_id)->where('modality_id', $group->modality_id)->where('campus_id', $group->campus_id)
                ->where('study_plan_id', $group->study_plan_id)->where('semester', $group->semester)
                ->pluck('id')->all();

            if ($peers !== [] && $this->validator->validate($this->validator->stateFor($group->term_id, $group->modality_id), ValidationScope::groups(...$peers), ['HC-03'])->hasHard()) {
                DB::rollBack();

                return false;
            }

            DB::commit();

            return true;
        } catch (ValidationException) {
            DB::rollBack();

            return false;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        } finally {
            $this->validator->forget();
        }
    }

    /**
     * Amplía la capacidad de grupos hasta el salón más chico de sus sesiones (SetGroupCapacity, HC-05) y el cupo de la
     * carrera dueña con ella (SetGroupQuotas, HC-07): primero los de las materias que faltan en el diff.
     */
    private function widenCapacity(AcademicLoad $load, User $head, int $wanted): int
    {
        $short = collect(app(ReanalysisDiffQuery::class)->forLoad($load)->rows)
            ->filter(fn (ReanalysisRow $row): bool => $row->balance === DemandBalance::Shortfall)
            ->map(fn (ReanalysisRow $row): string => $row->subjectId)
            ->flip();
        $groups = $this->groups($load)->load(['quotas', 'sessions.room'])
            ->filter(fn (Group $group): bool => $group->sessions->isNotEmpty() && $group->sessions->every(fn (GroupSession $session): bool => $session->room !== null)
                && $group->sessions->min(fn (GroupSession $session): int => $session->room->capacity) > $group->capacity)
            ->sortBy(fn (Group $group): array => [isset($short[$group->subject_id]) ? 0 : 1, $group->id])
            ->values();
        $done = 0;

        foreach ($groups as $group) {
            if ($done === $wanted) {
                break;
            }

            $capacity = min($group->capacity + self::WIDEN_SEATS, $group->sessions->min(fn (GroupSession $session): int => $session->room->capacity));
            $seats = $group->quotas->mapWithKeys(fn (GroupQuota $quota): array => [$quota->career_id => $quota->max_seats])->all();
            $seats[$group->owner_career_id] = ($seats[$group->owner_career_id] ?? 0) + $capacity - $group->capacity;

            try {
                DB::transaction(function () use ($group, $capacity, $seats, $head): void {
                    app(SetGroupCapacity::class)->handle($group, $capacity, $head);
                    app(SetGroupQuotas::class)->handle($group->fresh(), $seats, $head);
                });
                $done++;
            } catch (ValidationException) {
                continue;
            }
        }

        return $done;
    }

    /**
     * Mueve una sesión de grupos con docente a otro día que el grupo no usa, a la misma hora y en su salón, si el
     * validador no ve ningún choque duro ahí; la última sesión de la semana de cada grupo. Solo grupos de una carrera:
     * el validador revisa el alumno tipo (HC-03) del plan del grupo, y un grupo compartido también está en otros.
     */
    private function moveSessions(AcademicLoad $load, User $head, int $wanted): int
    {
        $done = 0;
        $groups = $this->groups($load)->load(['sessions.room', 'quotas'])
            ->filter(fn (Group $group): bool => $group->teacher_id !== null && $group->sessions->count() > 1 && $group->quotas->count() === 1
                && $group->sessions->every(fn (GroupSession $session): bool => $session->room !== null && ($session->room->department_id === null || $session->room->department_id === $group->department_id)))
            ->sortByDesc('id')
            ->values();

        foreach ($groups as $group) {
            if ($done === $wanted) {
                break;
            }

            $session = $group->sessions->sortBy(['day', 'starts_at'])->last();
            $used = $group->sessions->pluck('day')->all();
            [$startsAt, $endsAt] = [substr((string) $session->starts_at, 0, 5), substr((string) $session->ends_at, 0, 5)];

            foreach ($this->rotate($group->modality->profile->days, (int) $session->day) as $day) {
                if (in_array($day, $used, true)) {
                    continue;
                }

                $candidate = new SessionCandidate($day, $startsAt, $endsAt, $session->room_id, $session->kind, $session->getKey());

                if ($this->validator->checkSession($group, $candidate)->hasHard()) {
                    continue;
                }

                try {
                    $result = app(UpdateGroupSession::class)->handle($session->fresh(), $day, $startsAt, $endsAt, $session->kind, $head);
                } catch (ValidationException) {
                    continue;
                } finally {
                    $this->validator->forget();
                }

                if ($result->roomDropped && ! $this->assignFreeRoom($result->session->fresh(), $head)) {
                    app(JustifyGroup::class)->handle($group->fresh(), 'El salón se asigna antes del inicio de clases.', $head);
                }

                $done++;

                break;
            }
        }

        return $done;
    }

    /**
     * Cambia de salón unas sesiones presenciales, al primer salón sugerido libre, propio o sin dueño, donde cabe el grupo
     * y del tipo que pide (RoomSuggestionQuery), como PublicationShowcaseDemoSeeder; la vuelta 1 empieza a media lista.
     */
    private function changeRooms(AcademicLoad $load, User $head, int $wanted, int $round): int
    {
        $done = 0;
        $placed = $this->groups($load)->load('sessions.room')
            ->flatMap(fn (Group $group) => $group->sessions)
            ->filter(fn (GroupSession $session): bool => $session->room !== null && ($session->room->department_id === null || $session->room->department_id === $load->department_id))
            ->sortBy('id')
            ->values();
        $offset = intdiv($placed->count(), 2) * ($round % 2);
        $sessions = $placed->slice($offset)->concat($placed->take($offset));

        foreach ($sessions as $session) {
            if ($done === $wanted) {
                break;
            }

            if ($this->assignFreeRoom($session->fresh(), $head)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Pasa la sesión al primer salón sugerido libre que no es el suyo, propio o sin dueño, donde cabe el grupo y del tipo
     * que pide (RoomSuggestionQuery, AssignSessionRoom). Falso si no hay ninguno o el validador lo rechaza.
     */
    private function assignFreeRoom(GroupSession $session, User $head): bool
    {
        $target = collect(app(RoomSuggestionQuery::class)->for($session, 20)['rooms'])->first(fn (array $row): bool => $row['id'] !== $session->room_id
            && $row['state']['free'] && ! $row['state']['needsLoan'] && ! $row['state']['tooSmall'] && ! $row['state']['wrongType'] && $row['state']['blocked'] === null);

        if ($target === null) {
            return false;
        }

        try {
            app(AssignSessionRoom::class)->handle($session, Room::query()->findOrFail($target['id']), $head);

            return true;
        } catch (ValidationException) {
            return false;
        } finally {
            $this->validator->forget();
        }
    }

    /**
     * Los grupos activos de la carga, con sus sesiones, por id.
     *
     * @return Collection<int, Group>
     */
    private function groups(AcademicLoad $load): Collection
    {
        return Group::query()
            ->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)->where('department_id', $load->department_id)
            ->active()
            ->with(['sessions', 'subject', 'modality'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Los días del perfil empezando por `$first` y dando la vuelta.
     *
     * @param  list<int>  $days
     * @return list<int>
     */
    private function rotate(array $days, int $first): array
    {
        $index = array_search($first, $days, true);

        return $index === false ? $days : [...array_slice($days, (int) $index), ...array_slice($days, 0, (int) $index)];
    }

    /**
     * Las horas de inicio de la ventana, cada `$step` minutos (el bloque del perfil), empezando después de `$after` y
     * dando la vuelta.
     *
     * @return list<int>
     */
    private function startsAfter(int $after, int $first, int $last, int $step): array
    {
        $starts = range($first, max($first, $last), $step);
        $later = array_values(array_filter($starts, fn (int $start): bool => $start > $after));

        return [...$later, ...array_values(array_filter($starts, fn (int $start): bool => $start <= $after))];
    }
}
