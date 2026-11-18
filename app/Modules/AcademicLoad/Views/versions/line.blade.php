{{--
    La línea de versiones del tablero (DESIGN.md «Versiones publicadas»): la de «Publicación», de la primera a la vigente,
    con el mismo punto (x-academic-load::version-dot) y la misma regla de la caja (de lado desde @2xl, vertical más
    angosta). Cada punto es un botón que elige la versión: la fecha (con la hora si dos salieron el mismo día) y «vigente»,
    el motivo con su icono y lo que cambió contra la anterior («+2 −3 · 7 con cambios»), o sus grupos en la primera. La
    elegida va en gris polvo, con el filete `primary` de 3 px abajo de lado y la fecha subrayada en vertical.
--}}
@php
    $sameDay = collect($versions)->countBy(fn (array $version): string => $day($version['publishedAt']));
@endphp
<section class="@container rounded-box border border-base-300 bg-base-100 p-1.5 md:p-2" aria-labelledby="versions-line-title" data-testid="versions-line">
    <h2 id="versions-line-title" class="sr-only">Versiones de la carga</h2>
    <ol class="flex flex-col @2xl:flex-row @2xl:overflow-x-auto" aria-label="Versiones publicadas">
        @foreach ($versions as $version)
            @php
                $number = $version['version'];
                $isCurrent = $number === $current;
                $isSelected = $number === $selected['version'];
                $summary = $version['summary'];
            @endphp
            <li class="relative @2xl:min-w-48 @2xl:flex-1" wire:key="line-{{ $number }}">
                @unless ($loop->last)
                    <span class="absolute left-[25px] top-[38px] -bottom-2.5 w-0.5 bg-success/40 @2xl:left-10 @2xl:-right-3 @2xl:top-[23px] @2xl:bottom-auto @2xl:h-0.5 @2xl:w-auto" aria-hidden="true"></span>
                @endunless
                <button type="button" wire:click="selectVersion({{ $number }})" aria-pressed="{{ $isSelected ? 'true' : 'false' }}" aria-controls="version-title"
                    @class([
                        'relative grid min-h-11 w-full cursor-pointer grid-cols-[1.75rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0.5 rounded-field px-3 py-2.5 text-start @2xl:grid-cols-1 @2xl:justify-items-start @2xl:gap-y-1',
                        'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary',
                        'bg-secondary/50 @2xl:shadow-[inset_0_-3px_0_0_var(--color-primary)]' => $isSelected,
                        'hover:bg-base-200' => ! $isSelected,
                    ]) data-testid="version-{{ $number }}">
                    <x-academic-load::version-dot :version="$number" :current="$isCurrent" class="row-span-3 self-start @2xl:row-span-1" />
                    <span class="flex flex-wrap items-baseline gap-x-2">
                        <span @class(['font-semibold', '@max-2xl:underline @max-2xl:underline-offset-3' => $isSelected])>{{ $day($version['publishedAt']).($sameDay[$day($version['publishedAt'])] > 1 ? ', '.$clock($version['publishedAt']) : '') }}</span>
                        @if ($isCurrent)
                            <span class="text-xs font-semibold text-success">vigente</span>
                        @endif
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-[13px] text-neutral">
                        <x-icon :name="$version['reason']->icon()" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $version['reason']->label() }}
                    </span>
                    <span class="text-[12.5px] text-neutral">
                        @if ($summary !== null)
                            <span class="font-mono font-semibold text-base-content">+{{ $summary['added'] }} −{{ $summary['cancelled'] }}</span>
                            · <span class="font-mono font-semibold text-base-content">{{ $summary['changed'] }}</span> con cambios
                        @else
                            <span class="font-mono font-semibold text-base-content">{{ number_format($version['groups']) }}</span> grupos
                        @endif
                    </span>
                </button>
            </li>
        @endforeach
    </ol>
</section>
