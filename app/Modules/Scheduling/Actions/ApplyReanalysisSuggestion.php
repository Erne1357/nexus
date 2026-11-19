<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Quotas\SetGroupQuotas;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Enums\ReanalysisSuggestionKind;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Rules\QuotaRules;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Aplica una sugerencia del re-análisis (tarea 8.3a, ReanalysisAdvisor) con las Actions de la fase 3, todas en una
 * transacción: si una falla, no queda nada a medias. Cada Action deja su bitácora y su aviso después del commit; los
 * avisos a docentes y carreras salen al republicar (RA-05, tarea 8.4), no aquí.
 *
 * - Autoriza a quien actúa, aunque la pantalla ya lo haya hecho: ajusta el jefe del departamento de la carga (RA-07,
 *   `startReanalysis` de AcademicLoadPolicy: el mismo permiso que inicia el re-análisis) y, sobre cada grupo que toca, el
 *   permiso de GroupPolicy de lo que hace (`manageQuotas`, `manage` para la capacidad, `assignRoom`, `cancel`).
 * - Exige la carga en re-análisis, leída en compartido dentro de la transacción, y que los grupos sean de la carga. Bloquea
 *   todos los grupos que toca, por id, antes de llamar a ninguna Action (dos cierres cruzados no se traban) y revisa que
 *   sigan como cuando se calculó la sugerencia (`expected`): dos peticiones encimadas no suman dos veces.
 * - **Ampliar cupo:** suma `$seats` (los de la sugerencia, o los que editó el jefe, de 1 a `maxSeats`) al cupo de la
 *   carrera en el grupo; si no caben, primero sube la capacidad a lo repartido más lo apartado (SetGroupCapacity: HC-05,
 *   HC-07) y luego reparte (SetGroupQuotas: HC-07).
 * - **Cambiar salón:** asigna a cada sesión de `moves` su salón (AssignSessionRoom: HC-02 no se guarda; uno de otro
 *   departamento pide su préstamo, HC-10) y luego amplía como arriba.
 * - **Cerrar grupo:** suma los lugares de `relocations` a los grupos que los reciben (SetGroupQuotas) y cancela el grupo
 *   (CancelGroup: pierde su horario y sus acuerdos vivos). Uno con docente no se cierra hasta quitárselo en Asignación
 *   docente (`blockedReason`; CancelGroup tampoco lo deja).
 * - **Abrir grupo** no se aplica aquí: lleva el horario y el salón del motor y lo crea OpenReanalysisGroup (tarea 8.3b).
 *
 * Los lugares se suman a lo que el grupo tenga en ese momento, no a lo que vio la sugerencia. Un deadlock de la base sale
 * como error de validación (LoadEditability::guard()), no como un 500.
 */
final class ApplyReanalysisSuggestion
{
    public function __construct(
        private readonly SetGroupQuotas $quotas,
        private readonly SetGroupCapacity $capacity,
        private readonly AssignSessionRoom $rooms,
        private readonly CancelGroup $cancel,
        private readonly QuotaRules $rules,
        private readonly LoadEditability $editability,
    ) {}

    /**
     * @throws AuthorizationException si quien actúa no ajusta la carga o no puede hacer eso con un grupo
     * @throws ValidationException si la carga ya no está en re-análisis o una Action de la fase 3 lo rechaza
     */
    public function handle(AcademicLoad $load, ReanalysisSuggestion $suggestion, User $actor, ?int $seats = null): void
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('startReanalysis', $load);

        if ($suggestion->kind === ReanalysisSuggestionKind::OpenGroup) {
            throw ValidationException::withMessages(['suggestion' => 'Abrir un grupo se aplica con el horario y el salón que encuentre el motor.']);
        }

        if ($suggestion->blockedReason !== null) {
            throw ValidationException::withMessages(['suggestion' => $suggestion->blockedReason]);
        }

        $seats ??= $suggestion->seats;

        if ($suggestion->kind->addsSeats() && ($seats < 1 || $seats > $suggestion->maxSeats)) {
            throw ValidationException::withMessages(['seats' => "Los lugares van de 1 a {$suggestion->maxSeats}."]);
        }

