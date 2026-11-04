<?php

use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Queries\OrgChartQuery;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * «Organigrama» (3.P7, maqueta B): la estructura del Instituto como un diagrama de cajas pintado en el servidor
 * (OrgChartQuery::chart()) y, a un lado, el panel de la unidad elegida (core::components.org-unit-panel), que hace las
 * escrituras. Elegir una caja o buscar por nombre o clave lo hace Alpine sin ir al servidor; el diagrama solo se vuelve
 * a pintar al cambiar «Mostrar inactivas» o cuando el panel avisa que algo cambió (`org-chart-changed`).
 *
 * Solo la ve el admin con ese perfil activo (OrgUnitPolicy); el componente autoriza en mount(), en cada cambio y al
 * pintar.
 */
new #[Title('Organigrama')] class extends Component
{
    /** Si el diagrama enseña también las unidades inactivas; se recuerda en la sesión del usuario. */
    #[Session]
    public bool $showInactive = false;

    public function mount(): void
    {
        $this->authorize('viewAny', OrgUnit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(OrgChartQuery $query): array
    {
        $this->authorize('viewAny', OrgUnit::class);

        return ['chart' => $query->chart($this->showInactive)];
    }

    public function updated(): void
    {
        $this->authorize('viewAny', OrgUnit::class);
    }

    /**
     * El panel dio de alta, cambió, movió, desactivó o activó una unidad: solo vuelve a pintar el diagrama.
     */
    #[On('org-chart-changed')]
    public function refreshChart(): void
    {
        $this->authorize('viewAny', OrgUnit::class);
    }
};
