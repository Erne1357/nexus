<?php

use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use Livewire\Component;

/**
 * La banda de la consulta pública (tarea 4.4, DESIGN.md, «Consulta publicada»): la liebre, «Nexus» y la ruta
 * «Horarios»; a la derecha los selectores de Campus y Periodo, con los periodos que tienen algo publicado y, en cada
 * uno, sus campus (PublishedScheduleQuery::contexts()), y el acceso: «Iniciar sesión» para un invitado, o el nombre
 * y «Ir a Nexus» con sesión. Cambiar un selector lleva a `/horarios?periodo=&campus=`.
 *
 * Los códigos del periodo y el campus que se ven salen de la URL de la página que pinta el layout; si faltan, el
 * primer contexto publicado.
 */
new class extends Component
{
    /** Código del periodo que se ve (`20271`); '' es el primero con algo publicado. */
    public string $periodo = '';

    /** Código del campus que se ve (`C2`); '' es el primero de ese periodo. */
    public string $campus = '';

    public function mount(PublishedScheduleQuery $published, ?string $termCode = null, ?string $campusCode = null): void
    {
        $contexts = $published->contexts();
        $context = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $termCode) ?? ($contexts[0] ?? null);
        $this->periodo = $context['term']->code ?? '';
        $campuses = array_map(fn (Campus $item): string => $item->code, $context['campuses'] ?? []);
        $this->campus = in_array($campusCode, $campuses, true) ? $campusCode : ($campuses[0] ?? '');
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
        $context = array_find($published->contexts(), fn (array $entry): bool => $entry['term']->code === $this->periodo);
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
     * @return array{terms: list<array{id: string, name: string}>, campuses: list<array{id: string, name: string}>}
     */
    public function with(PublishedScheduleQuery $published): array
    {
        $contexts = $published->contexts();
        $current = array_find($contexts, fn (array $entry): bool => $entry['term']->code === $this->periodo);

        return [
            'terms' => array_map(fn (array $entry): array => ['id' => $entry['term']->code, 'name' => $entry['term']->name], $contexts),
            'campuses' => array_map(fn (Campus $campus): array => ['id' => $campus->code, 'name' => $campus->name], $current['campuses'] ?? []),
        ];
    }
};
