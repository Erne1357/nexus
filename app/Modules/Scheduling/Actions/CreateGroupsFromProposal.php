<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Rules\SuggestedGroups;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\GroupCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Crea los grupos de la parte de una propuesta de carrera que el departamento acaba de aceptar (reglas-de-negocio.md,
 * "Grupos y horarios"; tarea 3.3; confirmado el 7 de noviembre de 2026): por cada fila de la propuesta cuya materia es
 * del departamento y pide grupos, uno por grupo pedido, con la carrera de la propuesta como dueña, el periodo y la
 * modalidad de la propuesta, el campus de la fila (M8: la fila de C2 da grupos de C2) y la materia, plan y semestre de
 * la fila. Una fila sin campus (la columna única de una modalidad sin salones, MS5) todavía no da grupos: el grupo sin
 * campus llega con las modalidades sin salones (MS4; supuesto por confirmar).
 *
 * Es idempotente porque lo aceptado ya no cambia: por fila cuenta los grupos que ya nacieron de ella, en cualquier
 * estado (un jefe pudo cancelar uno a propósito), y crea solo los que faltan. En una transacción bloquea las filas que
 * lee, así que dos llamadas a la vez no duplican, y toma un bloqueo de aviso por periodo y materia para que las claves
 * provisionales de dos propuestas de carreras distintas no choquen.
 *
 * La capacidad es la del salón por defecto de la materia en el campus de la fila (el de menor prioridad, sin contar los
 * borrados); sin él, el tamaño objetivo de SuggestedGroups (35, o 25 con laboratorio). El cupo de la carrera dueña nace
 * igual a la capacidad. Cada grupo deja su entrada en la bitácora, con su campus y su modalidad, y, después del commit y
 * solo si nació alguno, GroupsCreated avisa una vez por decisión con la modalidad de la propuesta y todos los grupos,
 * de cualquier campus: quien lo escucha lee el campus de cada grupo.
 */
final class CreateGroupsFromProposal
{
    /**
     * @return Collection<int, Group>
     */
    public function handle(ProposalReview $review, ?User $user = null): Collection
    {
        return DB::transaction(function () use ($review, $user): Collection {
            $proposal = CareerProposal::query()->findOrFail($review->career_proposal_id);
            $sizes = SuggestedGroups::fromSettings();

            $items = ProposalItem::query()
                ->with('subject')
                ->where('career_proposal_id', $proposal->getKey())
                ->where('requested_groups', '>', 0)
                ->whereNotNull('campus_id')
                ->whereIn('subject_id', Subject::query()->where('department_id', $review->department_id)->select('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $created = new Collection;

            foreach ($items as $item) {
                $missing = $item->requested_groups - Group::query()->where('proposal_item_id', $item->getKey())->count();

                if ($missing > 0) {
                    $created = $created->concat($this->createFor($item, $proposal, $missing, $sizes, $user));
                }
            }

            if ($created->isNotEmpty()) {
                GroupsCreated::dispatch($proposal->term_id, $proposal->modality_id, $created->pluck('id')->all());
            }

            return $created->values();
        });
    }

    /**
     * @return list<Group>
     */
    private function createFor(ProposalItem $item, CareerProposal $proposal, int $count, SuggestedGroups $sizes, ?User $user): array
    {
        $subject = $item->subject;

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$proposal->term_id}:{$subject->getKey()}"]);

        $taken = Group::query()->active()
            ->where('term_id', $proposal->term_id)
            ->where('subject_id', $subject->getKey())
            ->pluck('code')->all();
        $capacity = $this->capacityFor($subject, $item->campus_id, $sizes);
        $groups = [];

        for ($i = 0; $i < $count; $i++) {
            $code = GroupCode::provisional($taken);
            $taken[] = $code;

            $group = Group::query()->create([
                'term_id' => $proposal->term_id,
                'campus_id' => $item->campus_id,
                'modality_id' => $proposal->modality_id,
                'subject_id' => $subject->getKey(),
                'department_id' => $subject->department_id,
                'owner_career_id' => $proposal->career_id,
                'study_plan_id' => $item->study_plan_id,
                'semester' => $item->semester,
                'code' => $code,
                'capacity' => $capacity,
                'status' => GroupStatus::Planned,
                'proposal_item_id' => $item->getKey(),
            ]);

            GroupQuota::query()->create([
                'group_id' => $group->getKey(),
                'career_id' => $proposal->career_id,
                'max_seats' => $capacity,
                'is_owner' => true,
            ]);

            activity()
                ->causedBy($user)
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
                    'proposal_item_id' => $group->proposal_item_id,
                ]])
                ->log("Creó el grupo {$group->code} de {$subject->name}");

            $groups[] = $group;
        }

        return $groups;
    }

    private function capacityFor(Subject $subject, string $campusId, SuggestedGroups $sizes): int
    {
        $default = SubjectRoomDefault::query()
            ->with('room')
            ->where('subject_id', $subject->getKey())
            ->where('campus_id', $campusId)
            ->orderBy('priority')
            ->first();

        return $default?->room?->capacity ?? $sizes->sizeFor((bool) $subject->needs_lab);
    }
}
