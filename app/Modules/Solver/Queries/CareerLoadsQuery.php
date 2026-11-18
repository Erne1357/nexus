<?php

namespace App\Modules\Solver\Queries;

use App\Modules\Scheduling\Models\Group;

/**
 * Qué cargas tocan una carrera (tarea 6.10; decisión del usuario del 17 de noviembre de 2026): el coordinador ve las
 * corridas de departamento de las cargas que tocan su carrera. Una carga (departamento en una modalidad y un periodo)
 * toca una carrera si tiene al menos un grupo sin cancelar cuya carrera dueña es esa.
 *
 * SolverRunPolicy::view la pregunta por cada corrida de departamento que ve un coordinador, así que recuerda la
 * respuesta por carga: el provider de Solver la registra `scoped` (una instancia por petición, sin estado estático,
 * por Octane), y el historial y cada tic de la página consultan una vez por carga.
 */
final class CareerLoadsQuery
{
    /** @var array<string, list<string>> las carreras de cada carga ya consultada en esta petición */
    private array $careers = [];

    /**
     * Las carreras dueñas de los grupos de la carga.
     *
     * @return list<string>
     */
    public function careersOf(string $termId, string $modalityId, string $departmentId): array
    {
        return $this->careers["{$termId}|{$modalityId}|{$departmentId}"] ??= Group::query()->active()
            ->where('term_id', $termId)
            ->where('modality_id', $modalityId)
            ->where('department_id', $departmentId)
            ->distinct()
            ->orderBy('owner_career_id')
            ->pluck('owner_career_id')
            ->all();
    }

    /**
     * Los departamentos cuyas cargas tienen grupos de la carrera.
     *
     * @return list<string>
     */
    public function departmentsOf(string $termId, string $modalityId, string $careerId): array
    {
        return Group::query()->active()
            ->where('term_id', $termId)
            ->where('modality_id', $modalityId)
            ->where('owner_career_id', $careerId)
            ->distinct()
            ->orderBy('department_id')
            ->pluck('department_id')
            ->all();
    }
}
