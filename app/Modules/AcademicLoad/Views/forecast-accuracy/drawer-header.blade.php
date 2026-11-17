{{-- El encabezado de un drawer de la pantalla: `$id`, `$title` (HTML ya escapado) y `$subtitle`; la X lo cierra. --}}
<header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
    <div class="min-w-0 flex-1">
        <h2 id="{{ $id }}" class="text-xl font-bold leading-tight">{!! $title !!}</h2>
        @if (filled($subtitle ?? null))
            <p class="mt-1 text-sm text-neutral">{{ $subtitle }}</p>
        @endif
    </div>
    <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
</header>
