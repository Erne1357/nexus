<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Credentials\CredentialPhotos;
use App\Modules\Core\Queries\CredentialQuery;
use App\Modules\Core\Queries\PageTrailQuery;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La banda del layout (DESIGN.md, "Estructura"): la liebre, que abre y cierra el menú completo;
 * «Nexus»; la ruta (área › pantalla); «Buscar o ir a…»; la campana de notificaciones, y la credencial de quien trabaja, que se abre
 * como tarjeta con el selector de perfil y periodo, el tema, el cambio de contraseña y el cierre de
 * sesión. Abrir y cerrar lo hace el componente Alpine `nexusShell` (resources/js/app.js).
 *
 * El área y la pantalla de la ruta salen de PageTrailQuery (el área de la entrada del menú de la pantalla y el título de
 * la página o, sin él, el nombre de la entrada), que el login también lee para su banda de llegada. La
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
     * @return array{areaLabel: ?string, pageTitle: ?string, name: string, username: string, initials: string, photo: ?string, roleLabel: ?string, summary: string}
     */
    public function with(Actor $context, PageTrailQuery $trails, CredentialQuery $credentials, CredentialPhotos $photos): array
    {
        $credential = $credentials->of($context);
        $trail = $trails->of($this->route, $this->title, $context);

        return [
            'areaLabel' => $trail['area'],
            'pageTitle' => $trail['title'],
            'name' => $credential['name'],
            'username' => $credential['username'],
            'initials' => $credential['initials'],
            // CO-04: la foto propia (la del personal, firmada, o la del alumno por su ruta propia); sin foto, las iniciales.
            'photo' => ($user = $context->user()) === null ? null : $photos->urlFor($user, withSources: $context->activeRole()?->isStaff() === false),
            'roleLabel' => $credential['role'],
            'summary' => $credential['summary'],
        ];
    }
};
