<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\CredentialQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La banda del layout (DESIGN.md, "Estructura"): la liebre, que abre y cierra el menú completo;
 * «Nexus»; la ruta (área › pantalla); «Buscar o ir a…»; la campana de notificaciones, y la credencial de quien trabaja, que se abre
 * como tarjeta con el selector de perfil y periodo, el tema, el cambio de contraseña y el cierre de
 * sesión. Abrir y cerrar lo hace el componente Alpine `nexusShell` (resources/js/app.js).
 *
 * El área de la ruta es la de la entrada del menú de la pantalla (Navigation::forRoute). La
 * credencial muestra el perfil activo (Actor::activeRole()) con su nombre para quien lo ocupa (CredentialQuery con
 * RoleTitleQuery: el jefe de Servicios Escolares, «Jefe del Departamento de Servicios Escolares», AZ-02) y el periodo
 * activo: «Rol · Periodo». La credencial del login lee lo mismo para llegar a la banda (tarea 1.23).
 * El campus no va en la credencial (#415): lo elige cada pantalla.
 */
new class extends Component
{
    /**
     * Título de la pantalla (el de #[Title] de la página); sin él, el nombre de su entrada del menú.
     */
    #[Locked]
    public ?string $title = null;

    /**
     * Nombre de la ruta de la pantalla; por omisión, la de la petición que pinta el layout.
     */
    #[Locked]
    public ?string $route = null;

    public function mount(?string $title = null, ?string $route = null): void
    {
        $this->title = $title;
        $this->route = $route ?? request()->route()?->getName();
    }

    /**
     * @return array{areaLabel: ?string, pageTitle: ?string, name: string, username: string, initials: string, roleLabel: ?string, summary: string}
     */
    public function with(Actor $context, Navigation $navigation, CredentialQuery $credentials): array
    {
        $credential = $credentials->of($context);
        $item = $this->route === null ? null : $navigation->forRoute($this->route, $context);

        return [
            'areaLabel' => $item?->area->label(),
            'pageTitle' => $this->title ?? $item?->label,
            'name' => $credential['name'],
            'username' => $credential['username'],
            'initials' => $credential['initials'],
            'roleLabel' => $credential['role'],
            'summary' => $credential['summary'],
        ];
    }
};
