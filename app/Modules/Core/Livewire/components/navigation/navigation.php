<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Navigation\MenuEntry;
use App\Modules\Core\Navigation\MenuGrid;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Navigation\RecentPages;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * El menú que se transforma (DESIGN.md, "El menú que se transforma"): Inicio y las entradas del registro
 * Navigation que el usuario puede ver, por área y, dentro de un área, por grupo (MN-01). Cerrado, la
 * franja de iconos (Inicio y un icono por área) se queda fija y la columna de al lado muestra el área
 * actual y se desplaza sola; abierto, el buscador y todas las áreas en la retícula de MenuGrid. Abrir,
 * cerrar, buscar y animar lo hace el componente Alpine `nexusShell` (resources/js/app.js).
 *
 * Inicio no es una entrada del registro: es el primer icono de la franja, lo ve cualquier usuario activo y
 * en Inicio lleva aria-current. El área actual es la de la entrada de la pantalla (Navigation::forRoute)
 * si el usuario la ve; si no, la primera que ve. Su columna se muestra cerrada y su icono se marca, salvo
 * en Inicio, donde la columna muestra la primera área sin marcarla. La entrada activa es la de la pantalla.
 *
 * El icono de cada área es un enlace: el del área actual lleva a su primera entrada (la principal); el de
 * las demás, a la última página que el usuario visitó de esa área (RecentPages) o, si no ha visitado
 * ninguna, a su primera entrada. Al pintarse en una página completa, el menú anota esa página como la
 * última de su área.
 *
 * Cada icono, nombre, encabezado de grupo y entrada lleva su lugar en la retícula del menú abierto:
 * `--oc`, `--or` y `--os` (columna, renglón y columnas que abarca) con cinco columnas, y `--oc3`, `--or3`
 * y `--os3` con las tres de la tableta.
 *
 * Lo oculto para los demás (MN-02, solo lo recibe el admin, con su motivo en MenuEntry::$hiddenReason) se marca
 * con la insignia «Oculta» (DESIGN.md, "Navigation"): en cada entrada oculta, salvo que su grupo entero esté
 * oculto por un solo motivo y lleve encabezado, que entonces lo dice una vez; en el encabezado de un grupo
 * entero oculto, y junto al nombre y en el icono de un área entera oculta. Si un grupo o un área están ocultos
 * se decide con todas sus entradas, aunque el buscador muestre solo algunas.
 */
