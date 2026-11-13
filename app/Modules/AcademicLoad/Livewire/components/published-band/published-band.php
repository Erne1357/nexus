<?php

use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Livewire\Component;

/**
 * La banda de la consulta pública (tarea 4.4, DESIGN.md, «Consulta publicada»): la liebre, «Nexus» y la ruta
 * «Horarios»; a la derecha los selectores de Campus y Periodo, con los periodos que tienen algo publicado, y el acceso:
 * «Iniciar sesión» para un invitado, o el nombre y «Ir a Nexus» con sesión. Cambiar un selector lleva a
 * `/horarios?periodo=&modalidad=&campus=`.
 *
 * Lo publicado es por modalidad (M1) y el campus es un filtro: el selector de Campus ofrece «Todos los campus» y los
 * campus donde se dan los grupos publicados de la modalidad en el periodo. La modalidad no tiene selector todavía (con
 * solo Presencial activa no aparecería, MS16): va en la URL y se conserva al cambiar de periodo si publicó en él.
 *
 * Los códigos que se ven son los de la página que pinta el layout, aunque no estén entre las opciones publicadas
 * (entonces se agregan, elegidos); si faltan, el primer contexto publicado.
 */
new class extends Component
{
    /** Código del periodo que se ve (`20271`); '' es el primero con algo publicado. */
    public string $periodo = '';

    /** Clave de la modalidad que se ve, en minúsculas (`pres`); '' es la primera de ese periodo. */
    public string $modalidad = '';

    /** Código del campus del filtro (`C2`); '' son todos. */
    public string $campus = '';

    /** @var list<array{term: Term, modalities: list<Modality>}>|null */
    private ?array $contexts = null;

    public function mount(PublishedScheduleQuery $published, ?string $termCode = null, ?string $modalityCode = null, ?string $campusCode = null): void
    {
        $contexts = $this->contexts($published);
        $context = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $termCode) ?? ($contexts[0] ?? null);
        $this->periodo = PublishedContextQuery::isCode($termCode) && Term::query()->where('code', $termCode)->exists() ? $termCode : ($context['term']->code ?? '');

        $first = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $this->periodo)['modalities'][0] ?? null;
        $given = $modalityCode === null ? null : PublishedContextQuery::modality($modalityCode);
        $this->modalidad = $given !== null ? PublishedContextQuery::modalityKey($given) : ($first === null ? '' : PublishedContextQuery::modalityKey($first));

        $this->campus = PublishedContextQuery::isCode($campusCode) && Campus::query()->where('code', $campusCode)->where('is_active', true)->exists() ? $campusCode : '';
    }

    public function hydrate(): void
    {
        PublishedThrottle::hit();
    }

    /**
     * Otro campus (o todos) del mismo periodo y modalidad.
     */
    public function updatedCampus(): void
    {
        $this->go();
    }

    /**
     * Otro periodo: se conserva la modalidad si publicó en él, si no la primera que sí; el campus del filtro se conserva.
     */
    public function updatedPeriodo(PublishedScheduleQuery $published): void
    {
        $context = array_find($this->contexts($published), fn (array $entry): bool => $entry['term']->code === $this->periodo);
        $modalities = array_map(fn (Modality $item): string => PublishedContextQuery::modalityKey($item), $context['modalities'] ?? []);

        if (! in_array($this->modalidad, $modalities, true)) {
            $this->modalidad = $modalities[0] ?? '';
        }

        $this->go();
    }

    private function go(): void
    {
        $this->redirect(route('published.index', array_filter(['periodo' => $this->periodo, 'modalidad' => $this->modalidad, 'campus' => $this->campus], fn (string $value): bool => $value !== '')), navigate: true);
    }

    /**
     * @return list<array{term: Term, modalities: list<Modality>}>
     */
    private function contexts(PublishedScheduleQuery $published): array
    {
        return $this->contexts ??= $published->contexts();
    }

    /**
     * @return array{terms: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>}
     */
    public function with(PublishedScheduleQuery $published): array
    {
        $contexts = $this->contexts($published);
        $terms = array_map(fn (array $entry): array => ['id' => $entry['term']->code, 'name' => $entry['term']->name], $contexts);
        $term = PublishedContextQuery::isCode($this->periodo) ? Term::query()->where('code', $this->periodo)->first() : null;
        $modality = PublishedContextQuery::isCode($this->modalidad) ? PublishedContextQuery::modality($this->modalidad) : null;
        $withGroups = $term === null || $modality === null ? [] : $published->schedule($term, $modality)->campuses();
        $campuses = [['id' => '', 'name' => 'Todos los campus'], ...array_map(fn (array $campus): array => ['id' => $campus['code'], 'name' => $campus['name']], $withGroups)];

        if ($term !== null && ! in_array($this->periodo, array_column($terms, 'id'), true)) {
            $terms[] = ['id' => $term->code, 'name' => $term->name];
        }

        if ($this->campus !== '' && ! in_array($this->campus, array_column($campuses, 'id'), true) && ($campus = Campus::query()->where('code', $this->campus)->first()) !== null) {
            $campuses[] = ['id' => $campus->code, 'name' => $campus->name];
        }

        return ['terms' => $terms, 'campuses' => $campuses];
    }
};
