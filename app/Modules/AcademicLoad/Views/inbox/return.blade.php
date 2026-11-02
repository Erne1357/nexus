{{--
    El drawer de regresar una parte (academic-load::pages.inbox), a la derecha para que la tabla de materias siga a
    la vista: las anomalías de la carrera para citarlas (un clic las agrega al comentario) y el comentario, obligatorio
    (hasta 1000 caracteres, con su contador), que le llega al coordinador. Confirmar llama a `returnPart`;
    `close()` es del x-drawer.
--}}
<div class="flex min-h-dvh flex-col" data-testid="return-panel">
    <header class="flex items-start gap-3 border-b border-base-300 py-4 ps-5 pe-3">
        <h2 id="return-title" class="min-w-0 flex-1 text-xl font-bold leading-tight">Regresar la parte de {{ $target['career']->code }}</h2>
        <x-button icon="o-x-mark" class="btn-ghost btn-sm btn-circle max-md:h-11 max-md:w-11" @click="close()" aria-label="Cerrar el panel" />
    </header>

    <div class="flex-1 space-y-5 px-5 py-4">
        @if ($targetAnomalies !== [])
            <section aria-labelledby="return-anomalies-title" data-testid="return-anomalies">
                <h3 id="return-anomalies-title" class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Anomalías de {{ $target['career']->code }}</h3>
                <ul class="mt-2 divide-y divide-base-300 rounded-box border border-base-300">
                    @foreach ($targetAnomalies as $entry)
                        @foreach ($entry['anomalies'] as $anomaly)
                            @php($citation = $entry['item']->subject->code.': '.$anomaly.'.')
                            <li class="flex items-center gap-2 px-3 py-2 text-sm">
                                <x-icon name="o-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning" />
                                <span class="min-w-0 flex-1"><span class="font-mono text-[15px] font-semibold">{{ $entry['item']->subject->code }}</span> · {{ $anomaly }}</span>
                                <button type="button" class="btn btn-sm btn-ghost max-md:h-11 max-md:min-h-11"
                                    x-on:click="$wire.comment = ($wire.comment ? $wire.comment + '\n' : '') + @js($citation)">Citar</button>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </section>
        @endif

        <div>
            <x-textarea label="Comentario para el coordinador" wire:model.live.debounce.300ms="comment" rows="6" maxlength="1000" data-testid="return-comment" />
            <p class="mt-1 text-right font-mono text-sm tabular-nums text-neutral" data-testid="return-counter" aria-live="polite">{{ mb_strlen($comment) }} / 1000</p>
        </div>
    </div>

    <footer class="sticky bottom-0 flex justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" @click="close()" />
        <x-button label="Regresar" icon="o-arrow-uturn-left" class="btn-outline max-md:h-11 max-md:min-h-11" wire:click="returnPart" spinner="returnPart" data-testid="confirm-return" />
    </footer>
</div>
