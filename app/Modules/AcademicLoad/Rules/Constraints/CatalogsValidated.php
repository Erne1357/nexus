<?php

namespace App\Modules\AcademicLoad\Rules\Constraints;

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Support\Arr;

/**
 * HC-14 (como la reescribe D2): la carga de un departamento no sale de borrador hasta que estén validados
 * los cuatro catálogos generales del departamento en el periodo y, si el perfil del campus usa salones, los
 * salones del departamento en el campus. La misma condición la exige el departamento para aceptar su parte de
 * una propuesta de carrera (AcceptProposalReview; confirmado el 2 de noviembre de 2026).
 */
final class CatalogsValidated
{
    public function __construct(private readonly CatalogReviewQuery $reviews) {}

    /**
     * El motivo de bloqueo de la carga, o nada si no falta ningún catálogo (reasonsFor()).
     *
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        $load->loadMissing('campus');

        return $this->reasonsFor($load->term_id, $load->campus, $load->department_id);
    }

    /**
     * El motivo de bloqueo de un departamento en un campus y un periodo, o nada si no falta ningún catálogo. Lee
     * las revisiones con bloqueo compartido: quien llama ya bloqueó lo que va a cambiar (la carga, en
     * TransitionLoad; la parte, en AcceptProposalReview) y sin esto una revisión des-validada en paralelo podría
     * colarse entre la lectura y la escritura.
     *
     * @return list<string>
     */
    public function reasonsFor(Term|string $term, Campus $campus, Department|string $department): array
    {
        $pending = $this->reviews->pendingFor($term, $campus, $department, lock: true);

        if ($pending === []) {
            return [];
        }

        $labels = array_map(fn (Catalog $catalog): string => $catalog->label(), $pending);
        $list = Arr::join($labels, ', ', ' y ');

        return ["HC-14: faltan por validar los catálogos de {$list}."];
    }
}
