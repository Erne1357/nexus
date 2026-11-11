{{-- Mientras la cola arma un exporte de lo publicado (DeliversPublishedExports), público o del personal: se recarga cada 3 s y, en cuanto está, el navegador lo
     descarga. Si falló, lo dice sin el error interno y deja volver. --}}
<x-core::layouts.guest title="Preparando el archivo">
    @if (! $failure)
        @push('head')
            <meta http-equiv="refresh" content="3">
        @endpush
    @endif
    <div class="card w-full max-w-md border border-base-300 bg-base-100">
        <div class="card-body gap-3">
            @if ($failure)
                <h1 class="text-xl font-bold">No se pudo preparar el archivo</h1>
                <p class="text-secondary-muted">{{ $failure }}</p>
            @else
                <h1 class="flex items-center gap-3 text-xl font-bold">
                    <span class="loading loading-spinner loading-sm" aria-hidden="true"></span>
                    Preparando el archivo
                </h1>
                <p class="text-secondary-muted">«{{ $filename }}» se descarga solo en unos segundos.</p>
            @endif
            <a href="{{ url()->previous() === url()->current() ? route('home') : url()->previous() }}" class="btn btn-outline btn-sm w-max">Volver</a>
        </div>
    </div>
</x-core::layouts.guest>
