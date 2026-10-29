<?php

namespace Tests\Fixtures\Modules\DemoCatalog;

use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Fixtures\Modules\DemoCatalog\Models\Item;
use Tests\Fixtures\Modules\DemoCatalog\Policies\NotificationPolicy;

/**
 * Módulo de ejemplo solo para pruebas de ModuleServiceProvider y de la navegación; no se registra
 * en la app.
 */
final class DemoCatalogServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        DatabaseNotification::class => NotificationPolicy::class,
    ];

    public function navigation(): array
    {
        return [
            new NavigationItem(
                label: 'Saludo',
                route: 'demo-catalog.greeting',
                icon: 'o-hand-raised',
                area: NavigationArea::ServiciosEscolares,
                visible: static fn (ScopedContext $context): bool => $context->has(Role::Coordinador),
            ),
            new NavigationItem(
                label: 'Elementos',
                route: 'demo-catalog.ping',
                icon: 'o-rectangle-stack',
                area: NavigationArea::RecursosHumanos,
                ability: 'viewAny',
                arguments: [Item::class],
            ),
        ];
    }
}
