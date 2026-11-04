<?php

namespace Tests\Fixtures\Modules\DemoCatalog;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Fixtures\Modules\DemoCatalog\Enums\DemoCatalogPermission;
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

    public function permissions(): array
    {
        return [DemoCatalogPermission::class];
    }

    /**
     * El listado de elementos no se concede a ningún puesto (solo el admin); ver es de quien trabaja en el campus con
     * cualquier puesto de la lista, editar es del jefe de departamento y del coordinador, y el saludo, del coordinador.
     */
    public function grants(): array
    {
        return [
            Role::Subdireccion->value => [DemoCatalogPermission::ItemView],
            Role::JefeDepartamento->value => [DemoCatalogPermission::ItemView, DemoCatalogPermission::ItemUpdate],
            Role::JefeProyectoDocente->value => [DemoCatalogPermission::ItemView],
            Role::Coordinador->value => [DemoCatalogPermission::ItemView, DemoCatalogPermission::ItemUpdate, DemoCatalogPermission::GreetingView],
            Role::Docente->value => [DemoCatalogPermission::ItemView],
        ];
    }

    public function navigation(): array
    {
        return [
            new NavigationItem(
                label: 'Saludo',
                route: 'demo-catalog.greeting',
                icon: 'o-hand-raised',
                area: NavigationArea::ServiciosEscolares,
                visible: static fn (Actor $context): bool => $context->allowsAny(DemoCatalogPermission::GreetingView),
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
