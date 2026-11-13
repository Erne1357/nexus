<?php

namespace App\Modules\AcademicLoad\Rules;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cuándo existe una propuesta de carrera y qué columnas lleva (reglas-de-negocio.md, "Propuesta de carrera"; M6, MS5).
 *
 * - ensure(): la modalidad está activa (M10), el periodo está abierto en ella (`term_openings`, M4) y la carrera se
 *   ofrece en algún campus activo (`career_campus` activa y sin borrar, HC-13). Se exige al armarla
 *   (StartCareerProposal) y otra vez al enviarla (SubmitCareerProposal) (supuestos por confirmar).
 * - columns(): en una modalidad con salones, los campus activos donde se ofrece la carrera, por clave; en una sin
 *   salones, una sola columna sin campus (MS5, supuesto por confirmar).
 */
final class ProposalAvailability
{
    /**
     * Lee la oferta con bloqueo compartido, así que va dentro de la transacción de quien la llama.
     *
     * @throws ValidationException sobre `term` o `career`
     */
    public function ensure(Term $term, Modality $modality, Career $career): void
    {
        if (! $modality->is_active || $modality->trashed()) {
            throw ValidationException::withMessages(['term' => "{$modality->name} está inactiva: su carga no se trabaja."]);
        }

        $opened = TermOpening::query()
            ->where('term_id', $term->getKey())
            ->where('modality_id', $modality->getKey())
            ->exists();

        if (! $opened) {
            throw ValidationException::withMessages(['term' => "El periodo {$term->code} no está abierto en {$modality->name}."]);
        }

        if ($this->offeredCampuses($career, lock: true)->isEmpty()) {
            throw ValidationException::withMessages(['career' => "{$career->code} no se ofrece en ningún campus."]);
        }
    }

    /**
     * Las columnas de la propuesta: los campus de la oferta si la modalidad usa salones; si no, una sola, sin campus.
     *
     * @return list<Campus|null>
     */
    public function columns(Modality $modality, Career $career): array
    {
        return $modality->profile->usesRooms ? $this->offeredCampuses($career)->values()->all() : [null];
    }

    /**
     * Los campus activos y sin borrar donde la carrera tiene su oferta activa y sin borrar, por clave.
     *
     * @return Collection<int, Campus>
     */
    private function offeredCampuses(Career $career, bool $lock = false): Collection
    {
        $offer = CareerCampus::query()
            ->where('career_id', $career->getKey())
            ->where('is_active', true)
            ->when($lock, fn ($query) => $query->sharedLock())
            ->pluck('campus_id')
            ->all();

        return Campus::query()
            ->whereIn('id', $offer)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }
}
