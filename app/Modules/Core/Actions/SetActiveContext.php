<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Navigation\RecentPages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

/**
 * Fija en la sesión el periodo activo del usuario autenticado (selector de la credencial). Cualquier periodo vale;
 * `null` olvida la elección y deja el de omisión, el actual. La credencial ya no elige campus (#415): lo elige cada
 * pantalla en su URL. También olvida las páginas que el menú recuerda por área (RecentPages): una ficha de otro
 * periodo ya no aplicaría. Solo escribe en la sesión, así que no abre transacción ni deja bitácora.
 */
final class SetActiveContext
{
    public function __construct(
        private readonly Actor $context,
        private readonly Session $session,
        private readonly RecentPages $recentPages,
    ) {}

    /**
     * @throws AuthorizationException si no hay usuario
     */
    public function handle(?Term $term): void
    {
        if ($this->context->user() === null) {
            throw new AuthorizationException('Inicia sesión para elegir el periodo.');
        }

        $this->session->put(Actor::ACTIVE_TERM, $term?->getKey());

        $this->recentPages->forget();
    }
}
