{{--
    Un renglón de la gráfica (variante B), con columnas por el ancho de su caja (`@container`): debajo de 48rem, el
    nombre y las cifras arriba y la regla a todo el ancho; desde 48rem, cuatro columnas con la clave sobre el nombre;
    desde 64rem, la clave junto al nombre.

    - Con `$career` (CareerAccuracy de ForecastReport): clave y nombre, su regla y su error con el de R1 (y, sin filtro de
      campus, el de cada campus). Con `$open`, es un botón que abre sus materias; corriendo, solo se muestra.
    - Sin carrera, el renglón de lo que falta mientras corre: `$pending` («Faltan 3 carreras») con el avance indeterminado.
--}}
@php
    $grid = "grid grid-cols-[minmax(0,1fr)_auto] [grid-template-areas:'name_value'_'rule_rule'] gap-x-3 px-3.5 @3xl:grid-cols-[minmax(10rem,13rem)_minmax(0,1fr)_8rem_16px] @3xl:[grid-template-areas:none] @3xl:gap-x-4 @3xl:px-4 @5xl:grid-cols-[minmax(12rem,17rem)_minmax(0,1fr)_9.5rem_18px] @5xl:gap-x-[22px]";
    $career ??= null;
    $open ??= false;
    $metrics = $career['metrics'] ?? null;
    $code = $career['career']->code ?? null;
    $name = $career['career']->name ?? null;
    $label = $metrics === null ? null : ($metrics->weightedError === null
        ? "{$code} {$name}: sin medir"
        : "{$code} {$name}: error ".number_format($metrics->weightedError * 100, 1).' %, con R1 '.number_format(($metrics->baselineError ?? 0) * 100, 1).' %, '.$metrics->confidence->label());
    $tag = $open ? 'button' : 'div';
@endphp
<li class="border-t border-base-300" @if ($career) wire:key="career-{{ $career['career']->id }}" @endif>
    <{{ $tag }} @if ($open) type="button" wire:click="openCareer('{{ $career['career']->id }}')" aria-label="{{ $label }}" @endif
        class="{{ $grid }} min-h-[54px] w-full items-center pt-1.5 pb-2.5 text-start @3xl:py-0 {{ $open ? 'cursor-pointer hover:bg-base-200/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary' : '' }}"
        @if ($career) data-testid="career-{{ $code }}" @else data-testid="careers-pending" @endif>
        <span class="flex min-w-0 items-baseline gap-2 py-1 [grid-area:name] @3xl:flex-col @3xl:gap-0 @3xl:py-2 @3xl:[grid-area:auto] @5xl:flex-row @5xl:gap-2.5">
            @if ($career)
                <span class="w-auto shrink-0 font-mono text-sm font-semibold @5xl:w-[3.4rem]">{{ $code }}</span>
                <span class="min-w-0 leading-snug @3xl:text-[13px] @3xl:text-neutral @5xl:text-sm @5xl:text-base-content">{{ $name }}</span>
            @else
                <span class="min-w-0 text-sm leading-snug text-neutral">{{ $pending }}</span>
            @endif
        </span>
        <span class="min-h-[30px] [grid-area:rule] @3xl:h-full @3xl:[grid-area:auto]">
            @if ($career)
                <x-academic-load::forecast-rule :metrics="$metrics" class="min-h-[30px] @3xl:min-h-11" />
            @else
                <span class="flex h-full min-h-[30px] items-center gap-3 @3xl:min-h-11">
                    <progress class="progress progress-info h-2 w-56 max-w-[60%]" aria-label="Calculando las carreras que faltan"></progress>
                    <span class="text-[12.5px] text-neutral">Calculando…</span>
                </span>
            @endif
        </span>
        <span class="flex items-baseline justify-end gap-2 py-1 [grid-area:value] @3xl:grid @3xl:justify-items-end @3xl:gap-px @3xl:py-1.5 @3xl:[grid-area:auto]">
            @if (! $career)
                <span class="text-neutral">—</span>
            @elseif ($metrics->weightedError === null)
                <span class="text-sm text-neutral">Sin medir</span>
            @else
                <x-academic-load::percent :value="$metrics->weightedError" class="text-[15px] font-semibold" />
                <span class="text-[12.5px] text-neutral">R1 <x-academic-load::percent :value="$metrics->baselineError" /></span>
                @if (count($career['byCampus']) > 1)
                    <span class="hidden items-center gap-1 whitespace-nowrap text-[11.5px] @3xl:flex" aria-hidden="true">
                        @foreach ($career['byCampus'] as $inCampus)
                            <x-core::campus-tag :campus="$inCampus['campus']" size="xs" decorative class="ms-1" />
                            <x-academic-load::percent :value="$inCampus['metrics']->weightedError" />
                        @endforeach
                    </span>
                @endif
            @endif
        </span>
        <span class="hidden @3xl:block">
            @if ($open)
                <x-icon name="o-chevron-right" class="h-4 w-4 text-neutral" aria-hidden="true" />
            @endif
        </span>
    </{{ $tag }}>
</li>
