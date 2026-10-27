<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

/**
 * Fija en la sesión el campus y el periodo activos del usuario autenticado (selector de la
 * topbar). El campus debe estar entre los que ScopedContext::campuses() le ofrece; cualquier
 * periodo vale. `null` olvida la elección y deja el de omisión. Solo escribe en la sesión, así que
 * no abre transacción ni deja bitácora.
 */
final class SetActiveContext
{
    public function __construct(
        private readonly ScopedContext $context,
        private readonly Session $session,
    ) {}

    /**
     * @throws AuthorizationException si no hay usuario o el campus no es de su alcance
     */
    public function handle(?Campus $campus, ?Term $term): void
    {
        if ($this->context->user() === null) {
            throw new AuthorizationException('Inicia sesión para elegir campus y periodo.');
        }

        if ($campus !== null && ! $this->context->campuses()->contains($campus)) {
            throw new AuthorizationException('No trabajas en ese campus.');
        }

        $this->session->put([
            ScopedContext::ACTIVE_CAMPUS => $campus?->getKey(),
            ScopedContext::ACTIVE_TERM => $term?->getKey(),
        ]);
    }
}