        $this->editability->guard(fn () => DB::transaction(function () use ($load, $suggestion, $actor, $seats, $gate): void {
            $status = AcademicLoad::query()->whereKey($load->getKey())->sharedLock()->first(['id', 'status'])?->status;

            if ($status !== LoadStatus::Reanalysis) {
                throw ValidationException::withMessages(['suggestion' => 'La carga ya no está en re-análisis: las sugerencias ya no se aplican.']);
            }

            $groups = $this->groupsOf($load, $suggestion);
            $this->ensureUnchanged($suggestion, $groups);
            $group = $groups[$suggestion->groupId];

            match ($suggestion->kind) {
                ReanalysisSuggestionKind::ExpandQuota => $this->addSeats($group, (string) $suggestion->careerId, $seats, $actor, $gate),
                ReanalysisSuggestionKind::ChangeRoom => $this->changeRoom($group, $suggestion, $seats, $actor, $gate),
                ReanalysisSuggestionKind::CloseGroup => $this->close($group, $groups, $suggestion, $actor, $gate),
                ReanalysisSuggestionKind::OpenGroup => null,
            };
        }), 'suggestion');
    }

    /**
     * Lo que GroupPolicy pide sobre cada grupo que toca la sugerencia, por id: ampliar, `manageQuotas` (y `manage` si sube
     * la capacidad); cambiar salón, además `assignRoom`; cerrar, `cancel` y `manageQuotas` en el suyo y `manageQuotas` en
     * los que reciben sus lugares. Abrir un grupo no toca ninguno (OpenReanalysisGroup::ABILITIES, sobre el que nace). La
     * pantalla lo usa para el motivo de lo que no se puede y el lote para autorizar todo antes de escribir.
     *
     * @return array<string, list<string>>
     */
    public static function abilities(ReanalysisSuggestion $suggestion): array
    {
        $capacity = $suggestion->raisesCapacity ? ['manage'] : [];
        $own = match ($suggestion->kind) {
            ReanalysisSuggestionKind::ExpandQuota => ['manageQuotas', ...$capacity],
            ReanalysisSuggestionKind::ChangeRoom => ['assignRoom', 'manageQuotas', ...$capacity],
            ReanalysisSuggestionKind::CloseGroup => ['cancel', 'manageQuotas'],
            ReanalysisSuggestionKind::OpenGroup => [],
        };
        $abilities = [];

        foreach ($suggestion->groupIds() as $groupId) {
            $abilities[$groupId] = $groupId === $suggestion->groupId ? $own : ['manageQuotas'];
        }

        return $abilities;
    }

    /**
     * Los grupos que toca la sugerencia, vivos y de la carga, bloqueados (FOR UPDATE, por id, después de la carga: el orden
     * de LoadEditability) para que sus cupos y su capacidad no cambien entre que se leen y se escriben.
     *
     * @return Collection<string, Group>
     *
     * @throws ValidationException
     */
    private function groupsOf(AcademicLoad $load, ReanalysisSuggestion $suggestion): Collection
    {
        $groups = Group::query()
            ->active()
            ->whereIn('id', $suggestion->groupIds())
            ->where('term_id', $load->term_id)
            ->where('modality_id', $load->modality_id)
            ->where('department_id', $load->department_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($groups->count() !== count($suggestion->groupIds())) {
            throw ValidationException::withMessages(['suggestion' => 'Un grupo de la sugerencia ya no está en la carga: vuelve a abrir las sugerencias.']);
        }

        return $groups->toBase();
    }

    /**
     * Con los grupos ya bloqueados, su capacidad, sus cupos y el salón de las sesiones que se mueven siguen como cuando se
     * calculó la sugerencia: si no, otra petición los cambió (o ya la aplicó) y no se escribe nada.
     *
     * @param  Collection<string, Group>  $groups
     *
     * @throws ValidationException
     */
    private function ensureUnchanged(ReanalysisSuggestion $suggestion, Collection $groups): void
    {
        $changed = false;

        foreach ($suggestion->expected as $groupId => $expected) {
            $quotas = $this->quotasOf($groups[$groupId]);
            ksort($quotas);
            $before = $expected['quotas'];
            ksort($before);
            $changed = $changed || $groups[$groupId]->capacity !== $expected['capacity'] || $quotas !== $before;
        }

        foreach ($suggestion->moves as $move) {
            $changed = $changed || GroupSession::query()->whereKey($move['sessionId'])->value('room_id') !== $move['fromId'];
        }

        if ($changed) {
            throw ValidationException::withMessages(['suggestion' => 'Esto cambió desde que abriste las sugerencias; vuelve a abrirlas.']);
        }
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function addSeats(Group $group, string $careerId, int $seats, User $actor, GateContract $gate): void
    {
        $gate->authorize('manageQuotas', $group);
        $quotas = $this->quotasOf($group);

        if (! isset($quotas[$careerId])) {
            throw ValidationException::withMessages(['suggestion' => "La carrera ya no tiene cupo en el grupo {$group->code}."]);
        }

        $quotas[$careerId] += $seats;
        $needed = array_sum($quotas) + $this->rules->heldSeats($group);

        if ($needed > $group->capacity) {
            $gate->authorize('manage', $group);
            $this->capacity->handle($group, $needed, $actor);
        }

        $this->quotas->handle($group->fresh(), $quotas, $actor);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function changeRoom(Group $group, ReanalysisSuggestion $suggestion, int $seats, User $actor, GateContract $gate): void
    {
        $gate->authorize('assignRoom', $group);

        foreach ($suggestion->moves as $move) {
            $session = GroupSession::query()->whereKey($move['sessionId'])->where('group_id', $group->id)->first();
            $room = Room::query()->whereKey($move['to']->id)->where('is_active', true)->first();

            if ($session === null || $room === null) {
                throw ValidationException::withMessages(['suggestion' => 'La sesión o el salón de la sugerencia ya no están: vuelve a abrir las sugerencias.']);
            }

            $this->rooms->handle($session, $room, $actor);
        }

        $this->addSeats($group->fresh(), (string) $suggestion->careerId, $seats, $actor, $gate);
    }

    /**
     * @param  Collection<string, Group>  $groups
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function close(Group $group, Collection $groups, ReanalysisSuggestion $suggestion, User $actor, GateContract $gate): void
    {
        $gate->authorize('cancel', $group);

        foreach (collect($suggestion->relocations)->groupBy('groupId') as $receiverId => $relocations) {
            $receiver = $groups[$receiverId];
            $gate->authorize('manageQuotas', $receiver);
            $quotas = $this->quotasOf($receiver);

            foreach ($relocations as $relocation) {
                if (! isset($quotas[$relocation['careerId']])) {
                    throw ValidationException::withMessages(['suggestion' => "{$relocation['careerCode']} ya no tiene cupo en el grupo {$receiver->code}."]);
                }

                $quotas[$relocation['careerId']] += $relocation['seats'];
            }

            $this->quotas->handle($receiver, $quotas, $actor);
        }

        $this->cancel->handle($group, $actor);
    }

    /**
     * @return array<string, int>
     */
    private function quotasOf(Group $group): array
    {
        return GroupQuota::query()->where('group_id', $group->id)->pluck('max_seats', 'career_id')->map(fn (mixed $seats): int => (int) $seats)->all();
    }
}
