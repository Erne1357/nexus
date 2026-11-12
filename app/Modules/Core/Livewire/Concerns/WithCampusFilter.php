<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * El campus de una pantalla (#415): la credencial ya no lo elige, así que la pantalla que lo necesita lo lleva en su
 * URL, `?campus=<código>`, y lo pinta con `<x-core::campus-filter>`. Las opciones son los campus activos donde el
 * usuario trabaja con su perfil (Actor::campuses(), por código). Un código que no está entre ellas (ajeno, inactivo,
 * borrado o que no existe) se ignora: es como no haber elegido.
 *
 * - campusFilter(): el campus elegido, o null para «todos»: listas y catálogos que muestran todos los campus juntos.
 * - requiredCampus(): para las pantallas que trabajan sobre un solo campus (la carga y sus pantallas): el elegido o,
 *   si no hay, el primero de las opciones; sin ninguna, 403.
 *
 * No es alcance: lo que el usuario puede ver o hacer lo siguen decidiendo las policies y Actor::constrain(). Los
 * métodos son protegidos para que el navegador no pueda llamarlos como acciones.
 */
trait WithCampusFilter
{
    /** El código del campus elegido en la página; null o uno que no está entre las opciones: ninguno. */
    #[Url(as: 'campus')]
    public ?string $campus = null;

    /**
     * Los campus que la pantalla ofrece: los activos donde trabaja con su perfil, por código.
     *
     * @return Collection<int, Campus>
     */
    protected function campusOptions(): Collection
    {
        return app(Actor::class)->campuses();
    }

    /**
     * El campus elegido si está entre las opciones; si no, null, que en una lista es «todos».
     */
    protected function campusFilter(): ?Campus
    {
        return blank($this->campus) ? null : $this->campusOptions()->firstWhere('code', $this->campus);
    }

    /**
     * El campus de una pantalla que necesita uno: el elegido o, si no hay, el primero de las opciones.
     */
    protected function requiredCampus(): Campus
    {
        return $this->campusFilter()
            ?? $this->campusOptions()->first()
            ?? abort(403, 'No trabajas en ningún campus activo.');
    }
}
