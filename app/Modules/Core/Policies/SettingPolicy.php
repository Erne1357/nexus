<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;

/**
 * Quién ve y cambia los parámetros de los módulos (CF-S7): ver con `core.setting.view` y cambiar o volver al valor por
 * omisión con `core.setting.update`. Se pueden dar a otro puesto (CF-03); de fábrica solo el admin los tiene.
 */
final class SettingPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::SettingView);
    }

    /**
     * Cambiar un parámetro o volverlo a su valor por omisión; se pregunta con la clase (`Setting::class`).
     */
    public function update(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::SettingUpdate);
    }
}
