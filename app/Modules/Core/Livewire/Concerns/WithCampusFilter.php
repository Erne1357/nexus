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
 * - requiredCampus(): para las pantallas que trabajan sobre un solo campus (la carga y sus pantallas): el elegido, o
 *   el último que se eligió en la sesión (Campus::LAST_CHOSEN_KEY) si sigue entre las opciones, o el primero; sin ninguna, 403.
 *   Cada vez que la petición trae un `?campus=` válido, se recuerda (supuesto por confirmar, AZ-02).
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
     * El campus de una pantalla que necesita uno: el elegido; si no hay, el último recordado en la sesión si sigue entre
     * las opciones; si no, el primero. El elegido se recuerda para la siguiente pantalla.
     */
    protected function requiredCampus(): Campus
    {
        $options = $this->campusOptions();
        $chosen = $this->campusFilter();

        if ($chosen !== null) {
            session()->put(Campus::LAST_CHOSEN_KEY, $chosen->code);

            return $chosen;
        }

        return $options->firstWhere('code', session()->get(Campus::LAST_CHOSEN_KEY))
            ?? $options->first()
            ?? abort(403, 'No trabajas en ningún campus activo.');
    }

    /**
     * Para el `with()` de una pantalla de un solo campus: las opciones y el código del campus que muestra, que van a
     * `<x-core::campus-filter :options="$campusOptions" :selected="$campusCode" />` (sin «Todos»).
     *
     * @return array{campusOptions: Collection<int, Campus>, campusCode: string}
     */
    protected function campusViewData(): array
    {
        return ['campusOptions' => $this->campusOptions(), 'campusCode' => $this->requiredCampus()->code];
    }

    /**
     * Cambiar de campus en una pantalla que lo resolvió al abrirse (periodo, grupos, filtros del campus anterior): la
     * abre de nuevo en `$route` con el campus elegido, sin los demás filtros.
     */
    protected function reopenOnCampus(string $route): void
    {
        $this->redirectRoute($route, ['campus' => $this->requiredCampus()->code], navigate: true);
    }
}
