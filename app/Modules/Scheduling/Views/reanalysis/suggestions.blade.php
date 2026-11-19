{{--
    Las sugerencias de una materia en un campus (tarea 8.3a), dentro del drawer `scheduling::components.reanalysis-suggestions`:
    una lista con filete, un renglón por sugerencia (scheduling::reanalysis.suggestion) con su tipo e icono, la evidencia
    en Mono, el efecto y «Aplicar», «Editar» y «Descartar»; y debajo los grupos de ahora de la materia en el campus.
    Quien ve la carga sin ajustarla ve lo mismo con los botones deshabilitados y el motivo una vez, arriba (RA-07).

    `$block` (ReanalysisBlock), `$load`, `$suggestions` (las visibles), `$advice` (ReanalysisSuggestions: grupos, lugares de
    ahora, claves y umbral), `$adjustReason` (por qué no ajusta, o null), `$blocked` (por llave, la sugerencia que la
    jefatura no puede aplicar en ese grupo), `$discardedCount`, `$editing` (la que se edita), `$placements` (por llave, el lugar
    que encontró el motor para «Abrir un grupo», tarea 8.3b), `$roomOptions` (los salones para editarlo) y `$choice` (lo que
    se elige para aplicar junto, solo a quien ajusta; null si no).
--}}
<section class="space-y-2.5" aria-labelledby="suggestions-list-title" data-testid="suggestions-slot">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h3 id="suggestions-list-title" class="text-base font-bold">Sugerencias <span class="font-mono font-normal text-neutral">{{ count($suggestions) }}</span></h3>
        @if ($discardedCount > 0)
            <x-button
                :label="$discardedCount === 1 ? 'Volver a mostrar la descartada' : 'Volver a mostrar las '.$discardedCount.' descartadas'"
                icon="o-arrow-uturn-left"
                class="btn-ghost btn-sm max-md:h-11"
                wire:click="restoreDiscarded"
                data-testid="suggestions-restore"
            />
        @endif
    </div>
    <p class="text-[13px] text-neutral">Contra los lugares de ahora, con el umbral de <span class="font-mono">{{ $advice?->threshold }} %</span> (Parámetros). Aplicar pasa por las reglas del horario: si rompe una regla dura, no se aplica y te dice cuál.</p>

    @if ($adjustReason !== null)
        <p id="suggestions-readonly" class="flex items-start gap-2 rounded-box border border-info/30 bg-info/10 px-3 py-2 text-sm" data-testid="suggestions-readonly">
            <x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
            <span>{{ $adjustReason }}</span>
        </p>
    @endif

    @if ($suggestions === [])
        <div class="flex items-start gap-3 rounded-box border border-base-300 px-4 py-4" data-testid="suggestions-empty">
            <x-icon :name="$block->onlyFirstSemester() ? 'o-user-plus' : 'o-check-circle'" @class(['mt-0.5 h-5 w-5 shrink-0', 'text-neutral' => $block->onlyFirstSemester(), 'text-success' => ! $block->onlyFirstSemester()]) aria-hidden="true" />
            <div class="grid gap-0.5">
                @if ($block->onlyFirstSemester())
                    <p class="font-semibold">Primer semestre: no se clasifica</p>
                    <p class="text-sm text-neutral">El kárdex nuevo no dice nada de los que van a entrar (RA-09). Si hace falta, ajusta los grupos de {{ $block->subjectCode }} en Horarios.</p>
                @elseif ($discardedCount > 0)
                    <p class="font-semibold">Descartaste todas las sugerencias de {{ $block->subjectCode }}</p>
                    <p class="text-sm text-neutral">Vuelven al recargar la página o con «Volver a mostrar».</p>
                @else
                    <p class="font-semibold">Sin sugerencias</p>
                    <p class="text-sm text-neutral">Con los grupos de ahora, cada carrera está dentro del {{ $advice?->threshold }} %, o lo que sobra no se puede cerrar sin dejar a otra carrera faltante.</p>
                @endif
            </div>
        </div>
    @else
        <ul class="grid rounded-box border border-base-300 bg-base-100" data-testid="suggestions-list">
            @foreach ($suggestions as $suggestion)
                @include('scheduling::reanalysis.suggestion', ['suggestion' => $suggestion, 'reason' => $adjustReason ?? ($blocked[$suggestion->key] ?? null), 'placement' => $placements[$suggestion->key] ?? null, 'choice' => $choice])
            @endforeach
        </ul>
    @endif
</section>

@if ($advice !== null && $advice->groups !== [])
    <section class="space-y-2" aria-labelledby="suggestions-groups-title">
        <h3 id="suggestions-groups-title" class="text-base font-bold">Grupos de ahora <span class="font-mono font-normal text-neutral">{{ count($advice->groups) }}</span></h3>
        <ul class="grid rounded-box border border-base-300 bg-base-100">
            @foreach ($advice->groups as $group)
                <li class="grid gap-1 border-t border-base-300 px-4 py-2.5 first:border-t-0" data-testid="suggestions-group" data-group="{{ $group->code }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <span class="font-mono text-[15px] font-semibold">{{ $block->subjectCode }} · {{ $group->code }}</span>
                        <span class="flex flex-wrap items-center gap-1.5">
                            @foreach ($group->quotas as $careerId => $seats)
                                <span class="inline-flex items-center gap-1 rounded-[4px] border border-base-300 bg-base-200 px-1.5 py-0.5 text-[13px]"><span class="font-mono text-[12.5px] font-semibold">{{ $advice->careerCodes[$careerId] ?? '—' }}</span> <span class="font-mono tabular-nums">{{ $seats }}</span></span>
                            @endforeach
                        </span>
                        <span class="text-[12.5px] text-neutral"><span class="font-mono tabular-nums text-base-content">{{ $group->used() }}</span> de <span class="font-mono tabular-nums text-base-content">{{ $group->capacity }}</span> lugares{{ $group->held > 0 ? ' · '.$group->held.' apartados' : '' }}</span>
                    </div>
                    <p class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[12.5px] text-neutral">
                        <x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />
                        <span>{{ $group->teacherName ?? 'Sin docente' }}</span>
                        @foreach ($group->sessions as $session)
                            <span aria-hidden="true">·</span>
                            <span class="inline-flex items-center gap-1"><span class="font-mono tabular-nums">{{ $session->label() }}</span>
                                @if ($session->room)
                                    <x-icon name="o-map-pin" class="h-3.5 w-3.5" aria-hidden="true" /><span class="font-mono">{{ $session->room->code }}</span><span class="font-mono tabular-nums">({{ $session->room->capacity }})</span>
                                @else
                                    <span>sin salón</span>
                                @endif
                            </span>
                        @endforeach
                    </p>
                </li>
            @endforeach
        </ul>
    </section>
@endif