new class extends Component
{
    private const int DESKTOP_COLUMNS = 5;

    private const int TABLET_COLUMNS = 3;

    /**
     * Nombre de la ruta de la pantalla; por omisión, la de la petición que pinta el layout.
     */
    #[Locked]
    public ?string $route = null;

    public function mount(RecentPages $recentPages, ?string $route = null): void
    {
        $this->route = $route ?? request()->route()?->getName();

        // Solo la página que se pidió cuenta como visita, no una ruta que llega dada.
        if ($route === null && $this->route !== null) {
            $recentPages->remember($this->route, request()->getRequestUri());
        }
    }

    /**
     * @return array{home: array{href: string, current: bool}, areas: list<array{area: NavigationArea, current: bool, marked: bool, headings: bool, href: string, iconStyle: string, nameStyle: string, hidden: ?array{why: string, id: string}, groups: list<array{group: ?NavigationGroup, style: ?string, hidden: ?array{why: string, reasons: string, note: ?string}, entries: list<array{item: NavigationItem, active: bool, page: bool, style: string, hidden: ?array{why: string, id: string, badge: bool, note: ?string}}>}>}>, grid: array{firstRow: int, columnRows: int}}
     */
    public function with(Navigation $navigation, Actor $context, RecentPages $recentPages): array
    {
        $menu = $navigation->visibleByArea($context);
        $routeItem = $this->route === null ? null : $navigation->forRoute($this->route);
        $onHome = $this->route === 'home';
        $visibleAreas = array_column($menu, 'area');
        $currentArea = in_array($routeItem?->area, $visibleAreas, true) ? $routeItem->area : ($visibleAreas[0] ?? null);

        $shape = array_map(fn (array $area): array => [
            'headings' => $area['headings'],
            'groups' => array_map(
                fn (array $group): array => ['heading' => $area['headings'] && $group['group'] !== null, 'entries' => count($group['entries'])],
                $area['groups'],
            ),
        ], $menu);

        $wide = MenuGrid::place($shape, self::DESKTOP_COLUMNS);
        $narrow = MenuGrid::place($shape, self::TABLET_COLUMNS);

        $areas = [];

        foreach ($menu as $index => $area) {
            $groups = [];

            foreach ($area['groups'] as $position => $group) {
                $heading = $wide[$index]['groups'][$position]['heading'];
                $groupReasons = $this->reasonsIfAllHidden($group['entries']);
                // Con encabezado y un solo motivo, el grupo lo dice una vez y sus entradas no llevan insignia.
                $covered = $heading !== null && $groupReasons !== null && count($groupReasons) === 1;
                $entries = [];

                foreach ($group['entries'] as $order => $entry) {
                    $entries[] = [
                        'item' => $entry->item,
                        'active' => $entry->item === $routeItem,
                        // La entrada es la página misma, o el listado de la pantalla hija en que se está.
                        'page' => $entry->item === $routeItem && $entry->item->route === $this->route,
                        'style' => $this->placeOf($wide[$index]['groups'][$position]['entries'][$order], $narrow[$index]['groups'][$position]['entries'][$order]),
                        'hidden' => $entry->hiddenReason === null ? null : [
                            'why' => "Oculta para los demás: {$entry->hiddenReason}",
                            'id' => "nexus-menu-why-{$area['area']->value}-{$position}-{$order}",
                            'badge' => ! $covered,
                            'note' => $covered ? null : $entry->hiddenReason,
                        ],
                    ];
                }

                $groups[] = [
                    'group' => $heading === null ? null : $group['group'],
                    'style' => $heading === null ? null : $this->placeOf($heading, $narrow[$index]['groups'][$position]['heading']),
                    'hidden' => $heading === null || $groupReasons === null ? null : [
                        'why' => 'Oculto para los demás: '.implode('. ', $groupReasons),
                        'reasons' => implode('. ', $groupReasons),
                        'note' => $covered ? $groupReasons[0] : null,
                    ],
                    'entries' => $entries,
                ];
            }

            $areaReasons = $this->reasonsIfAllHidden(array_merge(...array_column($area['groups'], 'entries')));

            $current = $area['area'] === $currentArea;
            $main = route($area['groups'][0]['entries'][0]->item->route);

            $areas[] = [
                'area' => $area['area'],
                'current' => $current,
                'marked' => $current && ! $onHome,
                'headings' => $area['headings'],
                'href' => $current ? $main : ($recentPages->last($area['area']) ?? $main),
                'iconStyle' => $this->placeOf($wide[$index]['icon'], $narrow[$index]['icon']),
                'nameStyle' => $this->placeOf($wide[$index]['name'], $narrow[$index]['name']),
                'hidden' => $areaReasons === null ? null : [
                    'why' => 'Oculta para los demás: '.implode('. ', $areaReasons),
                    'id' => "nexus-menu-why-{$area['area']->value}",
                ],
                'groups' => $groups,
            ];
        }

        return [
            'home' => ['href' => route('home'), 'current' => $onHome],
            'areas' => $areas,
            'grid' => ['firstRow' => MenuGrid::FIRST_ROW, 'columnRows' => MenuGrid::COLUMN_ROWS],
        ];
    }

    /**
     * Los motivos distintos, en orden, si todas las entradas están ocultas para los demás; si alguna no, null.
     *
     * @param  list<MenuEntry>  $entries
     * @return non-empty-list<string>|null
     */
    private function reasonsIfAllHidden(array $entries): ?array
    {
        $reasons = array_map(fn (MenuEntry $entry): ?string => $entry->hiddenReason, $entries);

        return $reasons === [] || in_array(null, $reasons, true) ? null : array_values(array_unique($reasons));
    }

    /**
     * Las variables de un elemento con su lugar en la retícula de cinco columnas y en la de tres.
     *
     * @param  array{column: int, row: int, span: int}  $wide
     * @param  array{column: int, row: int, span: int}  $narrow
     */
    private function placeOf(array $wide, array $narrow): string
    {
        return sprintf(
            '--oc: %d; --or: %d; --os: %d; --oc3: %d; --or3: %d; --os3: %d',
            $wide['column'], $wide['row'], $wide['span'], $narrow['column'], $narrow['row'], $narrow['span'],
        );
    }
};
