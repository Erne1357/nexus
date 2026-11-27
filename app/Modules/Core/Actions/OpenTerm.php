<?php

namespace App\Modules\Core\Actions;

use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre un periodo en una modalidad (M4, confirmado el 12 de noviembre de 2026: Presencial se abre una vez para C1 y
 * C2), o completa una apertura ya hecha. Core no conoce cargas ni revisiones: guarda la apertura y despacha TermOpened,
 * y los módulos de la derecha crean lo suyo para el periodo y la modalidad (MS15, supuesto por confirmar; ver
 * docs/dominio/reglas-de-negocio.md, Periodo y calendario). Devuelve las líneas que reportaron, vacías si no faltaba
 * nada.
 *
 * La modalidad debe estar activa y sin borrar (se relee en la transacción, con bloqueo compartido, para que nadie la
 * desactive a media apertura); si no, una ValidationException sobre `modality`. En una transacción bloquea el periodo
 * (así dos aperturas a la vez de la misma modalidad se forman una tras otra y la segunda solo completa), crea la
 * apertura la primera vez (quién, el usuario autenticado, y cuándo; volver a abrir no los cambia), despacha
 * TermOpened dentro (sus listeners corren en la misma transacción: si uno falla, no queda nada) y deja una entrada en
 * la bitácora del periodo con la modalidad y el reporte. Nunca cambia `is_current` (ver docs/dominio/
 * reglas-de-negocio.md, Periodo y calendario) ni cierra nada de otro periodo. Quién puede abrir lo decide TermPolicy
 * antes de llamarla.
 */
final class OpenTerm
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @return list<string>
     *
     * @throws ValidationException si la modalidad está inactiva o borrada
     * @throws AuthorizationException si no hay usuario autenticado
     */
    public function handle(Term $term, Modality $modality): array
    {
        $openedBy = $this->auth->guard()->id() ?? throw new AuthorizationException('Inicia sesión para abrir el periodo.');

        return DB::transaction(function () use ($term, $modality, $openedBy): array {
            $current = Modality::withTrashed()->sharedLock()->findOrFail($modality->getKey());

            if (! $current->is_active || $current->trashed()) {
                throw ValidationException::withMessages([
                    'modality' => "La modalidad {$current->code} está inactiva o borrada: no se puede abrir el periodo en ella.",
                ]);
            }

            $locked = Term::query()->lockForUpdate()->findOrFail($term->getKey());

            $opening = TermOpening::query()->firstOrCreate(
                ['term_id' => $locked->getKey(), 'modality_id' => $current->getKey()],
                ['opened_by' => $openedBy, 'opened_at' => now()],
            );

            $event = new TermOpened($locked, $current);
            event($event);

            activity()
                ->performedOn($locked)
                ->event('opened')
                ->withProperties(['modality_id' => $current->getKey(), 'report' => $event->lines()])
                ->log($opening->wasRecentlyCreated
                    ? "Abrió el periodo {$locked->code} en {$current->name}"
                    : "Completó la apertura del periodo {$locked->code} en {$current->name}");

            return $event->lines();
        });
    }
}
