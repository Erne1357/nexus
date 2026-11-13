{{--
    «N de M revisados» de una revisión (CV7) con su barra; con `$big` (la tarjeta del tablero), la cifra grande y lo que
    queda debajo. Solo cuentan los activos (confirmado el 12 de noviembre de 2026). `$card` es de review.php.
--}}
@php($big ??= false)
@php($reviewed = $card['reviewed'])
@php($left = $reviewed['total'] - $reviewed['reviewed'])
@if ($big)
    <div class="space-y-2" data-testid="reviewed-count-{{ $card['review']->catalog->value }}">
        <p class="flex items-baseline gap-2.5">
            <span class="text-3xl font-semibold leading-none tabular-nums">{{ $reviewed['reviewed'] }} de {{ $reviewed['total'] }}</span>
            <span class="text-neutral">revisados</span>
        </p>
        @include('catalog-validation::review.meter', ['reviewed' => $reviewed, 'class' => 'w-full'])
        <p class="text-sm text-neutral">{{ $left > 0 ? trans_choice('{1} Queda 1 por revisar|[2,*] Quedan :count por revisar', $left) : 'Todo revisado' }}</p>
    </div>
@else
    <div class="flex items-center gap-3" data-testid="reviewed-count-{{ $card['review']->catalog->value }}">
        <p class="text-sm font-medium whitespace-nowrap tabular-nums">{{ $reviewed['reviewed'] }} de {{ $reviewed['total'] }} revisados</p>
        @include('catalog-validation::review.meter', ['reviewed' => $reviewed, 'class' => 'w-28 lg:w-36'])
    </div>
@endif
