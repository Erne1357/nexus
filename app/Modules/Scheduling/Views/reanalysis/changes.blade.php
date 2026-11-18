{{--
    «Lo que ya cambiaste» (drawer a la derecha, `max-w-lg`; ADR-0023): los grupos nuevos, cancelados y cambiados contra la
    versión vigente (SnapshotDiff, el mismo diff que guardará la publicación de la versión n + 1), cada uno con su clave,
    su campus, qué le pasó y el detalle en palabras (SnapshotDiffText). Sin cambios, que todavía se puede cancelar el
    re-análisis (RA-10). `close()` es del x-drawer. `$entries`, `$load`, `$campusOptions` y `$mine` (si quien mira es quien ajusta).
--}}
<div class="flex min-h-dvh flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <div class="min-w-0 flex-1">
            <h2 id="changes-title" class="text-xl font-bold leading-tight">{{ $mine ? 'Lo que ya cambiaste' : 'Lo que ya cambió' }}</h2>
            <p class="mt-1 text-sm text-neutral">
                Contra la versión {{ $load->current_version }}@if ($load->published_at) publicada el {{ $load->published_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}@endif.
                Al publicar la {{ $load->current_version + 1 }} se avisa a los afectados.
            </p>
        </div>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 px-5 py-2">
        @if ($entries === [])
            <p class="py-4 text-neutral">{{ $mine ? 'Todavía no cambias nada: puedes cancelar el re-análisis y la carga vuelve a Publicada sin otra versión.' : 'Todavía no cambia nada contra la versión publicada.' }}</p>
        @else
            <ul class="grid" data-testid="changes-list">
                @foreach ($entries as $entry)
                    <li class="grid grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-3 border-t border-base-300 py-3 first:border-t-0" data-kind="{{ $entry['kind'] }}">
                        <x-icon :name="$entry['icon']" class="mt-0.5 h-5 w-5 text-neutral" aria-hidden="true" />
                        <div class="grid gap-0.5">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-mono text-[13.5px] font-semibold tabular-nums">{{ $entry['subject'] }} · {{ $entry['group'] }}</span>
                                <x-core::campus-tag :campus="$campusOptions->firstWhere('code', $entry['campus'])" />
                                <b class="font-semibold">{{ $entry['title'] }}</b>
                            </span>
                            <span class="text-sm text-neutral">{{ $entry['subjectName'] }} · {{ $entry['detail'] }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <footer class="sticky bottom-0 flex justify-end border-t border-base-300 bg-base-100 px-5 py-3">
        <x-button label="Cerrar" class="max-md:h-11 max-md:w-full" @click="close()" />
    </footer>
</div>
