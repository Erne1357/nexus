{{--
    Aviso de tronco común (SV-20, CommonCoreWarningQuery) y orden sugerido (SV-21, RunOrderQuery): otra carga que comparte
    alumnos con esta todavía no tiene horario en algunos grupos. No impide nada. `compact` es el de arriba de la página;
    sin él (el drawer de lanzar) lleva qué pasa con la vigencia y el orden sugerido.
--}}
@if ($commonCore !== [])
    @php
        $first = $commonCore[0];
        $others = array_slice($commonCore, 1);
        $current = array_search(true, array_column($order, 'current'), true);
    @endphp
    <div role="note" class="alert alert-warning alert-soft items-start" data-testid="trunk-warning">
        <x-icon name="o-exclamation-triangle" class="h-5 w-5 shrink-0" aria-hidden="true" />
        <div class="grid min-w-0 gap-1 text-base-content">
            <b>{{ $first['name'] }} todavía no tiene horario en <span class="font-mono">{{ $first['without'] }}</span> {{ $first['without'] === 1 ? 'grupo que comparte' : 'grupos que comparten' }} alumnos con {{ $scopeKind === 'department' ? 'esta carga' : 'tu carrera' }}.</b>
            @if ($others !== [])
                <span>También {{ collect($others)->map(fn (array $row): string => "{$row['name']} ({$row['without']})")->join(', ', ' y ') }}.</span>
            @endif
            @if ($compact)
                <span>Conviene correr primero esa carga.</span>
            @else
                <span>Sus sesiones de hoy entran fijas; si después cambian, la corrida queda vencida y hay que volver a correrla.</span>
                @if (count($order) > 1)
                    <span class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm">
                        <span class="text-neutral">Orden sugerido:</span>
                        @foreach (array_slice($order, 0, $current === false ? 3 : max(3, $current + 1)) as $index => $load)
                            @if ($index > 0)
                                <x-icon name="o-arrow-right" class="h-3.5 w-3.5 text-neutral" aria-hidden="true" />
                            @endif
                            <span @class(['font-semibold' => $load['current']])><span class="font-mono">{{ $index + 1 }}</span> {{ $load['name'] }}</span>
                        @endforeach
                    </span>
                @endif
            @endif
        </div>
    </div>
@endif
