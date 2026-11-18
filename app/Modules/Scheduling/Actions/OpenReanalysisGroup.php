<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Sessions\ReplaceGroupSessions;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Queries\NewGroupPlacementQuery;
use App\Modules\Scheduling\Rules\GroupCode;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupSpec;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use App\Modules\Scheduling\Validation\SessionCandidate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Aplica «Abrir un grupo» del re-análisis (tarea 8.3b): crea el grupo nuevo de la sugerencia (`newGroup`) con su cupo y
 * sus sesiones en el horario y el salón que encontró el motor (NewGroupPlacement), todo en una transacción.
 *
 * - Autoriza como ApplyReanalysisSuggestion: ajusta el jefe del departamento de la carga (RA-07, `startReanalysis`) y, sobre
 *   el grupo que va a nacer (draft()), GroupPolicy para crearlo, acomodar sus sesiones, asignar salón y repartir cupos.
 * - Exige la carga en re-análisis, leída en compartido dentro de la transacción, y toma el candado de aviso de las claves
 *   del periodo y la materia (como CreateGroupsFromProposal).
 * - Revisa con los candados puestos que todo siga como cuando se calculó: los grupos vivos de la materia en el campus,
 *   bloqueados, son los mismos y con la misma capacidad y cupos (`expected`; si otra pestaña ya abrió uno o amplió otro,
 *   «Esto cambió…») y cada salón sigue libre a cada hora (NewGroupPlacementQuery::conflicts(): HC-02, HC-10 y HC-11;
 *   «Esto cambió desde que buscaste el lugar…»).
 * - Crea el grupo como CreateGroupsFromProposal: suelto (sin paquete ni fila de propuesta), planeado, con la clave
 *   provisional siguiente (al escribir sus sesiones toma la de su hora, AssignGroupCode), la capacidad de la sugerencia,
 *   el cupo de la carrera dueña y su bitácora; escribe sus sesiones con ReplaceGroupSessions (HC-04, salones del campus,
 *   exclusiones de la base, préstamos y su bitácora) y, después del commit, GroupsCreated.
 * - Sin docente: la asignación es de Staffing (Asignación docente).
 *
 * Las demás reglas duras (HC-03, por ejemplo, si a esa hora ya se acomodó otro grupo de su semestre) se guardan y quedan
 * marcadas como en el editor; el motor ya las revisó al proponer.
 */
final class OpenReanalysisGroup
{
    /** Lo que GroupPolicy pide sobre el grupo que va a nacer. */
    public const array ABILITIES = ['manage', 'editSessions', 'assignRoom', 'manageQuotas'];

    public const string CHANGED = 'Esto cambió desde que abriste las sugerencias; vuelve a abrirlas.';

    public function __construct(
        private readonly ReplaceGroupSessions $sessions,
        private readonly NewGroupPlacementQuery $placements,
    ) {}

    /**
     * @return Group el grupo nuevo, ya con la clave de su hora (AssignGroupCode, después del commit)
     *
     * @throws AuthorizationException si quien actúa no ajusta la carga o no puede crear el grupo
     * @throws ValidationException si la carga ya no está en re-análisis, algo cambió o una Action lo rechaza
     */
    public function handle(AcademicLoad $load, ReanalysisSuggestion $suggestion, NewGroupPlacement $placement, User $actor): Group
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('startReanalysis', $load);
        $spec = $suggestion->newGroup;

        if ($suggestion->kind !== ReanalysisSuggestionKind::OpenGroup || $spec === null) {
            throw ValidationException::withMessages(['suggestion' => 'Esta sugerencia no abre un grupo.']);
        }

        if (! $placement->isPlaced()) {
            throw ValidationException::withMessages(['suggestion' => 'El motor no le encontró lugar al grupo nuevo: no hay qué aplicar.']);
        }

        $this->authorizeGroup($gate, self::draft($load, $spec));

