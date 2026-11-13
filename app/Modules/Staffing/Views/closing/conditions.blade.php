{{--
    «Para cerrar» en cifras (maqueta B2): una celda por cosa que falta (LoadClosing::conditions), con su cifra en Mono,
    su icono y su tono, el detalle («14 justificados», el nombre del docente dado de baja) y «Ir a …», que lleva a la
    pantalla donde se arregla con el `?campus=` del filtro, si hay. Lo que ya no falta queda atenuado con
    `o-check-circle`. La caja es un contenedor: cuatro columnas desde 44rem, dos desde 26rem y, más angosta, un renglón
    por cifra. `$closing`.
--}}
@php($screens = ['conflicts' => 'Choques y avance', 'editor' => 'Horarios', 'assign' => 'Asignación docente', 'change-requests' => 'Solicitudes de cambio', 'agreements' => 'Acuerdos'])
<div class="@container overflow-hidden rounded-b-box">
    <div class="-ms-px -mt-px grid grid-cols-1 @min-[26rem]:grid-cols-2 @min-[44rem]:grid-cols-4">
        @foreach ($closing->conditions() as $row)
            @php($pending = $row['count'] > 0)
            <div class="grid grid-cols-[5.25rem_minmax(0,1fr)] content-start gap-x-3 gap-y-0.5 border-s border-t border-base-300 px-4 py-3 @min-[26rem]:flex @min-[26rem]:flex-col @min-[26rem]:pt-3.5 @min-[26rem]:pb-4" data-testid="condition-{{ $row['key'] }}">
                <span @class([
                    'row-span-3 flex items-center gap-1.5 self-start font-mono text-[26px] leading-tight tabular-nums',
                    'font-semibold text-error' => $pending && $row['tone'] === 'error',
                    'font-semibold text-warning' => $pending && $row['tone'] === 'warning',
                    'text-success' => ! $pending,
                ])>
                    @if ($pending)
                        <x-icon :name="$row['icon']" class="h-5 w-5 shrink-0" aria-hidden="true" />{{ $row['count'] }}
                    @else
                        <x-icon name="o-check-circle" class="h-5 w-5 shrink-0" aria-hidden="true" /><span class="sr-only">Ninguno</span>
                    @endif
                </span>
                <span @class(['text-sm text-balance', 'font-semibold' => $pending, 'font-medium text-neutral' => ! $pending])>{{ $row['label'] }}</span>
                @if ($row['detail'])
                    <span class="text-[12.5px] text-neutral">{{ $row['detail'] }}</span>
                @endif
                @if ($pending)
                    {{-- La flecha va en línea, tras la última palabra, aunque el texto baje de renglón. --}}
                    <button type="button" wire:click="goTo('{{ $row['screen'] }}')" class="link col-start-2 inline-flex min-h-11 items-center justify-self-start text-start text-sm font-semibold no-underline hover:underline @min-[26rem]:mt-auto @min-[26rem]:min-h-0 @min-[26rem]:pt-2" data-testid="go-{{ $row['key'] }}">
                        <span>Ir a {{ $screens[$row['screen']] }}<x-icon name="o-arrow-right" class="inline-block h-4 w-4 align-[-0.2em]" aria-hidden="true" /></span>
                    </button>
                @endif
            </div>
        @endforeach
    </div>
</div>
