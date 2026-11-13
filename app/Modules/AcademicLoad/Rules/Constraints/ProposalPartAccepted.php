<?php

namespace App\Modules\AcademicLoad\Rules\Constraints;

use App\Modules\AcademicLoad\Enums\ProposalReviewStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;

/**
 * HC-20 (supuesto por confirmar): la carga de un departamento en una modalidad (M1) no sale de borrador sin al menos una
 * parte aceptada del departamento en una propuesta de carrera del mismo periodo y modalidad, de cualquiera de sus campus. Es la condición
 * «propuestas de carrera aceptadas» del diagrama de docs/proceso/proceso-propuesto.md. Una parte aceptada cuenta
 * aunque su propuesta esté regresada por otro departamento: lo aceptado ya no cambia.
 */
final class ProposalPartAccepted
{
    /**
     * El motivo de bloqueo, o nada si el departamento ya aceptó alguna parte. Lee las partes aceptadas con
     * bloqueo compartido, solo las filas de `proposal_reviews` (la propuesta va en una subconsulta sin bloqueo,
     * para no esperar a quien bloquea otra propuesta para decidirla).
     *
     * @return list<string>
     */
    public function reasons(AcademicLoad $load): array
    {
        $accepted = ProposalReview::query()
            ->where('department_id', $load->department_id)
            ->where('status', ProposalReviewStatus::Accepted)
            ->whereIn('career_proposal_id', CareerProposal::query()
                ->select('id')
                ->where('term_id', $load->term_id)
                ->where('modality_id', $load->modality_id))
            ->sharedLock()
            ->exists();

        return $accepted ? [] : ['HC-20: el departamento todavía no acepta la parte de ninguna propuesta de carrera.'];
    }
}
