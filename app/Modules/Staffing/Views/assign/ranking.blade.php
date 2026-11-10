{{--
    El ranking completo de un grupo, el mismo en las dos vistas: arriba la leyenda del orden; luego quienes pueden
    tomarlo (staffing::assign.ranking-row) y, plegado en un renglón, el bloque final (reglas-de-negocio.md, «Un solo
    bloque final»): «N docentes no pueden tomarlo (M sin horas, K choque, J no disponible)» con «Ver», que lo abre
    debajo (toggleFinalTier). Con la caja de 46rem o más es una tabla de nueve columnas; debajo, cada docente ocupa dos
    renglones. `capped` (en la vista por materia): desde 768 px la tabla mide a lo más 70vh, con su propio
    desplazamiento y el encabezado de columnas pegado arriba.
--}}
@php($grid = '@[46rem]:grid-cols-[1.75rem_minmax(8.5rem,24rem)_2.75rem_2.75rem_6rem_3rem_3.75rem_5.25rem_minmax(6.25rem,1fr)]')
@php($capped ??= false)
@php($eligible = $ranking->eligible())
@php($flagged = $ranking->flagged())
@php($finalOpen = in_array($group->id, $finalTierOpen, true))
@php($recommendedId = $eligible[0]->teacherId ?? null)
@php($size = count($ranking->rows))
<div class="@container" data-testid="ranking">
    @if ($ranking->rows === [])
        <p class="px-4 py-6 text-center text-sm text-neutral">Ningún docente entró al ranking de este grupo.</p>
    @else
        @include('staffing::assign.order-legend', ['class' => 'border-b border-base-300 px-4 py-2'])
        <div @class(['relative', 'md:max-h-[70vh] md:overflow-y-auto md:overscroll-contain' => $capped]) data-testid="ranking-scroll">
            <div role="table" aria-label="Ranking de {{ $group->code }} · {{ $group->subject?->name }}">
                <div role="rowgroup" @class(['bg-base-100', 'md:sticky md:top-0 md:z-10' => $capped])>
                    <div role="row" @class(['hidden items-center gap-x-2 border-b border-base-300 bg-secondary/40 px-4 py-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted @[46rem]:grid', $grid])>
                        <span role="columnheader">#</span>
                        <span role="columnheader">Docente</span>
                        <span role="columnheader" class="text-right">Base</span>
                        <span role="columnheader" class="text-right">Veces</span>
                        <span role="columnheader">Perfil</span>
                        <span role="columnheader" class="text-right">Eval.</span>
                        <span role="columnheader" class="text-right">Puntaje</span>
                        <span role="columnheader" class="text-right">Quedan</span>
                        <span role="columnheader"><span class="sr-only">Asignar</span></span>
                    </div>
                </div>
                <div role="rowgroup" class="divide-y divide-base-300">
                    @foreach ($eligible as $candidate)
                        @include('staffing::assign.ranking-row')
                    @endforeach

                    @if ($flagged !== [])
                        <div role="row" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 bg-base-200 px-4 py-2" data-testid="flagged-tier">
                            <p role="cell" class="flex min-w-0 items-start gap-2 text-sm text-neutral">
                                <x-icon name="o-no-symbol" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                <span><span class="font-mono font-semibold tabular-nums text-base-content">{{ count($flagged) }}</span> {{ count($flagged) === 1 ? 'docente no puede tomarlo' : 'docentes no pueden tomarlo' }} ({{ $ranking->flaggedSummary() }})</span>
                            </p>
                            <span role="cell">
                                <button type="button" wire:click="toggleFinalTier('{{ $group->id }}')" aria-expanded="{{ $finalOpen ? 'true' : 'false' }}"
                                    aria-label="{{ $finalOpen ? 'Ocultar' : 'Ver' }} los docentes que no pueden tomar {{ $group->code }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="toggle-final-tier">
                                    <x-icon :name="$finalOpen ? 'o-chevron-up' : 'o-chevron-down'" class="h-4 w-4" />{{ $finalOpen ? 'Ocultar' : 'Ver' }}
                                </button>
                            </span>
                        </div>

                        @if ($finalOpen)
                            @foreach ($flagged as $candidate)
                                @include('staffing::assign.ranking-row')
                            @endforeach
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
