<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisLoadsQuery;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * El drawer de sugerencias de una materia en un campus de «Re-análisis de la carga» (tarea 8.2, pantalla; el lugar de la
 * tarea 8.3, Riel B). Aparte de la página para que abrirlo no vuelva a pintar los bloques: se abre con
 * `open-reanalysis-suggestions` (`subjectId`, `campusId`, nulo sin campus) desde el botón «Sugerencias» de cada bloque.
 *
 * Hoy muestra la demanda por carrera de la materia en el campus (el bloque, sin los filtros de la página) y el espacio de
 * las sugerencias vacío. La 8.3 llena `suggestions()` (y la vista `scheduling::reanalysis.suggestions`) y agrega aquí sus
 * métodos de aplicar o descartar, que vuelven a autorizar con la Action de la fase 3 que corresponda.
 *
 * Lo ve quien ve toda la carga (`view` de AcademicLoadPolicy): el coordinador no ajusta (RA-07). Cada apertura vuelve a
 * autorizar.
 */
new class extends Component
{
    #[Locked]
    public string $loadId = '';

    #[Locked]
    public ?string $subjectId = null;

    #[Locked]
    public ?string $campusId = null;

    public bool $show = false;

    public function mount(string $loadId): void
    {
        $this->loadId = $loadId;
    }

    #[On('open-reanalysis-suggestions')]
    public function open(string $subjectId, ?string $campusId, Actor $context, ReanalysisLoadsQuery $loads): void
    {
        $this->authorizeLoad($context, $loads);

        $this->subjectId = $subjectId;
        $this->campusId = $campusId;
        $this->show = true;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, ReanalysisLoadsQuery $loads, ReanalysisBoardQuery $boards): array
    {
        if (! $this->show || $this->subjectId === null) {
            return ['block' => null, 'load' => null, 'suggestions' => []];
        }

        $load = $this->authorizeLoad($context, $loads);
        $block = $boards->block($load, null, $this->subjectId, $this->campusId);

        return [
            'block' => $block,
            'load' => $load,
            'suggestions' => $this->suggestions(),
        ];
    }

    /**
     * Las sugerencias de la materia en el campus (tarea 8.3): abrir, cerrar o ampliar un grupo y cambiar de salón. Vacías
     * hasta que llegue el asesor del Riel B.
     *
     * @return list<array<string, mixed>>
     */
    private function suggestions(): array
    {
        return [];
    }

    private function authorizeLoad(Actor $context, ReanalysisLoadsQuery $loads): AcademicLoad
    {
        $load = $loads->findOrFail($this->loadId);
        abort_unless($loads->careerScopeOf($context, $load) === null, 403);

        return $load;
    }
};
