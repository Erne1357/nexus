<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalPackage;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Rules\SuggestedGroups;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\GroupCapacity;
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
 * Los grupos de paquetes (tarea 5.6b; reglas-de-negocio.md, «Propuesta de carrera»): por cada paquete que lleva la materia y cada
 * uno de los que abre el campus de la fila (n de 1 a la cuenta), un grupo con `package_id` y `package_number = n`, y los
 * sueltos (`requested_groups` menos `package_groups`) sin paquete. La cohorte del motor es `package:<package_id>:<campus_code>:<package_number>`
 * (fase 6); aquí solo se guarda.
 *
 * Es idempotente porque lo aceptado ya no cambia: por fila mira los grupos que ya nacieron de ella, en cualquier
 * estado (un jefe pudo cancelar uno a propósito), y crea solo los que faltan. En una transacción bloquea las filas que
 * lee, así que dos llamadas a la vez no duplican, y toma un bloqueo de aviso por periodo y materia para que las claves
 * provisionales de dos propuestas de carreras distintas no choquen.
 *
 * La capacidad es la de GroupCapacity: la del salón por defecto de la materia en el campus de la fila (el de menor
 * prioridad, sin contar los borrados); sin él, el tamaño objetivo de SuggestedGroups (35, o 25 con laboratorio). El
 * cupo de la carrera dueña nace igual a la capacidad. Cada grupo deja su entrada en la bitácora, con su campus y su
 * modalidad, y, después del commit y
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
                $slots = $this->missingSlots($item);

                if ($slots !== []) {
                    $created = $created->concat($this->createFor($item, $proposal, $slots, $sizes, $user));
                }
            }

            if ($created->isNotEmpty()) {
                GroupsCreated::dispatch($proposal->term_id, $proposal->modality_id, $created->pluck('id')->all());
            }

            return $created->values();
        });
    }

    /**
     * Los grupos que le faltan a la fila (tarea 5.6b): uno por cada paquete que lleva la materia y cada uno de los que abre
     * el campus (`package_number` de 1 a la cuenta), más los sueltos (`requested_groups` menos `package_groups`), sin paquete.
     * Lo que ya nació de la fila, en cualquier estado, no se repite.
     *
     * @return list<array{package_id: ?string, package_number: ?int}>
     */
    private function missingSlots(ProposalItem $item): array
    {
        $existing = Group::query()->where('proposal_item_id', $item->getKey())->get(['package_id', 'package_number']);
        $taken = $existing->whereNotNull('package_id')->map(fn (Group $group): string => $group->package_id.'|'.$group->package_number)->all();
        $slots = [];

        $packages = ProposalPackage::query()
            ->join('proposal_package_subjects as ps', 'ps.proposal_package_id', '=', 'proposal_packages.id')
            ->join('proposal_package_campuses as pc', 'pc.proposal_package_id', '=', 'proposal_packages.id')
            ->where('proposal_packages.career_proposal_id', $item->career_proposal_id)
            ->where('ps.subject_id', $item->subject_id)
            ->where('pc.campus_id', $item->campus_id)
            ->where('pc.count', '>', 0)
            ->orderBy('proposal_packages.position')
            ->orderBy('proposal_packages.code')
            ->toBase()
            ->get(['proposal_packages.id', 'pc.count']);

        $desired = 0;

        foreach ($packages as $package) {
            for ($number = 1; $number <= $package->count && $desired < $item->package_groups; $number++) {
                $desired++;

                if (! in_array($package->id.'|'.$number, $taken, true)) {
                    $slots[] = ['package_id' => $package->id, 'package_number' => $number];
                }
            }
        }

        $loose = max(0, $item->requested_groups - $desired) - $existing->whereNull('package_id')->count();

        for ($i = 0; $i < $loose; $i++) {
            $slots[] = ['package_id' => null, 'package_number' => null];
        }

        return $slots;
    }

    /**
     * @param  list<array{package_id: ?string, package_number: ?int}>  $slots
     * @return list<Group>
     */
    private function createFor(ProposalItem $item, CareerProposal $proposal, array $slots, SuggestedGroups $sizes, ?User $user): array
    {
        $subject = $item->subject;

        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$proposal->term_id}:{$subject->getKey()}"]);

        $taken = Group::query()->active()
            ->where('term_id', $proposal->term_id)
            ->where('subject_id', $subject->getKey())
            ->pluck('code')->all();
        $capacity = GroupCapacity::for($subject, $item->campus_id, $sizes);
        $groups = [];

        foreach ($slots as $slot) {
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
                'package_id' => $slot['package_id'],
                'package_number' => $slot['package_number'],
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
                    'package_id' => $group->package_id,
                    'package_number' => $group->package_number,
                ]])
                ->log("Creó el grupo {$group->code} de {$subject->name}");

            $groups[] = $group;
        }

        return $groups;
    }
}
