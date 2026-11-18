{{--
    Los grupos que resultan del armado (maqueta B, «Grupos que resultan»). Los completos de una carrera en mosaicos: su
    carrera, el grupo que se queda (con sus horas colocadas) o «nuevo» y su capacidad. Los mixtos (y los restos de una
    sola carrera) en renglones: su nombre y qué grupo es; la barra de su capacidad con un tramo por carrera y sus lugares,
    lo libre en rayas y, si los cupos pasan la capacidad, la barra en `error` con «Pasa la capacidad por N» y HC-07 (color,
    icono y texto); «Poco lleno» si sus esperados no llegan a la mitad de la capacidad (de la maqueta C; supuesto por
    confirmar). Al final, si algún grupo pasa su capacidad o ninguno. Los cupos de un mixto son los esperados de cada
    carrera en exclusiva (confirmado el 18 de noviembre de 2026); el de un grupo de una sola carrera, toda su capacidad.
--}}
@php
    $fullIndexes = array_keys(array_filter($draft->slots, fn ($slot): bool => $slot->full));
    $restIndexes = array_keys(array_filter($draft->slots, fn ($slot): bool => ! $slot->full));
    $stripes = 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_14%,var(--color-base-100))_0_4px,var(--color-base-100)_4px_8px)]';
@endphp
<section aria-labelledby="parallels-results-title" class="space-y-4" data-testid="parallels-results">
    <div>
        <h3 id="parallels-results-title" class="text-base font-semibold">Grupos que resultan · {{ $draft->size() }}</h3>
        <p class="text-sm text-neutral">En un mixto, cada carrera recibe sus esperados en exclusiva y lo demás queda libre; un grupo de una sola carrera lleva toda su capacidad.</p>
    </div>

    <div class="space-y-2">
        <h4 class="{{ $label }}">Completos de una carrera · {{ count($fullIndexes) }}</h4>
        @if ($fullIndexes === [])
            <p class="text-sm text-neutral">Ninguno: cada carrera pide menos de un grupo lleno.</p>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach ($fullIndexes as $index)
                    @php
                        $slot = $draft->slots[$index];
                    @endphp
                    <li @class(['inline-flex items-center gap-1.5 rounded-field border px-2.5 py-1 text-sm', 'border-info/40 bg-info/10' => $slot->keeps, 'border-base-300 bg-base-100' => ! $slot->keeps]) wire:key="tile-{{ $index }}"
                        title="{{ $slot->keeps ? 'Ya existe: grupo '.$slot->keeps->code.' de '.$draft->code($slot->ownerCareerId) : 'Nace nuevo, sin horario' }}" data-testid="parallels-slot-{{ $index }}">
                        <span class="font-mono font-semibold">{{ $draft->code($slot->ownerCareerId) }}</span>
                        @if ($slot->keeps)
                            <span class="font-mono font-semibold">{{ $slot->keeps->code }}</span>
                            @if ($slot->keeps->minutes > 0)
                                <span class="text-neutral">· {{ $duration($slot->keeps->minutes) }}</span>
                            @endif
                        @else
                            <span class="text-neutral">nuevo</span>
                        @endif
                        <span class="text-neutral">· {{ $slot->capacity }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="space-y-2">
        <h4 class="{{ $label }}">Mixtos y restos · {{ count($restIndexes) }}</h4>
        @if ($restIndexes === [])
            <p class="text-sm text-neutral">Ninguno: todos los esperados van en completos.</p>
        @endif
        <div class="divide-y divide-base-300">
            @foreach ($restIndexes as $index)
                @php
                    $slot = $draft->slots[$index];
                    $quotaSum = array_sum($slot->quotas);
                    $expectedSum = array_sum($slot->expected);
                    $base = max($slot->capacity, $quotaSum, 1);
                    $over = $slot->over();
                    $lowFill = $expectedSum * 2 < $slot->capacity;
                @endphp
                <div class="grid gap-x-6 gap-y-2 py-3 first:pt-1 md:grid-cols-[minmax(10rem,14rem)_minmax(0,1fr)]" wire:key="rest-{{ $index }}" data-testid="parallels-slot-{{ $index }}">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $draft->slotName($index) }}</p>
                        <p class="text-[12.5px] text-neutral">
                            @if ($slot->keeps)
                                Es el grupo <b class="font-mono text-base-content">{{ $slot->keeps->code }}</b> de {{ $draft->code($slot->keeps->ownerCareerId) }}{{ $slot->keeps->minutes > 0 ? ' · '.$duration($slot->keeps->minutes).' colocadas' : ' · sin horario' }}{{ $slot->keeps->hasTeacher() ? ' · con docente' : '' }}{{ $slot->keeps->vetoed !== null ? ' · con una solicitud de cambio abierta' : '' }}
                            @else
                                Nuevo, sin horario
                            @endif
                        </p>
                    </div>
                    <div class="min-w-0 space-y-1.5">
                        <div @class(['flex h-6 overflow-hidden rounded-field border bg-base-100 text-[11.5px] font-mono font-semibold', 'border-error' => $over > 0, 'border-base-300' => $over === 0]) role="img"
                            aria-label="{{ collect($slot->quotas)->map(fn ($seats, $career) => $draft->code($career).' '.$seats)->join(', ') }} de {{ $slot->capacity }} lugares{{ $over > 0 ? ', pasa la capacidad por '.$over : '' }}">
                            @foreach ($slot->quotas as $career => $seats)
                                <span @class(['flex min-w-0 items-center justify-center truncate border-e border-base-100 px-1', 'bg-error/20 text-error' => $over > 0, 'bg-info/20 text-base-content' => $over === 0]) style="width: {{ $seats / $base * 100 }}%">{{ $seats / $base > 0.14 ? $draft->code($career).' '.$seats : '' }}</span>
                            @endforeach
                            @if ($slot->free() > 0)
                                <span class="block {{ $stripes }}" style="width: {{ $slot->free() / $base * 100 }}%"></span>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-neutral">
                            <span><b class="font-mono text-base-content">{{ $quotaSum }}</b> de {{ $slot->capacity }} lugares</span>
                            <span><b class="font-mono text-base-content">{{ $expectedSum }}</b> esperados</span>
                            @if ($over > 0)
                                <span class="inline-flex items-center gap-1 font-semibold text-error"><x-icon name="o-x-circle" class="h-3.5 w-3.5" aria-hidden="true" />Pasa la capacidad por {{ $over }} <span class="rounded-[4px] border border-error/60 px-1 font-mono text-[11px] leading-4">HC-07</span></span>
                            @elseif ($slot->free() > 0)
                                <span>{{ $slot->free() }} {{ $slot->free() === 1 ? 'libre' : 'libres' }}</span>
                            @endif
                            @if ($lowFill)
                                <x-core::status-badge tone="warning" icon="o-arrow-trending-down" label="Poco lleno" data-testid="parallels-low-{{ $index }}" />
                            @endif
                        </div>
                        <p class="flex flex-wrap gap-1.5 text-[12.5px]">
                            @foreach ($slot->expected as $career => $count)
                                <span class="rounded-field border border-base-300 px-2 py-0.5"><span class="font-mono font-semibold">{{ $draft->code($career) }}</span> <span class="font-mono">{{ $slot->quotas[$career] ?? $count }}</span> <span class="text-neutral">· {{ $count }} esp.</span></span>
                            @endforeach
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if ($draft->overs() !== [])
        <p class="flex items-start gap-2 text-sm text-error" data-testid="parallels-hc07"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{{ collect($draft->overs())->map(fn ($index) => $draft->slotName($index).' pasa su capacidad por '.$draft->slots[$index]->over())->join('; ') }} (HC-07). Quita esperados en «Reparto de los esperados» para armar.</span></p>
    @elseif ($draft->slots !== [])
        <p class="flex items-start gap-2 text-sm text-neutral"><x-icon name="o-check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-success" aria-hidden="true" /><span>Ningún grupo pasa su capacidad (HC-07): los cupos de cada grupo caben en sus lugares.</span></p>
    @endif
</section>
