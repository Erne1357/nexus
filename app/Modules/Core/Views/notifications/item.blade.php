{{--
    Un renglón de notificación, el mismo en la tarjeta de la campana y en «Todas las notificaciones»: icono con su
    tono, título (en negrita mientras no se lee), detalle, hace cuánto, el puesto al que va el aviso tras la hora si tiene más de un puesto (`$showRole`) y el punto de «sin leer». Abrirlo es
    `open(id)` del componente que lo incluye. Tamaños de la rampa de DESIGN.md: 14 px.
--}}
<button
    type="button"
    wire:click="open('{{ $item['id'] }}')"
    data-testid="notification"
    data-unread="{{ $item['read'] ? 'false' : 'true' }}"
    class="grid min-h-11 w-full text-sm cursor-pointer grid-cols-[36px_minmax(0,1fr)_12px] items-start gap-3 px-4 py-3 text-start hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-3 focus-visible:outline-primary data-[unread=true]:bg-info/7 data-[unread=true]:hover:bg-info/12"
>
    <span @class([
        'grid size-9 place-items-center rounded-lg border',
        'border-info/30 bg-info/12 text-info' => $item['tone'] === 'info',
        'border-warning/30 bg-warning/12 text-warning' => $item['tone'] === 'warning',
        'border-success/30 bg-success/12 text-success' => $item['tone'] === 'success',
        'border-base-300 bg-base-200 text-base-content/70' => ! in_array($item['tone'], ['info', 'warning', 'success'], true),
        'opacity-80' => $item['read'],
    ])>
        <x-icon :name="$item['icon']" class="w-5 h-5" />
    </span>

    <span class="min-w-0">
        @unless ($item['read'])
            <span class="sr-only">Sin leer.</span>
        @endunless
        <span @class(['block leading-[1.3] [overflow-wrap:anywhere]', 'font-bold' => ! $item['read'], 'font-medium' => $item['read']])>
            {{ $item['title'] }}
        </span>
        <span class="mt-0.5 block text-neutral [overflow-wrap:anywhere]">{{ $item['body'] }}</span>
        <span class="mt-1 block text-neutral">
            {{ $item['at']->locale('es')->diffForHumans() }}
            @if (($showRole ?? false) && $item['role'] !== null)
                · <span data-testid="notification-role">{{ $item['role'] }}</span>
            @endif
        </span>
    </span>

    <span aria-hidden="true" @class(['mt-[7px] size-[9px] rounded-full bg-info', 'invisible' => $item['read']])></span>
</button>
