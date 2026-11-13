<?php

namespace App\Modules\Staffing\Database\Seeders;

use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
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
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Actions\SyncScheduleConflicts;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Scheduling\Validation\Violation;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Actions\RefreshTeacherTermLoads;
use App\Modules\Staffing\Actions\UnassignTeacher;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Models\ChangeRequest;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * El cierre de la carga en la demo (tarea 4.1; docs/arquitectura/testing.md, «Demo por puntos del proceso»). Cada
 * escritura pasa por su Action, como el jefe del departamento (el primer titular de `academic_load.load.close` en el
 * campus y el departamento; sin titular, el admin). En el periodo `$term`, `$mode`, por cada campus de `$campuses` en
 * `clean` y `justified`, y una vez por modalidad, con sus campus de `$campuses`, en `closed` y `mixed`, porque se cierra
 * la carga entera (M1: una por departamento y modalidad, con los grupos de todos sus campus):
 *
 * - `clean` (punto 8, primer paso): deja el terreno limpio antes de cerrar. En las cargas en asignación con grupos
 *   activos rechaza con comentario las solicitudes de cambio pendientes y responde los acuerdos propuestos que tocan a
 *   su departamento: los acepta el que decide y, si no se puede (HC-02, HC-07, HC-11), los cancela quien los pidió o
 *   los rechaza el que decide. Después del punto 7 no queda nada así, pero lo dice.
 * - `justified` (punto 8): la variedad de grupos justificados. En 2 o 3 departamentos de cada campus quita el docente de
 *   1 o 2 grupos (UnassignTeacher, con motivo) y en uno de ellos el salón de una sesión presencial, de preferencia de
 *   laboratorio (AssignSessionRoom con null), y los justifica (JustifyGroup) con textos como los que escribiría el jefe.
 *   Si el campus ya tiene grupos justificados no hace nada.
 * - `closed` (punto 8): cierra con CloseLoad toda carga en asignación con grupos activos; sus grupos quedan confirmados
 *   (ConfirmGroupsOnLoadClosing). Las cargas sin grupos se quedan en borrador. Un veto falla con el departamento y sus
 *   motivos.
 * - `mixed` (la demo mezclada, `todo`): después de StaffingDemoSeeder cierra unas cuantas cargas en asignación
 *   (`closed` + `reopened` del perfil, las que menos justificaciones necesitan), cada una en su transacción: rechaza
 *   sus solicitudes pendientes, responde sus acuerdos, quita el docente de los grupos con un choque duro de docente
 *   (HC-01, HC-08, HC-09: StaffingDemoSeeder deja docentes que se pasan de su tope), justifica cada grupo sin docente o
 *   sin salón y la cierra. Una carga con otro choque duro, sin ningún docente o con solicitudes aprobadas sin aplicar
 *   no se toca, ni las de `$keepOpen` (códigos de departamento que otro paso usa). Si cerró al menos dos, Subdirección
 *   reabre la última con su motivo y queda en ajustes. Si el campus ya tiene una carga cerrada o reabierta, no hace
 *   nada.
 *
 * Mientras escribe, los eventos que recalcularían choques, rankings y cargas por docente en cada escritura se
 * descartan; al final recalcula una vez los choques del campus y `teacher_term_loads`. Determinista con `$seed`: se
 * baraja sobre llaves naturales (claves de departamento, materia y grupo). Se niega a correr en producción.
 */
final class LoadClosingDemoSeeder extends Seeder
{
    use RefusesProduction;

    /** Los modos de `run()`. */
    public const array MODES = ['clean', 'justified', 'closed', 'mixed'];

    /** Por perfil del modo `mixed`: cuántas cargas se quedan cerradas y cuántas se reabren después de cerrarlas. */
    private const array PROFILES = [
        'demo' => ['closed' => 1, 'reopened' => 1],
        'massive' => ['closed' => 3, 'reopened' => 1],
    ];

