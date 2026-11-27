{{-- Mientras la cola arma un exporte de lo publicado (DeliversPublishedExports), público o del personal: se recarga cada 3 s y, en cuanto está, el navegador lo
     descarga. Si falló, lo dice sin el error interno y deja volver. Si la cola no lo dejó listo a tiempo (`$retryUrl`; F-05 del ensayo 8.11), deja de
     recargarse, dice que tarda más de lo normal con `o-clock` en `warning` y ofrece «Reintentar» (`btn-primary`), que lo vuelve a encolar, junto a «Volver». --}}
<x-core::layouts.guest :title="$retryUrl ? 'El archivo está tardando' : 'Preparando el archivo'">
    @if (! $failure && ! $retryUrl)
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endif
    <div class="card w-full max-w-md border border-base-300 bg-base-100">
        <div class="card-body gap-3">
            @if ($failure)
                <h1 class="text-xl font-bold">No se pudo preparar el archivo</h1>
                <p class="text-secondary-muted">{{ $failure }}</p>
            @elseif ($retryUrl)
                <h1 class="flex items-start gap-3 text-xl font-bold text-balance">
                    <x-icon name="o-clock" class="mt-1 h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                    El archivo está tardando más de lo normal
                </h1>
                <p class="text-secondary-muted">«{{ $filename }}» lleva más de {{ $waitMinutes }} minutos en espera: el servidor puede estar ocupado. Reintenta y, si vuelve a pasar, avisa a quien administra Nexus.</p>
            @else
                <h1 class="flex items-center gap-3 text-xl font-bold">
                    <span class="loading loading-spinner loading-sm" aria-hidden="true"></span>
                    Preparando el archivo
                </h1>
                <p class="text-secondary-muted">«{{ $filename }}» se descarga solo en unos segundos.</p>
            @endif
            <div class="flex flex-wrap gap-2">
                @if ($retryUrl)
                    <a href="{{ $retryUrl }}" class="btn btn-primary btn-sm max-md:h-11" data-testid="export-retry">Reintentar</a>
                @endif
                <a href="{{ url()->previous() === url()->current() ? route('home') : url()->previous() }}" @class(['btn btn-outline btn-sm w-max', 'max-md:h-11' => $retryUrl])>Volver</a>
            </div>
        </div>
    </div>
</x-core::layouts.guest>
