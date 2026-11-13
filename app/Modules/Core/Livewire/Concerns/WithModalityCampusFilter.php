<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * El alcance de las pantallas de la carga (maqueta U2, opción A «Selectores en la página», elegida por el usuario el 12
 * de noviembre de 2026): la modalidad es el alcance (`?modalidad=`, WithModalityScope; M1) y el campus es un filtro
 * dentro de ella (`?campus=`, WithCampusFilter, con «Todos» o, en el editor, un campus a la vez).
 *
 * Es opcional: las pantallas que no son de la carga (edificios, salones, alumnos, carreras) siguen con WithCampusFilter
 * y los campus donde trabaja el usuario (Actor::campuses()). Aquí las opciones de campus son las de la modalidad
 * elegida: con salones, los campus activos, porque un grupo de esa modalidad se da en cualquiera (MS4); sin salones,
 * ninguno, porque sus grupos van sin campus y no hay filtro. Son todos los que el usuario lee: los permisos de lectura de
 * la carga declaran la modalidad sin el campus, así que quien tiene un nombramiento con campus lee toda su modalidad y
 * solo escribe lo de su campus, que siguen decidiendo las policies con el campus de cada grupo (MS6). Un código que no
 * está entre ellas, o un `?campus[]=` que no es texto, es «Todos».
 *
 * El campus de omisión de una pantalla de un solo campus (requiredCampus()) es el último elegido en la sesión si está
 * entre las opciones; si no, el primero donde el usuario trabaja con su perfil (donde escribe), y si no, el primero.
 */
trait WithModalityCampusFilter
{
    use WithCampusFilter;
    use WithModalityScope;

    /** @var array<string, EloquentCollection<int, Campus>> los campus de cada modalidad en esta petición, por id */
    protected array $campusesOfModality = [];

    /**
     * Los campus del filtro: los de la modalidad de la pantalla, por código.
     *
     * @return Collection<int, Campus>
     */
    protected function campusOptions(): Collection
    {
        return $this->campusesOf($this->requiredModality());
    }

    /**
     * Los campus donde se dan los grupos de una modalidad (MS4): con salones, los activos por código; sin salones,
     * ninguno.
     *
     * @return EloquentCollection<int, Campus>
     */
    protected function campusesOf(Modality $modality): EloquentCollection
    {
        return $this->campusesOfModality[$modality->getKey()] ??= $modality->profile->usesRooms
            ? Campus::query()->where('is_active', true)->orderBy('code')->get()
            : new EloquentCollection;
    }

    /**
     * El campus de omisión, sin fijarlo en la página: el último elegido en la sesión si sigue entre las opciones; si no,
     * el primero donde trabaja con su perfil (Actor::campuses(): donde escribe), o el primero; sin ninguno, 403.
     */
    protected function defaultCampus(): Campus
    {
        $options = $this->campusOptions();
        $own = app(Actor::class)->campuses()->modelKeys();

        return $options->firstWhere('code', session()->get(Campus::LAST_CHOSEN_KEY))
            ?? $options->first(fn (Campus $campus): bool => in_array($campus->getKey(), $own, true))
            ?? $options->first()
            ?? abort(403, 'La modalidad no tiene campus donde trabajar.');
    }

    /**
     * Para el `with()` de una pantalla de la carga: las modalidades del selector y la que muestra, y los campus del
     * filtro y el que muestra (null, «Todos»), que van a `<x-core::modality-scope>` y `<x-core::campus-filter>`.
     *
     * @return array{modalityOptions: Collection<int, Modality>, shownModality: Modality, campusOptions: Collection<int, Campus>, shownCampus: ?Campus}
     */
    protected function scopeViewData(): array
    {
        return [
            'modalityOptions' => $this->modalityOptions(),
            'shownModality' => $this->requiredModality(),
            'campusOptions' => $this->campusOptions(),
            'shownCampus' => $this->campusFilter(),
        ];
    }
}
