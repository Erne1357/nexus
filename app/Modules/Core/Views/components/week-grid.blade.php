{{--
    Semana de un docente (o de cualquier horario): una cuadrícula días x horas en pantallas anchas y, en celular, día
    por día (lista de bloques). `days` es la forma de MyLoadQuery: por número de día `{name, short, hours, blocks}`;
    cada bloque trae `start` y `end` (minutos desde medianoche), `time`, `subject_code`, `group_code`, `subject_name`
    y `room`. Muestra de 7:00 a 20:00 y se amplía si un bloque cae fuera. Solo lectura. Las posiciones de los bloques
    son valores calculados (top y height en rem), no estilo a mano.
--}}
@props(['days', 'label' => 'Semana'])

@php
    $row = 3.375;
    $all = collect($days)->flatMap(fn ($day) => $day['blocks']);
    $from = min(7, $all->isEmpty() ? 7 : (int) floor($all->min('start') / 60));
    $to = max(21, $all->isEmpty() ? 21 : (int) ceil($all->max('end') / 60));
    $hours = range($from, $to - 1);
@endphp

<div {{ $attributes->class(['overflow-hidden rounded-box border border-base-300']) }} data-testid="week-grid" aria-label="{{ $label }}">
    <div class="hidden md:grid" style="grid-template-columns: 3.5rem repeat({{ count($days) }}, minmax(0, 1fr))">
        <div class="border-b border-base-300 bg-base-200"></div>
        @foreach ($days as $day)
            <div class="border-b border-s border-base-300 bg-base-200 px-2.5 py-2 text-sm"><span class="font-bold">{{ $day['name'] }}</span> <span class="text-neutral">{{ $day['hours'] }} h</span></div>
        @endforeach

        <div class="bg-base-200">
            @foreach ($hours as $hour)
                <div class="border-b border-base-300 px-1.5 pt-1 font-mono text-xs text-neutral tabular-nums last:border-b-0" style="height: {{ $row }}rem">{{ $hour }}:00</div>
            @endforeach
        </div>
        @foreach ($days as $day)
            <div class="relative border-s border-base-300">
                @foreach ($hours as $hour)
                    <div class="border-b border-base-300 last:border-b-0" style="height: {{ $row }}rem"></div>
                @endforeach
                @foreach ($day['blocks'] as $block)
                    <div class="absolute inset-x-1 overflow-hidden rounded-field border border-info/30 bg-info/10 px-2 py-1 text-xs leading-snug"
                        style="top: {{ ($block['start'] / 60 - $from) * $row }}rem; height: calc({{ ($block['end'] - $block['start']) / 60 * $row }}rem - 0.25rem)"
                        title="{{ $block['subject_name'] }} · {{ $block['time'] }}" data-testid="week-block">
                        <div class="flex items-center justify-between gap-1 font-mono font-semibold"><span class="truncate">{{ $block['subject_code'] }} · {{ $block['group_code'] }}</span>@if ($block['room'])<span class="shrink-0"><x-icon name="o-map-pin" class="inline w-3 h-3" /> {{ $block['room'] }}</span>@endif</div>
                        <div class="truncate">{{ $block['subject_name'] }}</div>
                        @if (($block['end'] - $block['start']) >= 90)<div class="font-mono text-neutral">{{ $block['time'] }}</div>@endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="divide-y divide-base-300 md:hidden">
        @foreach ($days as $day)
            @continue ($day['blocks'] === [])
            <div class="px-3 py-2.5">
                <h4 class="mb-1.5 text-sm"><span class="font-bold">{{ $day['name'] }}</span> <span class="text-neutral">{{ $day['hours'] }} h</span></h4>
                <ul class="space-y-2">
                    @foreach ($day['blocks'] as $block)
                        <li class="grid grid-cols-[auto_1fr_auto] gap-x-3 text-sm">
                            <span class="font-mono tabular-nums">{{ $block['time'] }}</span>
                            <span class="font-mono font-semibold">{{ $block['subject_code'] }} {{ $block['group_code'] }}</span>
                            <span class="font-mono font-semibold">{{ $block['room'] }}</span>
                            <span></span>
                            <span class="col-span-2 truncate text-neutral">{{ $block['subject_name'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</div>
