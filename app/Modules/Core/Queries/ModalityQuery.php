<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Models\Modality;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Lecturas del catálogo de modalidades (ADR-0025) para las pantallas.
 */
final class ModalityQuery
{
    /**
     * Las modalidades activas y sin borrar, por clave: donde se abre el periodo y se fija una ventana propia (M4).
     *
     * @return Collection<int, Modality>
     */
    public function active(): Collection
    {
        return Modality::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * La modalidad elegida, sin las borradas.
     *
     * @throws ModelNotFoundException<Modality>
     */
    public function findOrFail(string $id): Modality
    {
        return KeyLookup::findOrFail(Modality::query(), $id);
    }
}
