<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * Crea en borrador las cargas que falten al abrir un periodo en un campus (ver docs/dominio/reglas-de-negocio.md, Periodo y calendario, supuesto por confirmar):
 * una por cada departamento activo y sin borrar que todavía no la tenga en ese periodo y campus. Devuelve
 * cuántas creó: 0 si no faltaba ninguna. No toca las que ya existen, estén en el estado que estén. Cada una lleva la
 * modalidad del catálogo del campus (ampliación de la ronda 2, ADR-0025; aún no la lee nadie).
 *
 * Un solo insert que ignora las que ya existen (la unicidad por periodo, campus y departamento), así que es
 * idempotente y otra sesión que abra a la vez no la hace fallar. No deja bitácora por carga ni avisa: la
 * llama CreateLoadsOnTermOpened dentro de la transacción de OpenTerm, que deja una entrada en la bitácora
 * del periodo con el reporte.
 */
final class CreateTermLoads
{
    public function __construct(private readonly DepartmentQuery $departments) {}

    public function handle(Term $term, Campus $campus): int
    {
        $now = now();

        $rows = collect($this->departments->activeIds())
            ->map(fn (string $departmentId): array => [
                'term_id' => $term->getKey(),
                'campus_id' => $campus->getKey(),
                'modality_id' => $campus->modality_id,
                'department_id' => $departmentId,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        return $rows === [] ? 0 : AcademicLoad::query()->insertOrIgnore($rows);
    }
}
