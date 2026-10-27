<?php

namespace Tests\Fixtures\Modules\DemoCatalog;

use App\Modules\Core\ModuleServiceProvider;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Fixtures\Modules\DemoCatalog\Policies\NotificationPolicy;

/**
 * Módulo de ejemplo solo para pruebas de ModuleServiceProvider; no se registra en la app.
 */
final class DemoCatalogServiceProvider extends ModuleServiceProvider
{
    protected array $policies = [
        DatabaseNotification::class => NotificationPolicy::class,
    ];
}
