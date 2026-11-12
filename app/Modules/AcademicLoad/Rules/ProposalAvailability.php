<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Validation\ValidationException;

/**
 * Cuándo existe una propuesta de carrera (reglas-de-negocio.md, "Propuesta de carrera", supuesto por confirmar):
 * el periodo está abierto en la modalidad del campus (`term_openings`, M4; por el puente `campuses.modality_id`
 * mientras la propuesta sea por campus) y la carrera se ofrece en él (`career_campus` activa y sin borrar). Se exige al armarla (StartCareerProposal) y otra vez al enviarla (SubmitCareerProposal).
 */
final class ProposalAvailability
{
    /**
     * Lee la oferta con bloqueo compartido, así que va dentro de la transacción de quien la llama.
     *
     * @throws ValidationException sobre `term` o `career`
     */
    public function ensure(Term $term, Campus $campus, Career $career): void
    {
        $opened = TermOpening::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $campus->modality_id)
            ->exists();

        if (! $opened) {
            throw ValidationException::withMessages(['term' => "El periodo {$term->code} no está abierto en {$campus->code}."]);
        }

        $offered = CareerCampus::query()
            ->where('career_id', $career->getKey())
            ->where('campus_id', $campus->getKey())
            ->where('is_active', true)
            ->sharedLock()
            ->exists();

        if (! $offered) {
            throw ValidationException::withMessages(['career' => "{$career->code} no se ofrece en {$campus->code}."]);
        }
    }
}
