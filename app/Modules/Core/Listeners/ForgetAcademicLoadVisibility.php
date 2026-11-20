<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Events\SettingsUpdated;

/**
 * Si cambió el interruptor de la carga académica (MN-05), olvida el valor que la petición ya había leído, para que lo
 * que se pinte después en la misma petición use el nuevo.
 */
final class ForgetAcademicLoadVisibility
{
    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    public function handle(SettingsUpdated $event): void
    {
        if (in_array(AcademicLoadVisibility::SETTING, $event->keys, true)) {
            $this->visibility->forget();
        }
    }
}
