<?php

namespace App\Modules\Academic\Authorization;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Models\Campus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Los códigos de carrera para el resumen de puestos de la credencial: una consulta por los ids pedidos, también de carreras borradas (un nombramiento viejo las sigue nombrando), y las
 * carreras que se ofrecen en un campus (o, sin campus, en algún campus activo) para elegir una al nombrar.
 */
final class AcademicScopeLabels implements ScopeLabels
{
    public function careers(array $careerIds): array
    {
        if ($careerIds === []) {
            return [];
        }

        return Career::withTrashed()->whereKey($careerIds)->pluck('code', 'id')->all();
    }

    public function careerOptions(?string $campusId): array
    {
        return Career::query()
            ->whereIn('id', $this->offeredIn($campusId)->select('career_id'))
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Career $career): array => [$career->id => "{$career->code} · {$career->name}"])
            ->all();
    }

    public function careerGroups(?string $campusId): array
    {
        $careers = Career::query()
            ->where('is_active', true)
            ->whereIn('id', $this->offeredIn($campusId)->select('career_id'))
            ->with(['department' => fn ($department) => $department->withTrashed()])
            ->get(['id', 'code', 'name', 'department_id']);

        return $careers->groupBy('department_id')
            ->map(fn ($group): array => [
                'id' => (string) $group->first()->department_id,
                'code' => (string) $group->first()->department?->code,
                'name' => (string) $group->first()->department?->name,
                'careers' => $group->sortBy('code')->map(fn (Career $career): array => ['id' => $career->id, 'code' => $career->code, 'name' => $career->name])->values()->all(),
            ])
            ->sortBy(fn (array $department): string => $department['code'].'|'.$department['name'])
            ->values()
            ->all();
    }

    /**
     * @return Builder<CareerCampus>
     */
    private function offeredIn(?string $campusId): Builder
    {
        return CareerCampus::query()
            ->where('is_active', true)
            ->when(
                $campusId === null,
                fn (Builder $query) => $query->whereIn('campus_id', Campus::query()->where('is_active', true)->select('id')),
                fn (Builder $query) => $query->where('campus_id', $campusId),
            );
    }
}
