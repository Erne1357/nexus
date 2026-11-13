<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AgreementKind;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;

/**
 * Lo que muestra el panel «Cupos por carrera» del grupo (tarea 3.6, HC-07), para que Blade no consulte: la dueña, los
 * cupos aceptados y los acuerdos propuestos vivos (con el departamento que decide), la capacidad, lo repartido en
 * cupos (`counted`) y lo que apartan los propuestos (`proposed`; HC-07 suma los dos, confirmado el 8 de noviembre de
 * 2026), y las carreras que se pueden agregar: activas, ofrecidas
 * en el campus del grupo y sin cupo ni acuerdo vivo en él, cada una con su departamento y si necesita acuerdo (es de
 * otro departamento que el que imparte).
 *
 * @phpstan-type Dept array{code: string, name: string}
 * @phpstan-type Row array{career_id: string, code: string, name: string, seats: int, state: 'owner'|'accepted'|'proposed', decides: Dept|null, agreement_id: string|null}
 * @phpstan-type Addable array{id: string, code: string, name: string, department: Dept, needs_agreement: bool}
 */
final class GroupQuotasQuery
{
    /**
     * Si el grupo tiene un acuerdo de grupo compartido vivo (propuesto o aceptado): con él no cambia de campus (MS25,
     * confirmado el 12 de noviembre de 2026). Lo leen el cajón del grupo antes del clic (ScheduleEditorQuery) y
     * MoveGroupToCampus al cambiarlo.
     */
    public function hasLiveSharedGroup(Group $group): bool
    {
        return InterdepartmentalAgreement::query()
            ->where('kind', AgreementKind::SharedGroup->value)
            ->where('group_id', $group->getKey())
            ->live()
            ->exists();
    }

    /**
     * Las claves de las carreras con cupo en el grupo, sin la dueña, que `$campusId` no ofrece (`career_campus` activa),
     * por clave: con alguna el grupo no se cambia a ese campus (M15, confirmado el 13 de noviembre de 2026). La dueña la
     * revisa HC-13. Lo leen el cajón del grupo antes del clic (ScheduleEditorQuery) y MoveGroupToCampus al cambiarlo.
     *
     * @return list<string>
     */
    public function careersNotOfferedIn(Group $group, string $campusId): array
    {
        return Career::query()->withTrashed()
            ->whereIn('id', GroupQuota::query()->where('group_id', $group->getKey())->where('is_owner', false)->select('career_id'))
            ->whereDoesntHave('offerings', fn ($query) => $query->where('campus_id', $campusId)->where('is_active', true))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /**
     * El motivo de M15 en español, sin el id de la regla: «Quita primero el cupo de ITIC: Campus I no ofrece esa carrera»
     * (o «los cupos de IIND y ITIC … esas carreras»).
     *
     * @param  non-empty-list<string>  $careers
     */
    public static function notOfferedReason(array $careers, string $campusName): string
    {
        $last = array_pop($careers);
        $list = $careers === [] ? $last : implode(', ', $careers).' y '.$last;

        return $careers === []
            ? "Quita primero el cupo de {$list}: {$campusName} no ofrece esa carrera"
            : "Quita primero los cupos de {$list}: {$campusName} no ofrece esas carreras";
    }

    /**
     * @return array{capacity: int, counted: int, proposed: int, department: Dept, rows: list<Row>, addable: list<Addable>}
     */
    public function for(Group $group): array
    {
        $quotas = GroupQuota::query()->with(['career' => fn ($query) => $query->withTrashed()])->where('group_id', $group->id)->get();
        $agreements = InterdepartmentalAgreement::query()
            ->with(['career' => fn ($query) => $query->withTrashed(), 'toDepartment' => fn ($query) => $query->withTrashed()])
            ->where('kind', AgreementKind::SharedGroup->value)
            ->where('group_id', $group->id)
            ->where('status', AgreementStatus::Proposed->value)
            ->get();

        $rows = [];

        foreach ($quotas->sortBy(fn (GroupQuota $quota): string => ($quota->is_owner ? '0' : '1').$quota->career->code) as $quota) {
            $rows[] = $this->row($quota->career, $quota->max_seats, $quota->is_owner ? 'owner' : 'accepted', null, null);
        }

        foreach ($agreements->sortBy(fn (InterdepartmentalAgreement $agreement): string => $agreement->career->code) as $agreement) {
            $rows[] = $this->row($agreement->career, (int) $agreement->max_seats, 'proposed', $this->department($agreement->toDepartment), $agreement->id);
        }

        return [
            'capacity' => $group->capacity,
            'counted' => (int) $quotas->sum('max_seats'),
            'proposed' => (int) $agreements->sum('max_seats'),
            'department' => $this->department(KeyLookup::findOrFail(Department::withTrashed(), $group->department_id)),
            'rows' => $rows,
            'addable' => $this->addable($group, array_merge($quotas->pluck('career_id')->all(), $agreements->pluck('career_id')->all())),
        ];
    }

    /**
     * @param  'owner'|'accepted'|'proposed'  $state
     * @param  array{code: string, name: string}|null  $decides
     * @return array{career_id: string, code: string, name: string, seats: int, state: 'owner'|'accepted'|'proposed', decides: array{code: string, name: string}|null, agreement_id: string|null}
     */
    private function row(Career $career, int $seats, string $state, ?array $decides, ?string $agreementId): array
    {
        return [
            'career_id' => $career->id,
            'code' => $career->code,
            'name' => $career->name,
            'seats' => $seats,
            'state' => $state,
            'decides' => $decides,
            'agreement_id' => $agreementId,
        ];
    }

    /**
     * @param  list<string>  $taken
     * @return list<array{id: string, code: string, name: string, department: array{code: string, name: string}, needs_agreement: bool}>
     */
    private function addable(Group $group, array $taken): array
    {
        return Career::query()
            ->with(['department' => fn ($query) => $query->withTrashed()])
            ->where('is_active', true)
            ->whereNotIn('id', $taken)
            ->whereHas('offerings', fn ($query) => $query->where('campus_id', $group->campus_id)->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->map(fn (Career $career): array => [
                'id' => $career->id,
                'code' => $career->code,
                'name' => $career->name,
                'department' => $this->department($career->department),
                'needs_agreement' => $career->department_id !== $group->department_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{code: string, name: string}
     */
    private function department(Department $department): array
    {
        return ['code' => $department->code, 'name' => $department->name];
    }
}
