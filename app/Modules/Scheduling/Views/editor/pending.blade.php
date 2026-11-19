{{--
    «Por colocar» en el editor de horarios (tarea 8.7): lo que otro módulo ya decidió para unos grupos y falta escribir
    (PendingPlacements; hoy, la alternativa del motor elegida en una solicitud de cambio), con el lenguaje de «Corridas del
    motor»: por grupo, cada sesión «Movida» (de dónde, tachado, a dónde), «Nueva», «Quitada» o «Sin cambio». Es
    informativa: no precoloca nada. A la derecha, «Volver a la solicitud».
--}}
<section class="rounded-box border border-warning/45 bg-warning/7 px-4 py-3.5 md:px-5" aria-labelledby="pending-title" data-testid="pending-placements">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
        <div class="min-w-0">
            <h2 id="pending-title" class="flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold">
                <x-core::status-badge tone="warning" icon="o-arrows-right-left" label="Por colocar" />
                <span>{{ $placements->title }}</span>
            </h2>
            <p class="mt-1 max-w-[75ch] text-sm text-neutral">{{ $placements->note }}</p>
        </div>
        @if ($placements->backUrl)
            <a href="{{ $placements->backUrl }}" wire:navigate class="btn btn-ghost btn-sm max-md:h-11 max-md:min-h-11"><x-icon name="o-arrow-uturn-left" class="h-4 w-4" aria-hidden="true" />Volver a la solicitud</a>
        @endif
    </div>
    <ul class="mt-3 grid gap-3 lg:grid-cols-2">
        @foreach ($placements->groups as $group)
            <li class="min-w-0 rounded-field border border-base-300 bg-base-100 px-3.5 py-2.5" wire:key="pending-{{ $group['group_id'] }}">
                <p class="flex flex-wrap items-baseline gap-x-2 text-sm"><span class="font-mono font-semibold whitespace-nowrap [word-spacing:-0.25em]">{{ $group['label'] }}</span><span>{{ $group['subject'] }}</span></p>
                @foreach ($group['moves'] as $move)
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 font-mono text-[13px] [word-spacing:-0.25em]">
                        <span @class(['min-w-[4.2rem] font-sans text-[11.5px] font-semibold [word-spacing:normal]', 'text-warning' => $move['tone'] === 'warning', 'text-error' => $move['tone'] === 'error', 'text-info' => $move['tone'] === 'info', 'text-neutral' => $move['tone'] === 'neutral'])>{{ $move['tag'] }}</span>
                        @if ($move['was'])
                            <span class="text-neutral line-through decoration-neutral/60"><span class="sr-only">antes </span>{{ $move['was'] }}</span>
                        @endif
                        @if ($move['was'] && $move['now'])
                            <x-icon name="o-arrow-right" class="h-3.5 w-3.5 shrink-0 text-neutral" aria-hidden="true" />
                        @endif
                        @if ($move['now'])
                            <span class="font-semibold"><span class="sr-only">después </span>{{ $move['now'] }}</span>
                        @endif
                    </div>
                @endforeach
                @if ($group['same'])
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 font-mono text-[13px] [word-spacing:-0.25em]">
                        <span class="min-w-[4.2rem] font-sans text-[11.5px] font-medium text-neutral [word-spacing:normal]">Sin cambio</span><span>{{ $group['same'] }}</span>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</section>
