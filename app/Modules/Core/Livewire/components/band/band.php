<?php

use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * La banda del layout (DESIGN.md, "Estructura"): la liebre, que abre y cierra el menú completo;
 * «Nexus»; la ruta (área › pantalla); «Buscar o ir a…», y la credencial de quien trabaja, que se abre
 * como tarjeta con el selector de campus y periodo, el tema, el cambio de contraseña y el cierre de
 * sesión. Abrir y cerrar lo hace el componente Alpine `nexusShell` (resources/js/app.js).
 *
 * El área de la ruta es la de la entrada del menú de la pantalla (Navigation::forRoute). La
 * credencial muestra el primer rol que el usuario ejerce (ScopedContext::roles(), en el orden del
 * enum) con su nombre de Role::label(), y el campus y el periodo activos.
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
    public function with(ScopedContext $context, Navigation $navigation): array
    {
        $user = $context->user();
        $role = $context->roles()[0] ?? null;
        $item = $this->route === null ? null : $navigation->forRoute($this->route);

        return [
            'areaLabel' => $item?->area->label(),
            'pageTitle' => $this->title ?? $item?->label,
            'name' => $user->name ?? '',
            'username' => $user->username ?? '',
            'initials' => $this->initials($user->name ?? ''),
            'roleLabel' => $role?->label(),
            'summary' => implode(' · ', array_filter([
                $role?->label(),
                $context->activeCampus()?->name,
                $context->activeTerm()?->name,
            ])),
        ];
    }

    /**
     * Las iniciales del avatar: las de las dos primeras palabras con mayúscula del nombre («María de
     * la Luz» da ML) o, si no hay, las de las dos primeras palabras.
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $capitalized = array_values(array_filter($words, fn (string $word): bool => preg_match('/^\p{Lu}/u', $word) === 1));

        return mb_strtoupper(implode('', array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($capitalized === [] ? $words : $capitalized, 0, 2),
        )));
    }
};