    /** Las reglas de docente cuyo choque duro se quita sacando al docente del grupo (modo `mixed`). */
    private const array TEACHER_RULES = ['HC-01', 'HC-08', 'HC-09'];

    /** Los eventos que, mientras siembra, encolarían un recálculo por cada escritura. */
    private const array FAKED_EVENTS = [GroupTeacherChanged::class, GroupScheduleChanged::class, GroupCancelled::class];

    /** Cuántas vueltas da el modo `mixed` quitando docentes con choque duro antes de rendirse con una carga. */
    private const int CONFLICT_ROUNDS = 12;

    private const array UNASSIGN_REASONS = [
        'El docente pidió licencia sin goce de sueldo por el semestre.',
        'El docente se jubila en enero; el grupo espera a quien lo cubra.',
        'El docente pasa a la Coordinación de Posgrado y deja sus horas frente a grupo.',
        'El docente renunció a sus horas de asignatura.',
    ];

    private const string OVER_LIMIT_REASON = 'Con sus horas fuera del ITCJ se pasa de su tope; el grupo espera a otro docente.';

    private const array TEACHER_JUSTIFICATIONS = [
        'Docente de nuevo ingreso, contratación en trámite.',
        'Plaza de asignatura autorizada; la convocatoria cierra en diciembre.',
        'Se espera el dictamen de compatibilidad de horarios del docente propuesto.',
        'El docente propuesto regresa de su año sabático el 1 de febrero.',
    ];

    private const string LAB_JUSTIFICATION = 'El laboratorio se entrega remodelado en enero.';

    private const array ROOM_JUSTIFICATIONS = [
        'El aula se entrega en enero, con la obra del edificio terminada.',
        'El salón depende de cuántos alumnos se inscriban: se asigna en la primera semana.',
        'Se espera el préstamo de un aula del edificio de posgrado.',
    ];

    private const array BOTH_JUSTIFICATIONS = [
        'Docente de nuevo ingreso, contratación en trámite; el salón se asigna con su horario definitivo.',
        'Grupo abierto por demanda tardía: docente y salón se asignan al cerrar la inscripción.',
    ];

    private const array REJECT_COMMENTS = [
        'La carga se cierra esta semana; si el cambio sigue haciendo falta, se pide en ajustes.',
        'No hay otro horario libre sin empalmar a los alumnos del semestre; se queda como está.',
    ];

    private const string AGREEMENT_REJECT_COMMENT = 'Se cierra la carga y ese bloque ya no está disponible.';

    private const string CLOSE_COMMENT = 'Carga completa; los grupos sin docente o sin salón van justificados.';

    private const string REOPEN_REASON = 'Se autorizó la contratación del docente de nuevo ingreso: se reabre para asignarle los grupos justificados.';

    private Randomizer $random;

    private User $admin;

    /** @var Collection<int, Campus> los campus que se siembran ahora: uno en `clean` y `justified`; los de la modalidad en `closed` y `mixed` */
    private Collection $campuses;

    /** @var array<string, ?User> «permiso|campus|departamento» => primer titular */
    private array $holders = [];

    /** @var array<string, int> lista de textos => siguiente a usar */
    private array $cursors = [];

    /** @var array<string, int> */
    private array $counts = [];

    /**
     * @param  list<string>  $campuses  claves de campus
     * @param  string  $mode  uno de MODES (ver la docblock de la clase)
     * @param  list<string>  $keepOpen  códigos de departamento que el modo `mixed` no cierra
     *
     * @throws RuntimeException en producción, con un modo que no existe, si el periodo no existe o, en los modos del
     *                          punto 8, si una Action rechaza un paso
     */
    public function run(string $profile = 'demo', array $campuses = ['C2'], string $term = '20271', int $seed = 2027, string $mode = 'mixed', array $keepOpen = []): void
    {
        $this->refuseProduction();

        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Modo desconocido: {$mode}.");
        }