        $group = DB::transaction(function () use ($load, $suggestion, $spec, $placement, $actor): Group {
            $status = AcademicLoad::query()->whereKey($load->getKey())->sharedLock()->first(['id', 'status'])?->status;

            if ($status !== LoadStatus::Reanalysis) {
                throw ValidationException::withMessages(['suggestion' => 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.']);
            }

            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$load->term_id}:{$spec->subjectId}"]);
            $this->ensureUnchanged($load, $suggestion, $spec);
            $conflicts = $this->placements->conflicts($load, $spec, $placement);

            if ($conflicts !== []) {
                throw ValidationException::withMessages(['suggestion' => 'Esto cambió desde que buscaste el lugar: '.implode(' ', $conflicts).' Vuelve a buscar horario y salón.']);
            }

            $group = $this->create($load, $spec, $actor);

            $this->sessions->handle([$group->id => array_map(fn (array $session): SessionCandidate => new SessionCandidate(
                $session['day'], $session['startsAt'], $session['endsAt'], $session['roomId'], SessionKind::from($session['kind']),
            ), $placement->sessions)], $actor, 'desde la sugerencia del re-análisis');

            GroupsCreated::dispatch($load->term_id, $load->modality_id, [$group->id]);

            return $group;
        });

        return $group->refresh();
    }

    /**
     * El grupo que va a nacer, sin guardar: para preguntarle a GroupPolicy (la pantalla también lo usa para el motivo).
     */
    public static function draft(AcademicLoad $load, NewGroupSpec $spec): Group
    {
        return new Group([
            'term_id' => $load->term_id,
            'campus_id' => $spec->campusId,
            'modality_id' => $load->modality_id,
            'subject_id' => $spec->subjectId,
            'department_id' => $load->department_id,
            'owner_career_id' => $spec->careerId,
            'study_plan_id' => $spec->studyPlanId,
            'semester' => $spec->semester,
            'capacity' => $spec->capacity,
            'status' => GroupStatus::Planned,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeGroup(GateContract $gate, Group $draft): void
    {
        foreach (self::ABILITIES as $ability) {
            $gate->authorize($ability, $draft);
        }
    }

    /**
     * Los grupos vivos de la materia en el campus, bloqueados por id, son los que vio la sugerencia y con la misma capacidad
     * y cupos.
     *
     * @throws ValidationException
     */
    private function ensureUnchanged(AcademicLoad $load, ReanalysisSuggestion $suggestion, NewGroupSpec $spec): void
    {
        $groups = Group::query()
            ->active()
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->where('subject_id', $spec->subjectId)
            ->where('campus_id', $spec->campusId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'capacity'])
            ->keyBy('id');
        $quotas = GroupQuota::query()->whereIn('group_id', $groups->keys()->all())->get(['group_id', 'career_id', 'max_seats'])->groupBy('group_id');

        $now = $groups->map(function (Group $group) use ($quotas): array {
            $seats = ($quotas[$group->id] ?? collect())->mapWithKeys(fn (GroupQuota $quota): array => [$quota->career_id => (int) $quota->max_seats])->all();
            ksort($seats);

            return ['capacity' => $group->capacity, 'quotas' => $seats];
        })->all();
        $expected = array_map(function (array $group): array {
            ksort($group['quotas']);

            return $group;
        }, $suggestion->expected);
        ksort($now);
        ksort($expected);

        if ($now !== $expected) {
            throw ValidationException::withMessages(['suggestion' => self::CHANGED]);
        }
    }

    /**
     * Crea el grupo suelto con la clave provisional siguiente y el cupo de su carrera dueña, con su bitácora, como
     * CreateGroupsFromProposal.
     */
    private function create(AcademicLoad $load, NewGroupSpec $spec, User $actor): Group
    {
        $subject = Subject::withTrashed()->whereKey($spec->subjectId)->where('department_id', $load->department_id)->first()
            ?? throw ValidationException::withMessages(['suggestion' => 'La materia ya no es del departamento de la carga.']);
        $taken = Group::query()->active()->where('term_id', $load->term_id)->where('subject_id', $subject->id)->pluck('code')->all();

        $group = Group::query()->create([
            ...self::draft($load, $spec)->getAttributes(),
            'code' => GroupCode::provisional($taken),
        ]);

        GroupQuota::query()->create([
            'group_id' => $group->id,
            'career_id' => $spec->careerId,
            'max_seats' => $spec->seats,
            'is_owner' => true,
        ]);

        activity()
            ->causedBy($actor)
            ->performedOn($group)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => [
                'code' => $group->code,
                'term_id' => $group->term_id,
                'campus_id' => $group->campus_id,
                'modality_id' => $group->modality_id,
                'subject_id' => $group->subject_id,
                'owner_career_id' => $group->owner_career_id,
                'study_plan_id' => $group->study_plan_id,
                'semester' => $group->semester,
                'capacity' => $group->capacity,
                'quotas' => [$spec->careerId => $spec->seats],
            ]])
            ->withProperties(['origin' => 'reanalysis'])
            ->log("Abrió el grupo {$group->code} de {$subject->name} desde el re-análisis");

        return $group;
    }
}
