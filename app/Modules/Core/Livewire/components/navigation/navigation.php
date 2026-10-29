<?php

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El menú que se transforma (DESIGN.md, "El menú que se transforma"): las entradas del registro
 * Navigation que el usuario puede ver, agrupadas por área. Cerrado muestra la franja de iconos de
 * área y las entradas del área actual; abierto, el buscador y todas las áreas en columnas. Abrir,
 * cerrar, buscar y animar lo hace el componente Alpine `nexusShell` (resources/js/app.js).
 *
 * El área actual es la de la entrada de la pantalla (Navigation::forRoute) si el usuario la ve; si
 * no, la primera que ve. La entrada activa es esa misma entrada.
 *
 * Cada elemento lleva su lugar en la retícula del menú abierto: `--c` es el número de área, que en
 * escritorio es también su columna y, cerrado, su renglón en la franja de iconos; `--i`, el de la
 * entrada dentro del área. En tableta el menú abierto tiene tres columnas (`--col-t`) y las áreas de
 * la cuarta en adelante bajan a `--base-t`, un renglón debajo de la más larga de las tres primeras.
 */
new class extends Component
{
    /**
     * Renglón del encabezado de cada área en el menú abierto: los dos primeros son del buscador.
     */
    private const int FIRST_AREA_ROW = 3;

    private const int TABLET_COLUMNS = 3;

    /**
     * Nombre de la ruta de la pantalla; por omisión, la de la petición que pinta el layout.
     */
    #[Locked]
    public ?string $route = null;

    public function mount(?string $route = null): void
    {
        $this->route = $route ?? request()->route()?->getName();
    }

    /**
     * @return array{areas: list<array{area: NavigationArea, current: bool, style: string, entries: list<array{item: NavigationItem, active: bool, style: string}>}>}
     */
    public function with(Navigation $navigation, ScopedContext $context): array
    {
        $groups = $navigation->visibleByArea($context);
        $routeItem = $this->route === null ? null : $navigation->forRoute($this->route);
        $visibleAreas = array_column($groups, 'area');
        $currentArea = in_array($routeItem?->area, $visibleAreas, true) ? $routeItem->area : ($visibleAreas[0] ?? null);

        $longestOnTop = max([0, ...array_map(fn (array $group): int => count($group['items']), array_slice($groups, 0, self::TABLET_COLUMNS))]);
        $tabletWrapRow = self::FIRST_AREA_ROW + $longestOnTop + 2;

        $areas = [];

        foreach ($groups as $position => $group) {
            $number = sprintf('--c: %d', $position + 1);
            $tablet = sprintf(
                '--col-t: %d; --base-t: %d',
                $position % self::TABLET_COLUMNS + 1,
                $position < self::TABLET_COLUMNS ? self::FIRST_AREA_ROW : $tabletWrapRow,
            );

            $entries = [];

            foreach ($group['items'] as $index => $item) {
                $entries[] = [
                    'item' => $item,
                    'active' => $item === $routeItem,
                    'style' => sprintf('%s; --i: %d; %s', $number, $index + 1, $tablet),
                ];
            }

            $areas[] = [
                'area' => $group['area'],
                'current' => $group['area'] === $currentArea,
                'style' => "{$number}; {$tablet}",
                'entries' => $entries,
            ];
        }

        return ['areas' => $areas];
    }
};
