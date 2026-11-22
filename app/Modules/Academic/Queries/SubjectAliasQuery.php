<?php

namespace App\Modules\Academic\Queries;

use App\Modules\Academic\Models\SubjectAlias;

/**
 * La clave del SII de las materias de una retícula (regla D8-bis, confirmada el 22 de noviembre de 2026): el alias de la
 * materia en ESE plan. Una consulta por plan o conjunto de planes, para que las retículas y el kárdex la muestren como
 * clave principal sin consultar por mosaico. Si la materia tiene varias claves del SII en el plan queda la menor.
 */
final class SubjectAliasQuery
{
    /**
     * Las claves del SII del plan por id de materia. Solo trae las que se pintan distintas de la clave de Nexus.
     *
     * @return array<string, string>
     */
    public function forPlan(string $planId): array
    {
        return $this->forPlans([$planId])[$planId] ?? [];
    }

    /**
     * @param  list<string>  $planIds
     * @return array<string, array<string, string>> por id de plan y luego por id de materia
     */
    public function forPlans(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $aliases = [];

        $rows = SubjectAlias::query()
            ->join('subjects', 'subjects.id', '=', 'subject_aliases.subject_id')
            ->whereIn('subject_aliases.study_plan_id', array_values(array_unique($planIds)))
            ->whereColumn('subject_aliases.code', '!=', 'subjects.code')
            ->orderByDesc('subject_aliases.code')
            ->toBase()
            ->get(['subject_aliases.study_plan_id', 'subject_aliases.subject_id', 'subject_aliases.code']);

        foreach ($rows as $row) {
            $aliases[$row->study_plan_id][$row->subject_id] = $row->code;
        }

        return $aliases;
    }

    /**
     * La clave principal y la secundaria con que se pinta una materia: la del SII del plan y debajo la del TecNM, o
     * solo la de Nexus si no hay alias o es igual.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function display(string $code, ?string $alias): array
    {
        return $alias === null || $alias === $code ? [$code, null] : [$alias, $code];
    }
}
