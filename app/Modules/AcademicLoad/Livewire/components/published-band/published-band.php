<?php

use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\AcademicLoad\Rules\PublishedThrottle;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Livewire\Component;

/**
 * La banda de la consulta pública (tarea 4.4, DESIGN.md, «Consulta publicada»): la liebre, «Nexus» y la ruta
 * «Horarios»; a la derecha los selectores de Campus y Periodo, con los periodos que tienen algo publicado y, en cada
 * uno, sus campus activos (PublishedScheduleQuery::contexts()), y el acceso: «Iniciar sesión» para un invitado, o el
 * nombre y «Ir a Nexus» con sesión. Cambiar un selector lleva a `/horarios?periodo=&campus=`.
 *
 * Los códigos del periodo y el campus que se ven son los de la página que pinta el layout, aunque no estén entre
 * las opciones publicadas (entonces se agregan, elegidos); si faltan, el primer contexto publicado.
 */
new class extends Component
{
    /** Código del periodo que se ve (`20271`); '' es el primero con algo publicado. */
    public string $periodo = '';

    /** Código del campus que se ve (`C2`); '' es el primero de ese periodo. */
    public string $campus = '';

    /** @var list<array{term: Term, campuses: list<Campus>}>|null */
    private ?array $contexts = null;

    public function mount(PublishedScheduleQuery $published, ?string $termCode = null, ?string $campusCode = null): void
    {
        $contexts = $this->contexts($published);
        $context = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $termCode) ?? ($contexts[0] ?? null);
        $this->periodo = PublishedContextQuery::isCode($termCode) && Term::query()->where('code', $termCode)->exists() ? $termCode : ($context['term']->code ?? '');

        $first = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $this->periodo)['campuses'][0] ?? null;
        $given = PublishedContextQuery::isCode($campusCode) && Campus::query()->where('code', $campusCode)->where('is_active', true)->exists();
        $this->campus = $given ? $campusCode : ($first->code ?? '');
    }

    public function hydrate(): void
    {
        PublishedThrottle::hit();
    }

    /**
     * Otro campus del mismo periodo.
     */
    public function updatedCampus(): void
    {
        $this->go();
    }

    /**
     * Otro periodo: se conserva el campus si publicó en él, si no el primero que sí.
     */
    public function updatedPeriodo(PublishedScheduleQuery $published): void
    {
        $context = array_find($this->contexts($published), fn (array $entry): bool => $entry['term']->code === $this->periodo);
        $campuses = array_map(fn (Campus $item): string => $item->code, $context['campuses'] ?? []);

        if (! in_array($this->campus, $campuses, true)) {
            $this->campus = $campuses[0] ?? '';
        }

        $this->go();
    }

    private function go(): void
    {
        $this->redirect(route('published.index', ['periodo' => $this->periodo, 'campus' => $this->campus]), navigate: true);
    }

    /**
     * @return list<array{term: Term, campuses: list<Campus>}>
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
        $current = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $this->periodo);
        $terms = array_map(fn (array $entry): array => ['id' => $entry['term']->code, 'name' => $entry['term']->name], $contexts);
        $campuses = array_map(fn (Campus $campus): array => ['id' => $campus->code, 'name' => $campus->name], $current['campuses'] ?? []);

        if (PublishedContextQuery::isCode($this->periodo) && ! in_array($this->periodo, array_column($terms, 'id'), true) && ($term = Term::query()->where('code', $this->periodo)->first()) !== null) {
            $terms[] = ['id' => $term->code, 'name' => $term->name];
        }

        if (PublishedContextQuery::isCode($this->campus) && ! in_array($this->campus, array_column($campuses, 'id'), true) && ($campus = Campus::query()->where('code', $this->campus)->first()) !== null) {
            $campuses[] = ['id' => $campus->code, 'name' => $campus->name];
        }

        return ['terms' => $terms, 'campuses' => $campuses];
    }
};
