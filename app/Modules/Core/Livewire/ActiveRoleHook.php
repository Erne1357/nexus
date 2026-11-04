<?php

namespace App\Modules\Core\Livewire;

use App\Modules\Core\Authorization\Actor;
use Livewire\ComponentHook;

/**
 * AZ-S5: una pestaña pintada con un perfil no puede mandar acciones con otro. Al deshidratar guarda en el memo
 * `nexus_role` el perfil activo; al hidratar, si el memo no coincide con el perfil activo de la sesión, responde 419
 * con el encabezado `X-Nexus-Role-Changed: 1`, que resources/js/app.js usa para ofrecer recargar con un mensaje
 * neutro. No distingue por qué cambió: la sesión cambió de perfil en otra pestaña, o cambiaron sus puestos (perdió
 * el activo, se desactivó el campus de su único nombramiento o recibió el primero). Sin usuario autenticado,
 * con un memo pintado sin usuario o con otro usuario (`nexus_user`), no hace nada. La petición que cambia el
 * perfil no se rechaza: el cambio ocurre en su acción, después de hidratar.
 *
 * API confirmada en livewire/livewire v4.4.8: ComponentHook::hydrate($memo, $context) y dehydrate($context);
 * $context->addMemo(); registro con ComponentHookRegistry::register() desde CoreServiceProvider::register()
 * (Livewire::componentHook() desde boot() llega tarde: los hooks se cablean en
 * LivewireServiceProvider::boot()); en JS, Livewire.interceptRequest(({ onError }) => …) con onError({ response, preventDefault }) (el manejo por omisión del 419 es un confirm en inglés).
 */
final class ActiveRoleHook extends ComponentHook
{
    public const string MEMO = 'nexus_role';

    public const string USER_MEMO = 'nexus_user';

    public const string HEADER = 'X-Nexus-Role-Changed';

    public function hydrate(array $memo): void
    {
        if (! array_key_exists(self::MEMO, $memo)) {
            return;
        }

        $context = app(Actor::class);

        $user = $context->user();

        // Otra persona en la misma pantalla no es un cambio de perfil: eso lo resuelven las policies de cada acción.
        if ($user === null || ($memo[self::USER_MEMO] ?? null) !== $user->getKey()) {
            return;
        }

        if ($memo[self::MEMO] !== $context->activeRole()?->value) {
            abort(419, '', [self::HEADER => '1']);
        }
    }

    public function dehydrate($context): void
    {
        $scoped = app(Actor::class);

        if ($scoped->user() === null) {
            return;
        }

        $context->addMemo(self::USER_MEMO, $scoped->user()->getKey());
        $context->addMemo(self::MEMO, $scoped->activeRole()?->value);
    }
}
