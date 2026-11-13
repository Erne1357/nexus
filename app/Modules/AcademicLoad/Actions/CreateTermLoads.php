<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * Crea en borrador las cargas que falten al abrir un periodo en una modalidad (M4; MS15, supuesto por confirmar; ver
 * docs/dominio/reglas-de-negocio.md, Periodo y calendario): una por cada departamento activo y sin borrar que todavía
 * no la tenga en ese periodo y modalidad (M1, la carga es por departamento y modalidad). Abrir Presencial crea una por
 * departamento, con los grupos de C1 y C2 juntos. Devuelve cuántas creó: 0 si no faltaba ninguna. No toca las que ya
 * existen, estén en el estado que estén.
 *
 * Un solo insert que ignora las que ya existen (la unicidad por periodo, modalidad y departamento), así que es
 * idempotente y otra sesión que abra a la vez no la hace fallar. No deja bitácora por carga ni avisa: la
 * llama CreateLoadsOnTermOpened dentro de la transacción de OpenTerm, que deja una entrada en la bitácora
 * del periodo con el reporte.
 */
final class CreateTermLoads
{
    public function __construct(private readonly DepartmentQuery $departments) {}

    /**
     * Lo que handle() crearía, sin escribir: las cargas que faltan (`loads`), las claves de los departamentos activos y los
     * campus activos de la modalidad. Lo usa el panel de abrir el periodo, por TermOpeningPreviewing.
     *
     * @return array{loads: int, departments: list<string>, campuses: int}
     */
    public function preview(Term $term, Modality $modality): array
    {
        $departments = $this->departments->activeIds();
        $campuses = Campus::query()->where('modality_id', $modality->getKey())->where('is_active', true)->pluck('id');
        $existing = $campuses->isEmpty() || $departments === [] ? 0 : AcademicLoad::query()
            ->where('term_id', $term->getKey())
            ->whereIn('campus_id', $campuses)
            ->whereIn('department_id', $departments)
            ->count();

        return [
            'loads' => $campuses->count() * count($departments) - $existing,
            'departments' => $this->departments->activeCodes(),
            'campuses' => $campuses->count(),
        ];
    }

    public function handle(Term $term, Modality $modality): int
    {
        $now = now();

        $rows = array_map(fn (string $department): array => [
            'term_id' => $term->getKey(),
            'modality_id' => $modality->getKey(),
            'department_id' => $department,
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->departments->activeIds());

        return $rows === [] ? 0 : AcademicLoad::query()->insertOrIgnore($rows);
    }
}
