<?php

namespace App\Modules\Core\Navigation;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\ModuleServiceProvider;
use Closure;
use SplObjectStorage;

/**
 * Registro del menú: las entradas que cada módulo declara en navigation() de su provider, en el orden
 * de bootstrap/providers.php y, dentro de un módulo, en el que las declara. El menú las agrupa por área,
 * en el orden de NavigationArea, y dentro de cada área por grupo, en el orden de NavigationGroup (MN-01):
 * primero las entradas sin grupo y, dentro de un grupo, por `order` y luego por el registro.
 *
 * Core lo arma una vez, cuando todos los módulos ya arrancaron, y lo comparte entre peticiones
 * (Octane): es inmutable y lo que depende del usuario o del periodo se calcula en visibleByArea() y
 * visibleTo(), sin guardarlo.
 */
final readonly class Navigation
{
    /**
     * Un área lleva encabezados de grupo solo con al menos tantos grupos con nombre (MN-01)...
     */
    public const int HEADINGS_MIN_GROUPS = 2;

    /**
     * ...y al menos tantas entradas; si no, es una lista plana en el orden de los grupos.
     */
    public const int HEADINGS_MIN_ENTRIES = 8;

    /**
     * Las entradas en el orden del menú.
     *
     * @var list<NavigationItem>
     */
    private array $menuOrder;

    /**
     * @param  list<NavigationItem>  $items
     */
    public function __construct(private array $items)
    {
        $areas = array_flip(array_map(fn (NavigationArea $area): string => $area->value, NavigationArea::cases()));
        $groups = array_flip(array_map(fn (NavigationGroup $group): string => $group->value, NavigationGroup::cases()));
        $keyed = [];

        foreach ($items as $index => $item) {
            $keyed[] = [[$areas[$item->area->value], $item->group === null ? -1 : $groups[$item->group->value], $item->order, $index], $item];
        }

        usort($keyed, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $this->menuOrder = array_column($keyed, 1);
    }

    /**
     * @param  iterable<ModuleServiceProvider>  $modules
     */
    public static function fromModules(iterable $modules): self
    {
        $items = [];

        foreach ($modules as $module) {
            array_push($items, ...$module->navigation());
        }

        return new self($items);
    }

    /**
     * Todas las entradas declaradas, para cualquier usuario, en el orden del registro.
     *
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Las entradas que el usuario del contexto ve en el menú, en su orden; ninguna sin usuario activo.
     *
     * @return list<NavigationItem>
     */
    public function visibleTo(Actor $context): array
    {
        $items = [];

        foreach ($this->visibleByArea($context) as $area) {
            foreach ($area['groups'] as $group) {
                foreach ($group['entries'] as $entry) {
                    $items[] = $entry->item;
                }
            }
        }

        return $items;
    }

    /**
     * El menú del usuario del contexto: las áreas en el orden de NavigationArea y solo las que tienen al
     * menos una entrada que ve; en cada una, sus grupos en el orden de NavigationGroup (las entradas sin
     * grupo primero, con `group` null) y solo los que tienen alguna. `headings` dice si el área muestra
     * los nombres de sus grupos (MN-01).
     *
     * Ve una entrada quien tiene permiso de verla (NavigationItem::isVisibleTo) y, si no está disponible
     * en el periodo activo, solo quien tiene `core.navigation.view_unavailable`, con el motivo en
     * MenuEntry::$hiddenReason (MN-02). Cada condición de disponibilidad se evalúa una sola vez por llamada,
     * aunque la compartan varias entradas, y nunca la de una entrada que el usuario no puede ver.
     *
     * @return list<array{area: NavigationArea, headings: bool, groups: non-empty-list<array{group: ?NavigationGroup, entries: non-empty-list<MenuEntry>}>}>
     */
    public function visibleByArea(Actor $context): array
    {
        /** @var SplObjectStorage<Closure, ?string> $reasons */
        $reasons = new SplObjectStorage;
        $seesUnavailable = null;
        $byArea = [];

        foreach ($this->menuOrder as $item) {
            if (! $item->isVisibleTo($context)) {
                continue;
            }

            $reason = null;

            if ($item->whyUnavailable !== null) {
                if (! $reasons->offsetExists($item->whyUnavailable)) {
                    $reasons[$item->whyUnavailable] = ($item->whyUnavailable)($context);
                }

                $reason = $reasons[$item->whyUnavailable];
            }

            if ($reason !== null && ! ($seesUnavailable ??= $context->allowsAny(CorePermission::NavigationViewUnavailable))) {
                continue;
            }

            $byArea[$item->area->value][$item->group->value ?? ''][] = new MenuEntry($item, $reason);
        }

        $menu = [];

        foreach ($byArea as $area => $groups) {
            $named = count(array_filter(array_keys($groups), fn (string|int $group): bool => $group !== ''));
            $entries = array_sum(array_map(count(...), $groups));

            $menu[] = [
                'area' => NavigationArea::from($area),
                'headings' => $named >= self::HEADINGS_MIN_GROUPS && $entries >= self::HEADINGS_MIN_ENTRIES,
                'groups' => array_map(
                    fn (string|int $group, array $entries): array => ['group' => NavigationGroup::tryFrom((string) $group), 'entries' => $entries],
                    array_keys($groups),
                    array_values($groups),
                ),
            ];
        }

        return $menu;
    }

    /**
     * La entrada declarada para una ruta, la vea o no el usuario (p. ej. para mostrar
     * «área › pantalla»): la que la declara como suya o como pantalla hija (`childRoutes`), la
     * primera si varias, y null si ninguna. No adivina por el prefijo del nombre.
     */
    public function forRoute(string $route): ?NavigationItem
    {
        foreach ($this->items as $item) {
            if ($item->owns($route)) {
                return $item;
            }
        }

        return null;
    }
}
