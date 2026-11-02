<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre un periodo en un campus (D1, confirmado el 1 de noviembre de 2026), o completa una apertura ya
 * hecha. Core no conoce cargas ni revisiones: guarda la apertura y despacha TermOpened, y los módulos de
 * la derecha crean lo suyo para el periodo y el campus (S-A6, supuesto por confirmar). Devuelve las
 * líneas que reportaron, vacías si no faltaba nada.
 *
 * El campus debe estar activo y sin borrar (se relee en la transacción, con bloqueo compartido, para que
 * nadie lo desactive a media apertura); si no, una ValidationException sobre `campus`. En una
 * transacción bloquea el periodo, crea la apertura la primera vez (quién, el usuario autenticado, y
 * cuándo; volver a abrir no los cambia), despacha TermOpened dentro (sus listeners corren en la misma
 * transacción: si uno falla, no queda nada) y deja una entrada en la bitácora del periodo con el campus y
 * el reporte. Nunca cambia `is_current` (S-A4) ni cierra nada de otro periodo (D3). Quién puede abrir lo
 * decide TermPolicy antes de llamarla.
 */
final class OpenTerm
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @return list<string>
     *
     * @throws ValidationException si el campus está inactivo o borrado
     * @throws AuthorizationException si no hay usuario autenticado
     */
    public function handle(Term $term, Campus $campus): array
    {
        $openedBy = $this->auth->guard()->id() ?? throw new AuthorizationException('Inicia sesión para abrir el periodo.');

        return DB::transaction(function () use ($term, $campus, $openedBy): array {
            $current = Campus::withTrashed()->sharedLock()->findOrFail($campus->getKey());

            if (! $current->is_active || $current->trashed()) {
                throw ValidationException::withMessages([
                    'campus' => "El campus {$current->code} está inactivo o borrado: no se puede abrir el periodo en él.",
                ]);
            }

            $locked = Term::query()->lockForUpdate()->findOrFail($term->getKey());

            $opening = TermOpening::query()->firstOrCreate(
                ['term_id' => $locked->getKey(), 'campus_id' => $current->getKey()],
                ['opened_by' => $openedBy, 'opened_at' => now()],
            );

            $event = new TermOpened($locked, $current);
            event($event);

            activity()
                ->performedOn($locked)
                ->event('opened')
                ->withProperties(['campus_id' => $current->getKey(), 'report' => $event->lines()])
                ->log($opening->wasRecentlyCreated
                    ? "Abrió el periodo {$locked->code} en {$current->code}"
                    : "Completó la apertura del periodo {$locked->code} en {$current->code}");

            return $event->lines();
        });
    }
}
