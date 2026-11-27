{{--
    Los avisos agrupados por día (Hoy, Ayer, Esta semana, Antes), el mismo en la tarjeta de la campana y en
    «Todas las notificaciones». Llegan de la más nueva a la más vieja, así que cada grupo es continuo. Recibe
    `$rows` (la lista de renglones de NotificationQuery) y `$showRole` (el puesto tras la hora, solo si tiene más de
    uno). El campus del aviso no se muestra: la pantalla del aviso se abre en él (#415). Cada grupo lleva un encabezado de 11.5 px en mayúsculas, como los de tabla.
--}}
@foreach (collect($rows)->groupBy('group') as $group => $groupRows)
    <li wire:key="notification-group-{{ $group }}" class="border-b border-base-300 last:border-b-0" data-testid="group-{{ $group }}">
        <h3 class="border-b border-base-300 bg-base-200 px-4 py-1.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $groupRows->first()['group_label'] }}</h3>

        <ul role="list">
            @foreach ($groupRows as $item)
                <li wire:key="notification-{{ $item['id'] }}" class="border-b border-base-300 last:border-b-0">
                    @include('core::notifications.item', ['item' => $item, 'showRole' => $showRole ?? false])
                </li>
            @endforeach
        </ul>
    </li>
@endforeach
