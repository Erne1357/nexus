{{--
    «Choques del semestre»: los duros primero (fondo y borde `error`), luego los avisos (`warning`; HC-12 se lee como
    «faltan horas» aunque el validador lo dé duro). Cada uno con su regla en Mono, el día y las horas de su sesión, su
    mensaje y «Ver»: lleva a la sesión y la marca, en el navegador (en celular, cambia al día); si el choque no es de una
    sesión, elige el grupo del semestre al que pertenece. Desde
    1200 px va debajo de la bandeja con su propio desplazamiento; antes, después de la semana y plegable.
--}}
<section id="choques" class="order-3 flex min-h-0 min-w-0 scroll-mt-24 flex-col rounded-box border border-base-300 bg-base-100 desktop:max-h-[42dvh] desktop:flex-[0_1_auto]"
    x-data="{ open: matchMedia('(min-width: 48rem)').matches, wide: matchMedia('(min-width: 75rem)').matches }"
    x-init="matchMedia('(min-width: 75rem)').addEventListener('change', (event) => wide = event.matches)"
    aria-labelledby="conflicts-title" data-testid="conflicts">
    <h2 id="conflicts-title">
        <button type="button" class="flex w-full flex-wrap items-center gap-2 rounded-box px-3.5 py-3 text-left font-bold focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-default"
            x-on:click="open = ! open" x-bind:aria-expanded="(open || wide) ? 'true' : 'false'" x-bind:disabled="wide" aria-controls="conflicts-body">
            Choques del semestre
            <span class="ms-auto flex flex-wrap items-center gap-1.5 font-normal">
                @if ($hardCount > 0)
                    <x-core::status-badge tone="error" :label="$hardCount.($hardCount === 1 ? ' duro' : ' duros')" />
                @endif
                @if ($softCount > 0)
                    <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="$softCount.($softCount === 1 ? ' aviso' : ' avisos')" />
                @endif
            </span>
            <x-icon name="o-chevron-down" class="h-5 w-5 transition-transform motion-reduce:transition-none desktop:hidden" x-bind:class="open || '-rotate-90'" aria-hidden="true" />
        </button>
    </h2>

    <div id="conflicts-body" x-show="open || wide" class="grid min-h-0 content-start gap-2 overflow-y-auto px-3 pb-3">
        @forelse ($conflicts as $conflict)
            <div @class([
                'grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-2 gap-y-0.5 rounded-field border px-2.5 py-2 text-[13px] leading-snug',
                'border-error/40 bg-error/10' => $conflict['hard'],
                'border-warning/40 bg-warning/10' => ! $conflict['hard'],
            ]) data-conflict="{{ $conflict['rule'] }}">
                <span @class(['flex flex-wrap items-center gap-1 font-mono text-xs font-semibold', 'text-error' => $conflict['hard'], 'text-warning' => ! $conflict['hard']])>
                    <x-icon :name="$conflict['hard'] ? 'o-x-circle' : 'o-exclamation-triangle'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $conflict['rule'] }}
                    @if ($conflict['rule'] === 'HC-12')
                        <span class="font-sans font-medium">· faltan horas</span>
                    @endif
                    @if ($conflict['when'])
                        <span class="font-normal text-neutral">· {{ $conflict['when'] }}</span>
                    @endif
                </span>
                @if ($conflict['session'])
                    <x-button label="Ver" icon-right="o-arrow-right" class="btn-outline btn-xs row-span-2 bg-base-100" x-on:click="found('{{ $conflict['session'] }}')" />
                @else
                    <x-button label="Ver" icon-right="o-arrow-right" class="btn-outline btn-xs row-span-2 bg-base-100" wire:click="pick('{{ $conflict['group'] }}', false)" />
                @endif
                <p class="text-base-content">{{ \App\Modules\Scheduling\Support\WeekGrid::prose($conflict['message']) }}</p>
            </div>
        @empty
            <p class="flex items-center gap-2 px-1 pb-1 text-success"><x-icon name="o-check-circle" class="h-5 w-5" aria-hidden="true" />Sin choques en este semestre</p>
        @endforelse
    </div>
</section>
