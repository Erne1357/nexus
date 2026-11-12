<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Modality;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * La modalidad de una pantalla que trabaja sobre una sola (M4; la carga es por departamento y modalidad, M1): la lleva
 * en su URL, `?modalidad=<clave>`, y la pinta con `<x-core::modality-scope>`, que no se ve con una sola opción (MS16,
 * supuesto por confirmar). Las opciones son las modalidades activas donde el usuario trabaja con su perfil
 * (Actor::modalities(), por clave). Una clave que no está entre ellas (ajena, inactiva, borrada o que no existe) se
 * ignora: es como no haber elegido.
 *
 * - requiredModality(): la elegida si está entre las opciones; si no, la primera por clave; sin ninguna, 403.
 *
 * No es alcance: lo que el usuario puede ver o hacer lo siguen decidiendo las policies y Actor::constrain(). Los métodos
 * son protegidos para que el navegador no pueda llamarlos como acciones.
 */
trait WithModalityScope
{
    /** La clave de la modalidad elegida en la página; null o una que no está entre las opciones: la primera. */
    #[Url(as: 'modalidad')]
    public ?string $modalidad = null;

    /**
     * Las modalidades que la pantalla ofrece: las activas donde trabaja con su perfil, por clave.
     *
     * @return Collection<int, Modality>
     */
    protected function modalityOptions(): Collection
    {
        return app(Actor::class)->modalities();
    }

    /**
     * La modalidad de la pantalla: la elegida si está entre las opciones; si no, la primera por clave; sin ninguna, 403.
     */
    protected function requiredModality(): Modality
    {
        $options = $this->modalityOptions();

        return (blank($this->modalidad) ? null : $options->firstWhere('code', $this->modalidad))
            ?? $options->sortBy('code')->first()
            ?? abort(403, 'No trabajas en ninguna modalidad activa.');
    }
}