        $started = hrtime(true);
        $this->random = new Randomizer(new Mt19937($seed));
        $termModel = Term::query()->where('code', $term)->first() ?? throw new RuntimeException("El periodo {$term} no existe: corre antes DemoTermSeeder.");
        $this->admin = User::query()->where('username', 'admin')->first() ?? User::query()->first()
            ?? throw new RuntimeException('Hace falta un usuario para la bitácora: corre antes AdminUserSeeder.');
        $found = Campus::query()->whereIn('code', $campuses)->orderBy('code')->get();
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        $anyTouched = false;

        try {
            // Cerrar es por carga (M1: una por departamento y modalidad, con los grupos de todos sus campus): `closed` y
            // `mixed` trabajan una vez por modalidad con todos sus campus de la demo; `clean` y `justified`, campus por campus.
            $batches = in_array($mode, ['closed', 'mixed'], true)
                ? $found->groupBy('modality_id')->map(fn (Collection $campuses): Collection => $campuses->values())->values()
                : $found->map(fn (Campus $campus): Collection => collect([$campus]));

            foreach ($batches as $campuses) {
                $this->campuses = $campuses;
                $campus = $campuses->first();
                $touched = Event::fakeFor(fn (): bool => match ($mode) {
                    'clean' => $this->cleanCampus($termModel, $campus),
                    'justified' => $this->justifyCampus($termModel, $campus),
                    'closed' => $this->closeCampus($termModel, $campus),
                    default => $this->mixCampus($termModel, $campus, self::PROFILES[$profile] ?? self::PROFILES['demo'], $keepOpen),
                }, self::FAKED_EVENTS);

                if ($touched) {
                    foreach ($campuses as $touchedCampus) {
                        app(SyncScheduleConflicts::class)->handle($termModel, $touchedCampus);
                    }

                    $anyTouched = true;
                }
            }

            if ($anyTouched) {
                app(RefreshTeacherTermLoads::class)->handle($termModel);
            }
        } finally {
            config(['queue.default' => $previousQueue]);
        }

