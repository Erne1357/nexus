<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * La modalidad de una pantalla que trabaja sobre una sola (M4; la carga es por departamento y modalidad, M1): la lleva
 * en su URL, `?modalidad=<clave>`, y la pinta con `<x-core::modality-scope>`, que no se ve con una sola opción (MS16,
 * supuesto por confirmar). Las opciones son las modalidades activas donde el usuario trabaja con su perfil
 * (Actor::modalities(), por clave). Una clave que no está entre ellas (ajena, inactiva, borrada o que no existe) se
 * ignora: es como no haber elegido.
 *
 * - requiredModality(): la elegida si está entre las opciones; si no, defaultModality(); sin ninguna, 403.
 * - defaultModality(): con una sola opción, esa; con varias, la primera por clave donde el periodo activo de la
 *   credencial está abierto (`term_openings`: ahí hay cargas que trabajar), y si no está abierto en ninguna, la primera
 *   por clave (MS30, supuesto por confirmar). Una pantalla puede preferir otra (el cierre, la de su departamento).
 *
 * No es alcance: lo que el usuario puede ver o hacer lo siguen decidiendo las policies y Actor::constrain(). Los métodos
 * son protegidos para que el navegador no pueda llamarlos como acciones.
 */
trait WithModalityScope
{
    /** La clave de la modalidad elegida en la página; null o una que no está entre las opciones: la de omisión. */
    #[Url(as: 'modalidad')]
    public ?string $modalidad = null;

    /** La modalidad de omisión de esta petición, ya resuelta (no se guarda entre peticiones). */
    protected ?Modality $defaultModalityOfRequest = null;

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
     * La modalidad de la pantalla: la elegida si está entre las opciones; si no, la de omisión; sin ninguna, 403.
     */
    protected function requiredModality(): Modality
    {
        $options = $this->modalityOptions();

        return (blank($this->modalidad) ? null : $options->firstWhere('code', $this->modalidad))
            ?? $this->defaultModality($options)
            ?? abort(403, 'No trabajas en ninguna modalidad activa.');
    }

    /**
     * La modalidad cuando no se eligió una válida (MS30, supuesto por confirmar): la única opción; con varias, la primera
     * por clave donde el periodo activo está abierto, o la primera por clave. Así, con EAD activa pero sin abrir, la
     * pantalla no abre en una modalidad sin cargas. Una consulta por petición, y solo con más de una opción.
     *
     * @param  Collection<int, Modality>  $options
     */
    protected function defaultModality(Collection $options): ?Modality
    {
        return $this->firstOpenModality($options);
    }

    /**
     * De `$options`, la única; con varias, la primera por clave donde el periodo activo está abierto, o la primera por
     * clave (MS30). Se recuerda en la petición.
     *
     * @param  Collection<int, Modality>  $options
     */
    protected function firstOpenModality(Collection $options): ?Modality
    {
        $sorted = $options->sortBy('code')->values();

        if ($sorted->count() < 2) {
            return $sorted->first();
        }

        if ($this->defaultModalityOfRequest !== null && $sorted->contains($this->defaultModalityOfRequest)) {
            return $this->defaultModalityOfRequest;
        }

        $termId = app(Actor::class)->activeTerm()?->getKey();
        $opened = $termId === null ? [] : TermOpening::query()->where('term_id', $termId)->whereIn('modality_id', $sorted->modelKeys())->pluck('modality_id')->all();

        return $this->defaultModalityOfRequest = $sorted->first(fn (Modality $modality): bool => in_array($modality->getKey(), $opened, true)) ?? $sorted->first();
    }
}
