<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\User;
use App\Modules\Core\Policies\Concerns\AuthorizesActor;
use App\Modules\Core\Settings\SettingDefinition;

/**
 * Quién ve y cambia los parámetros de los módulos (CF-S7): ver con `core.setting.view` y cambiar o volver al valor por
 * omisión con `core.setting.update`. Se pueden dar a otro puesto (CF-03); de fábrica el admin tiene los dos y la Subdirección solo ve. Un parámetro puede declarar el permiso de su módulo que deja cambiarlo (la Subdirección cambia los de demanda, confirmado el 16 de noviembre de 2026).
 */
final class SettingPolicy
{
    use AuthorizesActor;

    public function viewAny(User $user): bool
    {
        return $this->actor($user)->allowsAny(CorePermission::SettingView);
    }

    /**
     * Cambiar un parámetro o volverlo a su valor por omisión; se pregunta con la clase (`Setting::class`) y, para un
     * parámetro en concreto, con la clase y su definición (`[Setting::class, $definition]`). Sin definición, o si esta no
     * declara un permiso propio, es `core.setting.update`; si lo declara, vale ese o `core.setting.update`.
     */
    public function update(User $user, ?SettingDefinition $definition = null): bool
    {
        $actor = $this->actor($user);

        return $actor->allowsAny(CorePermission::SettingUpdate)
            || ($definition?->permission !== null && $actor->allowsAny($definition->permission));
    }
}
