<?php

namespace App\Modules\AcademicLoad\Rules\Constraints;

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;

/**
 * HC-14 (como la reescribe D2): la carga de un departamento no sale de borrador hasta que estén validados
 * los cuatro catálogos generales del departamento en el periodo y, si el perfil del campus usa salones, los
 * salones del departamento en el campus.
 */
final class CatalogsValidated
{
    public function __construct(private readonly CatalogReviewQuery $reviews) {}

    /**
     * El motivo de bloqueo, o nada si no falta ningún catálogo. Lee las revisiones con bloqueo compartido: la
     * carga ya está bloqueada por TransitionLoad y sin esto una revisión des-validada en paralelo podría
     * colarse entre la lectura y el cambio de estado.
     *
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        $load->loadMissing('campus');

        $pending = $this->reviews->pendingFor($load->term_id, $load->campus, $load->department_id, lock: true);

        if ($pending === []) {
            return [];
        }

        $labels = array_map(fn (Catalog $catalog): string => $catalog->label(), $pending);
        $last = array_pop($labels);
        $list = $labels === [] ? $last : implode(', ', $labels).' y '.$last;

        return ["HC-14: faltan por validar los catálogos de {$list}."];
    }
}
