<?php

namespace App\Modules\AcademicLoad\Http\Controllers\Concerns;

use App\Modules\AcademicLoad\Enums\ExportState;
use App\Modules\AcademicLoad\Exports\PublishedExport;
use App\Modules\AcademicLoad\Exports\PublishedExports;
use App\Modules\AcademicLoad\Queries\PublishedContextQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cómo se entrega un exporte de lo publicado (tareas 4.5 y 4.6), público o del personal: 404 si no está publicado,
 * autoriza antes de encolar (PublishedExportPolicy), encola con un tope por quien lo pide y, mientras la cola lo arma,
 * responde una página que se recarga sola o que dice que falló.
 *
 * Si la cola no lo deja listo a tiempo (PublishedExports::WAIT_SECONDS; incidencia F-05 del ensayo 8.11), la página deja
 * de recargarse, dice que tarda más de lo normal y ofrece «Reintentar»: la misma URL con `?reintentar=1`, que vuelve a
 * encolarlo (con el mismo tope que un exporte nuevo) y redirige a la URL sin el parámetro, para que recargar la página no
 * lo encole otra vez. Antes de que venza la espera, `reintentar` solo redirige.
 */
trait DeliversPublishedExports
{
    /** Exportes nuevos que encola una IP o un usuario por minuto (supuesto por confirmar). */
    public const int ENQUEUES_PER_MINUTE = 30;

    /** El parámetro de «Reintentar» en la URL del exporte. */
    public const string RETRY_PARAMETER = 'reintentar';

    /**
     * El periodo, la modalidad activa (por su clave, en cualquier capitalización; M1) y el campus del filtro (`?campus=`,
     * activo) de la URL; 404 si alguno no existe.
     *
     * @return array{0: Term, 1: Modality, 2: ?Campus}
     */
    private function context(string $termCode, string $modalityCode, ?string $campusCode = null): array
    {
        $campusCode = $campusCode === '' ? null : $campusCode;

        return [
            Term::query()->where('code', PublishedContextQuery::isCode($termCode) ? $termCode : '')->firstOrFail(),
            PublishedContextQuery::modality($modalityCode) ?? abort(404),
            $campusCode === null ? null : Campus::query()->where('code', PublishedContextQuery::isCode($campusCode) ? $campusCode : '')->where('is_active', true)->firstOrFail(),
        ];
    }

    private function deliver(Request $request, ?PublishedExport $export): Response|StreamedResponse|RedirectResponse
    {
        abort_if($export === null, 404);
        Gate::authorize('download', $export);

        $exports = app(PublishedExports::class);
        $state = $exports->state($export);

        if ($request->query->has(self::RETRY_PARAMETER)) {
            if ($state === ExportState::Stalled) {
                $this->throttleEnqueues($request);
                $exports->retry($export);
            }

            return redirect()->to($request->fullUrlWithoutQuery(self::RETRY_PARAMETER));
        }

        if ($state === ExportState::Missing) {
            $this->throttleEnqueues($request);
            $state = $exports->request($export);
        }

        if ($state === ExportState::Ready) {
            return $exports->download($export);
        }

        return response()->view('academic-load::published.preparing', [
            'filename' => $export->filename(),
            'failure' => $state === ExportState::Failed ? $exports->failure($export) : null,
            'retryUrl' => $state === ExportState::Stalled ? $request->fullUrlWithQuery([self::RETRY_PARAMETER => 1]) : null,
            'waitMinutes' => intdiv(PublishedExports::WAIT_SECONDS, 60),
        ], $state === ExportState::Failed ? 503 : 202)->header('Cache-Control', 'no-store');
    }

    /**
     * El tope de exportes que encola quien lo pide (ENQUEUES_PER_MINUTE): 429 si ya lo pasó.
     */
    private function throttleEnqueues(Request $request): void
    {
        abort_unless(RateLimiter::attempt('published-exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip()), self::ENQUEUES_PER_MINUTE, fn (): bool => true), 429);
    }
}
