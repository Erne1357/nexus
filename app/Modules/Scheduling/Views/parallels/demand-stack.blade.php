{{--
    Lo que piden, de consulta, debajo de 1024 px (maqueta B, «armar» a 390 px): por carrera que cuenta, su clave y el estado
    de su parte, sus esperados y lo que pidió, y en qué grupos quedan («1 completo · 10 lugares en Mixto 2»), con los
    esperados sin lugar o de más; luego las regresadas, que no cuentan.
--}}
<ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300 lg:hidden" data-testid="parallels-demand-stack">
    @foreach ($row->careers as $career)
        @php
            $id = $career->career->getKey();
            $places = [];

            if ($draft->full[$id] > 0) {
                $places[] = $draft->full[$id] === 1 ? '1 completo' : $draft->full[$id].' completos';
            }

            foreach ($draft->mixed as $column => $shares) {
                if (($shares[$id] ?? 0) > 0) {
                    $places[] = $shares[$id].' '.($shares[$id] === 1 ? 'lugar' : 'lugares').' en Mixto '.($column + 1);
                }
            }

            $left = $draft->left[$id];
        @endphp
        <li class="grid gap-1 px-4 py-3" wire:key="stack-{{ $id }}">
            <span class="flex flex-wrap items-center gap-2">
                @include('scheduling::parallels.career-chip', ['code' => $career->career->code, 'name' => $career->career->name, 'accepted' => $career->isAccepted()])
                @if ($career->isAccepted())
                    <x-core::status-badge tone="success" label="Aceptada" />
                @else
                    <x-core::status-badge tone="warning" label="Pendiente" />
                @endif
            </span>
            <span class="text-sm"><b class="font-mono font-semibold">{{ $career->expected }}</b> esperados · pidió <b class="font-mono font-semibold">{{ $career->requested }}</b></span>
            <span class="text-sm text-neutral">Quedan en: {{ $places === [] ? 'ningún grupo' : implode(' · ', $places) }}</span>
            @if ($left !== 0)
                <span class="flex items-center gap-1.5 text-sm text-warning"><x-icon name="o-exclamation-triangle" class="h-4 w-4" aria-hidden="true" /><span class="text-base-content">{{ abs($left) }} {{ $left > 0 ? 'sin lugar' : 'de más' }}</span></span>
            @endif
        </li>
    @endforeach
    @foreach ($row->excluded as $career)
        <li class="grid gap-1 px-4 py-3 text-neutral" wire:key="stack-out-{{ $career->career->getKey() }}">
            <span class="flex flex-wrap items-center gap-2">
                @include('scheduling::parallels.career-chip', ['code' => $career->career->code, 'name' => $career->career->name, 'accepted' => false])
                <x-core::status-badge tone="error" icon="o-arrow-uturn-left" label="Regresada" />
            </span>
            <span class="text-sm">No cuenta hasta que la reenvíen.</span>
        </li>
    @endforeach
</ul>
