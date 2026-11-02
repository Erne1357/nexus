{{--
    El drawer de regresar una parte (academic-load::pages.inbox), a la derecha para que la tabla de materias siga a
    la vista: las anomalías de la carrera para citarlas (un clic las agrega al comentario) y el comentario, obligatorio
    (hasta 1000 caracteres, con su contador), que le llega al coordinador. Confirmar llama a `returnPart`;
    `close()` es del x-drawer.
--}}
@php($max = \App\Modules\AcademicLoad\Rules\ProposalReviewRules::MAX_COMMENT_LENGTH)
<div class="flex min-h-dvh flex-col" data-testid="return-panel"
    x-data="{
        max: {{ $max }},
        full: false,
        cite(text) {
            const current = $wire.comment ?? ''
            const next = current === '' ? text : current + '\n' + text
            this.full = next.length > this.max
            if (! this.full) { $wire.comment = next }
        },
    }">
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
                                    data-testid="return-cite" x-on:click="cite(@js($citation))">Citar</button>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </section>
        @endif

        <div>
            <x-textarea label="Comentario para el coordinador" wire:model="comment" rows="6" maxlength="{{ $max }}" data-testid="return-comment" />
            <p class="mt-1 flex justify-between gap-3 text-sm text-neutral">
                <span x-show="full" x-cloak class="text-left" role="status">No cabe la cita en el comentario.</span>
                <span class="ms-auto font-mono tabular-nums" data-testid="return-counter"><span x-text="($wire.comment ?? '').length">0</span> / {{ $max }}</span>
            </p>
        </div>
    </div>

    <footer class="sticky bottom-0 flex justify-end gap-2 border-t border-base-300 bg-base-100 px-5 py-3">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" @click="close()" />
        <x-button label="Regresar" icon="o-arrow-uturn-left" class="btn-outline max-md:h-11 max-md:min-h-11" wire:click="returnPart" spinner="returnPart" data-testid="confirm-return" />
    </footer>
</div>
