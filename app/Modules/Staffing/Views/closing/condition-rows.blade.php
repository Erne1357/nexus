{{--
    «Para cerrar» en renglones, para el detalle de «Avance del cierre» (maqueta E): cada cosa que falta con su icono, su
    nombre y detalle, su cifra y «Ir a …»; lo que ya no falta, con `o-check-circle` y un guion. `$closing`.
--}}
@php($screens = ['conflicts' => 'Choques y avance', 'editor' => 'Horarios', 'assign' => 'Asignación docente', 'change-requests' => 'Solicitudes de cambio', 'agreements' => 'Acuerdos'])
<ul class="divide-y divide-base-300" data-testid="condition-rows">
    @foreach ($closing->conditions() as $row)
        @php($pending = $row['count'] > 0)
        <li class="grid grid-cols-[1.5rem_minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 py-3 sm:grid-cols-[1.5rem_minmax(0,1fr)_3rem_auto]" data-testid="condition-row-{{ $row['key'] }}">
            <x-icon :name="$pending ? $row['icon'] : 'o-check-circle'" @class(['mt-0.5 h-5 w-5', 'text-error' => $pending && $row['tone'] === 'error', 'text-warning' => $pending && $row['tone'] === 'warning', 'text-success' => ! $pending]) aria-hidden="true" />
            <span class="min-w-0">
                <span @class(['block', 'font-semibold' => $pending, 'text-neutral' => ! $pending])>{{ $row['label'] }}</span>
                @if ($row['detail'])
                    <span class="block text-[12.5px] text-neutral">{{ $row['detail'] }}</span>
                @endif
            </span>
            <span @class(['text-end font-mono text-lg tabular-nums', 'font-semibold text-error' => $pending && $row['tone'] === 'error', 'font-semibold text-warning' => $pending && $row['tone'] === 'warning', 'text-neutral' => ! $pending])>
                @if ($pending){{ $row['count'] }}@else<span aria-hidden="true">–</span><span class="sr-only">Ninguno</span>@endif
            </span>
            @if ($pending)
                <button type="button" wire:click="goTo('{{ $row['screen'] }}')" class="link col-start-2 inline-flex min-h-11 items-center gap-1 justify-self-start text-sm font-semibold no-underline hover:underline sm:col-start-auto sm:min-h-0 sm:justify-self-end" data-testid="go-{{ $row['key'] }}">
                    Ir a {{ $screens[$row['screen']] }}<x-icon name="o-arrow-right" class="h-4 w-4 shrink-0" aria-hidden="true" />
                </button>
            @else
                <span class="max-sm:hidden"></span>
            @endif
        </li>
    @endforeach
</ul>
