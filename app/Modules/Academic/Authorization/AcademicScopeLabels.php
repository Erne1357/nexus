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
        $offered = CareerCampus::query()
            ->where('is_active', true)
            ->when(
                $campusId === null,
                fn (Builder $query) => $query->whereIn('campus_id', Campus::query()->where('is_active', true)->select('id')),
                fn (Builder $query) => $query->where('campus_id', $campusId),
            );

        return Career::query()
            ->where('is_active', true)
            ->whereIn('id', $offered->select('career_id'))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Career $career): array => [$career->id => "{$career->code} · {$career->name}"])
            ->all();
    }
}
