<?php

namespace App\Modules\CatalogValidation\Jobs;

use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * «Recordar a todos los pendientes» del tablero de avance (tarea 2.5): avisar a los jefes de varios departamentos en
 * varios campus tarda, así que va a la cola (CLAUDE.md, §4.7). Lleva solo ids; la pantalla ya autorizó
 * (CatalogReviewPolicy::remind) y a quién se recuerda lo decide la lista de departamentos que le pasó.
 */
final class RemindPendingReviewsJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $campusIds
     * @param  list<string>  $departmentIds
     */
    public function __construct(
        public readonly string $termId,
        public readonly array $campusIds,
        public readonly string $actorId,
        public readonly array $departmentIds,
    ) {}

    public function handle(RemindPendingReviews $remind): void
    {
        $term = Term::query()->find($this->termId);
        $actor = User::query()->find($this->actorId);

        if ($term === null || $actor === null) {
            return;
        }

        $remind->handle($term, Campus::query()->whereIn('id', $this->campusIds)->orderBy('code')->get(), $actor, $this->departmentIds);
    }
}
