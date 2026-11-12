<?php

namespace App\Modules\AcademicLoad\Actions;

use App\Modules\Academic\Queries\DepartmentQuery;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * Crea en borrador las cargas que falten al abrir un periodo en una modalidad (M4; MS15, supuesto por confirmar; ver
 * docs/dominio/reglas-de-negocio.md, Periodo y calendario). Puente mientras la carga sea por campus (pasará a una por
 * departamento y modalidad, M1): una por cada departamento activo y sin borrar en cada campus activo y sin borrar cuya
 * modalidad del catálogo (`campuses.modality_id`) es la que se abre, si todavía no la tiene en ese periodo y campus.
 * Abrir Presencial crea las de C1 y C2, como antes abrir cada campus. Devuelve cuántas creó: 0 si no faltaba ninguna.
 * No toca las que ya existen, estén en el estado que estén. Cada una lleva la modalidad que se abre.
 *
 * Un solo insert que ignora las que ya existen (la unicidad por periodo, campus y departamento), así que es
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
        $departments = $this->departments->activeIds();
        $campuses = Campus::query()->where('modality_id', $modality->getKey())->where('is_active', true)->orderBy('code')->pluck('id');

        $rows = $campuses
            ->crossJoin($departments)
            ->map(fn (array $pair): array => [
                'term_id' => $term->getKey(),
                'campus_id' => $pair[0],
                'modality_id' => $modality->getKey(),
                'department_id' => $pair[1],
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        return $rows === [] ? 0 : AcademicLoad::query()->insertOrIgnore($rows);
    }
}