        $this->command?->info(sprintf('Cierre de la carga (%s) en %.1f s.', $mode, (hrtime(true) - $started) / 1e9));
    }

    /**
     * El modo `clean`: rechaza las solicitudes pendientes y responde los acuerdos propuestos de cada carga en asignación
     * con grupos activos. Devuelve si cambió algo de los grupos (un préstamo aceptado o cancelado).
     */
    private function cleanCampus(Term $term, Campus $campus): bool
    {
        $before = $this->counts;

        foreach ($this->loadsWithGroups($term, $campus, LoadStatus::Staffing) as $load) {
            $this->orFail(fn () => $this->clean($load), "La carga de {$load->department->code} en {$campus->code} no quedó limpia");
        }

        $this->command?->info(sprintf('Terreno limpio en %s: %d solicitudes de cambio rechazadas, %d acuerdos aceptados, %d cancelados o rechazados.', $campus->code,
            $this->delta($before, 'solicitudes rechazadas'), $this->delta($before, 'acuerdos aceptados'), $this->delta($before, 'acuerdos cancelados')));

        return $this->delta($before, 'acuerdos aceptados') + $this->delta($before, 'acuerdos cancelados') > 0;
    }

    /**
     * El modo `justified`: entre 2 o 3 departamentos quita el salón de una sesión presencial (de laboratorio, si hay) y
     * luego, en cada uno, el docente de 1 o 2 grupos más; todos quedan justificados.
     */
    private function justifyCampus(Term $term, Campus $campus): bool
    {
        if (Group::query()->where('term_id', $term->id)->where('campus_id', $campus->id)->whereNotNull('close_justification')->exists()) {
            $this->command?->info("Justificaciones en {$campus->code}: ya hay grupos justificados; no se toca.");

            return false;
        }

        $loads = $this->loadsWithGroups($term, $campus, LoadStatus::Staffing)->all();
        $picked = array_slice($this->random->shuffleArray($loads), 0, min(count($loads), $this->random->getInt(2, 3)));
        usort($picked, fn (AcademicLoad $a, AcademicLoad $b): int => $a->department->code <=> $b->department->code);
        $touched = [];
        $lines = [];
        $session = $campus->profile->usesRooms && $picked !== [] ? $this->roomToFree($picked) : null;

        if ($session !== null) {
            $group = Group::query()->with('department')->findOrFail($session->group_id);
            $head = $this->head($campus, $group->department_id);
            $lab = $session->getAttribute('room_type') === RoomType::Laboratorio->value;

            $this->orFail(function () use ($session, $group, $head, $lab): void {
                app(AssignSessionRoom::class)->handle($session, null, $head);
                app(JustifyGroup::class)->handle($group->refresh(), $lab ? self::LAB_JUSTIFICATION : $this->next(self::ROOM_JUSTIFICATIONS), $head);
            }, "No se pudo quitar el salón del grupo {$group->code} de {$group->department->code} en {$campus->code}");
            $touched[$group->id] = true;
            $lines[] = "{$group->department->code} 1 sin salón".($lab ? ' (laboratorio)' : '');
        }

        foreach ($picked as $load) {
            $head = $this->head($campus, $load->department_id);
            $staffed = $this->groupsOf($load)->whereNotNull('groups.teacher_id')->whereNotIn('groups.id', array_keys($touched))->get()->all();
            $unassigned = array_slice($this->random->shuffleArray($staffed), 0, min(count($staffed), $this->random->getInt(1, 2)));

            foreach ($unassigned as $group) {
                $this->orFail(function () use ($group, $head): void {
                    app(UnassignTeacher::class)->handle($group, $head, $this->next(self::UNASSIGN_REASONS));
                    app(JustifyGroup::class)->handle($group->refresh(), $this->next(self::TEACHER_JUSTIFICATIONS), $head);
                }, "No se pudo quitar el docente del grupo {$group->code} de {$load->department->code} en {$campus->code}");
                $touched[$group->id] = true;
            }

            $lines[] = sprintf('%s %d sin docente', $load->department->code, count($unassigned));
        }

        $this->command?->info("Justificaciones en {$campus->code}: ".($lines === [] ? 'ninguna carga en asignación con grupos' : implode(', ', $lines)).'.');

        return $lines !== [];
    }

    /**
     * El modo `closed`: cierra cada carga en asignación con grupos activos; un veto detiene el seeder con su motivo.
     */
    private function closeCampus(Term $term, Campus $campus): bool
    {
        $closed = [];

        foreach ($this->loadsWithGroups($term, $campus, LoadStatus::Staffing) as $load) {
            $this->orFail(fn () => app(CloseLoad::class)->handle($load, $this->head($campus, $load->department_id), self::CLOSE_COMMENT),
                "La carga de {$load->department->code} en {$this->where()} no se cierra");
            $closed[] = $load->department->code;
        }

        $draft = AcademicLoad::query()->with('department')->where('term_id', $term->id)->where('modality_id', $campus->modality_id)
            ->where('status', LoadStatus::Draft)->get()->pluck('department.code')->sort()->values()->all();

        $this->command?->info(sprintf('Cierre en %s: cerradas %s; en borrador, sin grupos, %s.', $this->where(),
            $closed === [] ? 'ninguna' : implode(', ', $closed), $draft === [] ? 'ninguna' : implode(', ', $draft)));

        return false;
    }

    /**
     * El modo `mixed` (ver la docblock de la clase).
     *
     * @param  array{closed: int, reopened: int}  $profile
     * @param  list<string>  $keepOpen
     */
    private function mixCampus(Term $term, Campus $campus, array $profile, array $keepOpen): bool
    {
        $already = AcademicLoad::query()->where('term_id', $term->id)->where('modality_id', $campus->modality_id)
            ->where(fn ($query) => $query->whereIn('status', [LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published])
                ->orWhereNotNull('closed_at'))
            ->exists();

        if ($already) {
            $this->command?->info("Cierre en {$this->where()}: ya hay cargas cerradas o reabiertas; no se toca.");

            return false;
        }

        $closed = [];
        $skipped = [];

        foreach ($this->mixedCandidates($term, $campus, $keepOpen) as $load) {
            if (count($closed) >= $profile['closed'] + $profile['reopened']) {
                break;
            }

            try {
                DB::transaction(function () use ($load): void {
                    $this->clean($load);
                    $this->clearTeacherConflicts($load);
                    $this->justifyMissing($load);
                    app(CloseLoad::class)->handle($load, $this->head($this->campusOf($load), $load->department_id), self::CLOSE_COMMENT);
                });
                $closed[] = $load;
            } catch (ValidationException|RuntimeException $exception) {
                $skipped[] = "{$load->department->code} («".$this->reason($exception).'»)';
            }
        }

        $reopened = null;

        if (count($closed) >= 2 && $profile['reopened'] > 0) {
            $reopened = array_pop($closed);
            $this->orFail(fn () => app(ReopenLoad::class)->handle($reopened->refresh(), $this->subdireccion($campus, $reopened->department_id), self::REOPEN_REASON),
                "La carga de {$reopened->department->code} en {$this->where()} no se pudo reabrir");
        }

        $this->command?->info(sprintf('Cierre en %s: cerradas %s; reabierta %s%s.', $this->where(),
            $closed === [] ? 'ninguna' : implode(', ', array_map(fn (AcademicLoad $load): string => $load->department->code, $closed)),
            $reopened === null ? 'ninguna (hace falta otra carga que se pueda cerrar)' : $reopened->department->code,
            $skipped === [] ? '' : '; no se pudieron cerrar '.implode('; ', $skipped)));

        return $closed !== [] || $reopened !== null;
    }

    /**
     * Las cargas en asignación del campus que el modo `mixed` puede cerrar, las que menos justificaciones necesitan
     * primero: con al menos un docente asignado, sin solicitudes aprobadas sin aplicar y fuera de `$keepOpen`.
     *
     * @param  list<string>  $keepOpen
     * @return Collection<int, AcademicLoad>
     */
    private function mixedCandidates(Term $term, Campus $campus, array $keepOpen): Collection
    {
        return $this->loadsWithGroups($term, $campus, LoadStatus::Staffing)
            ->reject(fn (AcademicLoad $load): bool => in_array($load->department->code, $keepOpen, true))
            ->reject(fn (AcademicLoad $load): bool => ChangeRequest::query()->where('term_id', $load->term_id)->where('modality_id', $load->modality_id)
                ->where('department_id', $load->department_id)->where('status', ChangeRequestStatus::Approved)->exists())
            ->filter(fn (AcademicLoad $load): bool => $this->groupsOf($load)->whereNotNull('groups.teacher_id')->exists())
            ->sortBy(fn (AcademicLoad $load): array => [$this->lacking($load)->reorder()->count(), $load->department->code])
            ->values();
    }

    /**
     * Rechaza las solicitudes pendientes del departamento y responde los acuerdos propuestos que lo tocan: los que pidió
     * y los que esperan su respuesta (AgreementsAnswered, y el préstamo pendiente que es HC-10).
     *
     * @throws ValidationException
     */
    private function clean(AcademicLoad $load): void
    {
        $head = $this->head($this->campusOf($load), $load->department_id);

        ChangeRequest::query()->join('groups', 'groups.id', '=', 'change_requests.group_id')->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('change_requests.term_id', $load->term_id)->whereIn('change_requests.campus_id', $this->campusIds())
            ->where('change_requests.department_id', $load->department_id)->where('change_requests.status', ChangeRequestStatus::Pending)
            ->orderBy('subjects.code')->orderBy('groups.code')->orderBy('change_requests.kind')->select('change_requests.*')->get()
            ->each(function (ChangeRequest $request) use ($head): void {
                app(RejectChangeRequest::class)->handle($request, $head, $this->next(self::REJECT_COMMENTS));
                $this->count('solicitudes rechazadas');
            });

        InterdepartmentalAgreement::query()->leftJoin('groups', 'groups.id', '=', 'interdepartmental_agreements.group_id')
            ->leftJoin('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('interdepartmental_agreements.term_id', $load->term_id)->whereIn('interdepartmental_agreements.campus_id', $this->campusIds())
            ->where('interdepartmental_agreements.status', AgreementStatus::Proposed)
            ->where(fn ($query) => $query->where('interdepartmental_agreements.from_department_id', $load->department_id)
                ->orWhere('interdepartmental_agreements.to_department_id', $load->department_id))
            ->orderBy('interdepartmental_agreements.kind')->orderBy('subjects.code')->orderBy('groups.code')
            ->orderBy('interdepartmental_agreements.day')->orderBy('interdepartmental_agreements.starts_at')
            ->select('interdepartmental_agreements.*')->get()
            ->each(fn (InterdepartmentalAgreement $agreement) => $this->answer($agreement, $this->campuses->firstWhere('id', $agreement->campus_id) ?? $this->campusOf($load)));
    }

    /**
     * Lo acepta el departamento que decide; si una regla no lo deja, lo cancela quien lo pidió o, si tampoco, lo rechaza
     * el que decide.
     *
     * @throws ValidationException
     */
    private function answer(InterdepartmentalAgreement $agreement, Campus $campus): void
    {
        $decider = $this->head($campus, $agreement->to_department_id);
        $requester = $this->head($campus, $agreement->from_department_id);
        $loan = $agreement->kind === AgreementKind::RoomLoan;

        try {
            DB::transaction(fn () => $loan
                ? app(AcceptRoomLoan::class)->handle($agreement, $decider)
                : app(AcceptSharedGroup::class)->handle($agreement, $decider));
            $this->count('acuerdos aceptados');

            return;
        } catch (ValidationException) {
            // Se cancela o se rechaza abajo.
        }

        try {
            DB::transaction(fn () => $loan
                ? app(CancelRoomLoan::class)->handle($agreement->refresh(), $requester)
                : app(WithdrawGroupQuotaProposal::class)->handle($agreement->refresh(), $requester));
        } catch (ValidationException) {
            $loan
                ? app(RejectRoomLoan::class)->handle($agreement->refresh(), self::AGREEMENT_REJECT_COMMENT, $decider)
                : app(RejectSharedGroup::class)->handle($agreement->refresh(), self::AGREEMENT_REJECT_COMMENT, $decider);
        }

        $this->count('acuerdos cancelados');
    }

    /**
     * Quita el docente de los grupos de la carga con un choque duro de docente (HC-01, HC-08, HC-09), uno por docente y
     * vuelta, hasta que no quede ninguno. Otro choque duro deja la carga sin cerrar.
     *
     * @throws RuntimeException con el choque que no se quita así
     */
    private function clearTeacherConflicts(AcademicLoad $load): void
    {
        $head = $this->head($this->campusOf($load), $load->department_id);
        $groupIds = $this->groupsOf($load)->pluck('groups.id')->flip();

        for ($round = 0; $round < self::CONFLICT_ROUNDS; $round++) {
            $validator = app(ScheduleValidator::class);
            $validator->forget();
            $hard = array_values(array_filter(
                $this->campuses->flatMap(fn (Campus $campus): array => $validator->validateLoad($load->term, $campus, $load->department)->violations())->all(),
                fn (Violation $violation): bool => $violation->severity === ConflictSeverity::Hard && $violation->groupId !== null && $groupIds->has($violation->groupId),
            ));

            if ($hard === []) {
                return;
            }

            $other = Arr::first($hard, fn (Violation $violation): bool => ! in_array($violation->rule, self::TEACHER_RULES, true));

            if ($other !== null) {
                throw new RuntimeException("{$other->rule}: {$other->message}");
            }

            $byTeacher = [];
            foreach ($hard as $violation) {
                $group = Group::query()->findOrFail($violation->groupId);

                if ($group->teacher_id !== null) {
                    $byTeacher[$group->teacher_id] ??= $group;
                }
            }

            foreach ($byTeacher as $group) {
                app(UnassignTeacher::class)->handle($group, $head, self::OVER_LIMIT_REASON);
            }
        }

        throw new RuntimeException('Siguen los choques duros de docente después de '.self::CONFLICT_ROUNDS.' vueltas.');
    }

    /**
     * Justifica cada grupo activo de la carga sin docente o con una sesión presencial sin salón, con el texto que toca.
     *
     * @throws ValidationException
     */
    private function justifyMissing(AcademicLoad $load): void
    {
        $head = $this->head($this->campusOf($load), $load->department_id);

        foreach ($this->lacking($load)->get() as $group) {
            $text = match (true) {
                $group->teacher_id === null && (bool) $group->getAttribute('missing_room') => $this->next(self::BOTH_JUSTIFICATIONS),
                $group->teacher_id === null => $this->next(self::TEACHER_JUSTIFICATIONS),
                default => $this->next(self::ROOM_JUSTIFICATIONS),
            };

            app(JustifyGroup::class)->handle($group, $text, $head);
            $this->count('grupos justificados');
        }
    }

    /**
     * Los grupos activos de la carga sin justificación a los que les falta docente o, si el campus usa salones, salón
     * en una sesión presencial, en orden de materia y grupo, con `missing_room`.
     *
     * @return Builder<Group>
     */
    private function lacking(AcademicLoad $load): Builder
    {
        $missingRoom = 'exists (select 1 from group_sessions s where s.group_id = groups.id and s.is_active and s.room_id is null and s.kind <> ?)';
        $usesRooms = $load->modality->profile->usesRooms;

        return $this->groupsOf($load)->whereNull('groups.close_justification')
            ->selectRaw(($usesRooms ? $missingRoom : 'false').' as missing_room', $usesRooms ? [SessionKind::Virtual->value] : [])
            ->where(fn ($query) => $query->whereNull('groups.teacher_id')
                ->when($usesRooms, fn ($query) => $query->orWhereRaw($missingRoom, [SessionKind::Virtual->value])));
    }

    /**
     * Una sesión presencial con salón de un grupo de las cargas (del mismo periodo y campus): primero las de
     * laboratorio, y los salones del propio departamento o sin dueño antes que los prestados; en orden de departamento,
     * materia, grupo, día y hora.
     *
     * @param  non-empty-list<AcademicLoad>  $loads
     */
    private function roomToFree(array $loads): ?GroupSession
    {
        return GroupSession::query()
            ->join('groups', 'groups.id', '=', 'group_sessions.group_id')
            ->join('departments', 'departments.id', '=', 'groups.department_id')
            ->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->join('rooms', 'rooms.id', '=', 'group_sessions.room_id')
            ->where('groups.term_id', $loads[0]->term_id)->whereIn('groups.campus_id', $this->campusIds())
            ->whereIn('groups.department_id', array_map(fn (AcademicLoad $load): string => $load->department_id, $loads))
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->where('group_sessions.is_active', true)->where('group_sessions.kind', '<>', SessionKind::Virtual->value)
            ->orderByRaw('case when rooms.type = ? then 0 else 1 end', [RoomType::Laboratorio->value])
            ->orderByRaw('case when rooms.department_id is null or rooms.department_id = groups.department_id then 0 else 1 end')
            ->orderBy('departments.code')->orderBy('subjects.code')->orderBy('groups.code')->orderBy('group_sessions.day')->orderBy('group_sessions.starts_at')
            ->select('group_sessions.*', 'rooms.type as room_type')
            ->first();
    }

    /**
     * Las cargas de la modalidad del campus en `$status` con grupos activos en el campus (la carga es por departamento y
     * modalidad, M1), con su departamento y modalidad, en orden de departamento.
     *
     * @return Collection<int, AcademicLoad>
     */
    private function loadsWithGroups(Term $term, Campus $campus, LoadStatus $status): Collection
    {
        return AcademicLoad::query()->with(['department', 'modality', 'term'])
            ->where('term_id', $term->id)->where('modality_id', $campus->modality_id)->where('status', $status)
            ->whereExists(Group::query()->active()->selectRaw('1')
                ->whereColumn('groups.term_id', 'academic_loads.term_id')
                ->whereColumn('groups.modality_id', 'academic_loads.modality_id')
                ->whereColumn('groups.department_id', 'academic_loads.department_id')
                ->whereIn('groups.campus_id', $this->campusIds())
                ->toBase())
            ->get()->sortBy('department.code')->values();
    }

    /**
     * Los grupos activos de la carga, en orden de materia y grupo.
     *
     * @return Builder<Group>
     */
    private function groupsOf(AcademicLoad $load): Builder
    {
        return Group::query()->active()->join('subjects', 'subjects.id', '=', 'groups.subject_id')
            ->where('groups.term_id', $load->term_id)->whereIn('groups.campus_id', $this->campusIds())->where('groups.department_id', $load->department_id)
            ->orderBy('subjects.code')->orderBy('groups.code')->select('groups.*');
    }

    /**
     * El campus para preguntar por los titulares de la carga: el primero de los que se siembran ahora (la carga es por
     * departamento y modalidad, M1; sus permisos no piden campus).
     */
    private function campusOf(AcademicLoad $load): Campus
    {
        return $this->campuses->first() ?? throw new RuntimeException("No hay campus en curso para la carga de {$load->department_id}.");
    }

    /**
     * Los ids de los campus que se siembran ahora: los grupos, solicitudes y acuerdos de la carga que se tocan.
     *
     * @return list<string>
     */
    private function campusIds(): array
    {
        return $this->campuses->pluck('id')->all();
    }

    /**
     * Los campus que se siembran ahora, para los mensajes: «C1 y C2».
     */
    private function where(): string
    {
        return $this->campuses->pluck('code')->join(', ', ' y ');
    }

    private function head(Campus $campus, string $departmentId): User
    {
        return $this->holder(AcademicLoadPermission::LoadClose, $campus, $departmentId) ?? $this->admin;
    }

    private function subdireccion(Campus $campus, string $departmentId): User
    {
        return $this->holder(AcademicLoadPermission::LoadReopen, $campus, $departmentId) ?? $this->admin;
    }

    private function holder(AcademicLoadPermission $permission, Campus $campus, string $departmentId): ?User
    {
        $key = "{$permission->value}|{$campus->id}|{$departmentId}";

        if (! array_key_exists($key, $this->holders)) {
            $this->holders[$key] = app(RecipientsQuery::class)->holders($permission, Target::of(modality: $campus->modality_id, campus: in_array(Dimension::Campus, $permission->dimensions(), true) ? $campus->id : null, unit: $departmentId))[0]['user'] ?? null;
        }

        return $this->holders[$key];
    }

    /**
     * Corre `$work` y, si una Action lo rechaza, detiene el seeder con `$what` y sus motivos.
     *
     * @throws RuntimeException
     */
    private function orFail(Closure $work, string $what): mixed
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            throw new RuntimeException("{$what}: ".$this->reason($exception), previous: $exception);
        }
    }

    private function reason(ValidationException|RuntimeException $exception): string
    {
        return $exception instanceof ValidationException
            ? implode(' ', Arr::flatten($exception->errors()))
            : $exception->getMessage();
    }

    /**
     * El siguiente texto de la lista, en orden y dando la vuelta: así cada corrida da los mismos.
     *
     * @param  list<string>  $pool
     */
    private function next(array $pool): string
    {
        $key = $pool[0];
        $index = $this->cursors[$key] ?? 0;
        $this->cursors[$key] = $index + 1;

        return $pool[$index % count($pool)];
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    /**
     * @param  array<string, int>  $before
     */
    private function delta(array $before, string $key): int
    {
        return ($this->counts[$key] ?? 0) - ($before[$key] ?? 0);
    }
}
