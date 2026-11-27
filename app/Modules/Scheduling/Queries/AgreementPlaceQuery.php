<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El lugar de un acuerdo entre departamentos (MS6, M12): el campus del salón en un préstamo; el del grupo en un grupo
 * compartido, que no guarda campus (nulo en una modalidad sin salones, MS4). AcademicLoad no lee grupos, así que la
 * regla vive aquí, en una sola clase con sus formas: la expresión de SQL (el filtro y las cifras de campus de la bandeja),
 * el campus de un acuerdo leído de la base (los avisos) y el de un acuerdo con su grupo ya cargado (las filas).
 */
final class AgreementPlaceQuery
{
    /**
     * El campus del lugar en una consulta sobre `interdepartmental_agreements`.
     */
    public function campusIdColumn(): Expression
    {
        return DB::raw('coalesce(interdepartmental_agreements.campus_id, (select groups.campus_id from groups where groups.id = interdepartmental_agreements.group_id))');
    }

    /**
     * Solo los acuerdos cuyo lugar es ese campus.
     *
     * @param  Builder<InterdepartmentalAgreement>  $query
     * @return Builder<InterdepartmentalAgreement>
     */
    public function in(Builder $query, Campus $campus): Builder
    {
        return $query->where($this->campusIdColumn(), $campus->getKey());
    }

    /**
     * El campus del lugar de un acuerdo, leído de la base.
     */
    public function campusIdOf(InterdepartmentalAgreement $agreement): ?string
    {
        return InterdepartmentalAgreement::query()->whereKey($agreement->getKey())->toBase()->value($this->campusIdColumn());
    }

    /**
     * El campus del lugar de un acuerdo con su campus y su grupo (con el campus del grupo) ya cargados: las filas de la
     * bandeja, sin otra consulta.
     */
    public function campusOf(InterdepartmentalAgreement $agreement, ?Group $group): ?Campus
    {
        return $agreement->campus ?? $group?->campus;
    }
}
