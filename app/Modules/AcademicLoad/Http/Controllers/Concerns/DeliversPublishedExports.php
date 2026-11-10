<?php

namespace App\Modules\AcademicLoad\Http\Controllers\Concerns;

use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cómo se entrega un exporte de lo publicado (tareas 4.5 y 4.6), público o del personal: 404 si no está publicado,
 * autoriza antes de encolar (PublishedExportPolicy), encola con un tope por quien lo pide y, mientras la cola lo arma,
 * responde una página que se recarga sola o que dice que falló.
 */
trait DeliversPublishedExports
{
    /** Exportes nuevos que encola una IP o un usuario por minuto (supuesto por confirmar). */
    public const int ENQUEUES_PER_MINUTE = 30;

    /**
     * @return array{0: Term, 1: Campus}
     */
    private function context(string $termCode, string $campusCode): array
    {
        return [
            Term::query()->where('code', PublishedContextQuery::isCode($termCode) ? $termCode : '')->firstOrFail(),
            Campus::query()->where('code', PublishedContextQuery::isCode($campusCode) ? $campusCode : '')->where('is_active', true)->firstOrFail(),
        ];
    }

    private function deliver(Request $request, ?PublishedExport $export): Response|StreamedResponse
    {
        abort_if($export === null, 404);
        Gate::authorize('download', $export);

        $exports = app(PublishedExports::class);
        $state = $exports->state($export);

        if ($state === ExportState::Missing) {
            abort_unless(RateLimiter::attempt('published-exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip()), self::ENQUEUES_PER_MINUTE, fn (): bool => true), 429);
            $state = $exports->request($export);
        }

        if ($state === ExportState::Ready) {
            return $exports->download($export);
        }

        return response()->view('academic-load::published.preparing', [
            'filename' => $export->filename(),
            'failure' => $state === ExportState::Failed ? $exports->failure($export) : null,
        ], $state === ExportState::Failed ? 503 : 202)->header('Cache-Control', 'no-store');
    }
}
