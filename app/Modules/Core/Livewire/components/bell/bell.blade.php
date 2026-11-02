{{--
    La campana (opción A): botón de la banda con el globo de no leídas y la tarjeta flotante debajo, con
    la misma forma y el mismo movimiento que la de la credencial (encabezado con la banda y su franja roja).
    Se oculta mientras el menú completo está abierto, igual que «Buscar o ir a…». Esc o un clic fuera la
    cierran; abrir la credencial también.
--}}
<div
    wire:poll.60s.visible
    x-data="{ bellOpen: false }"
    x-init="$watch('cardOpen', (open) => { if (open) bellOpen = false })"
    x-on:click.outside="bellOpen = false"
    x-on:keydown.escape.window="if (bellOpen) { bellOpen = false; $refs.bell.focus() }"
    class="flex-none transition-[opacity,visibility] duration-200 motion-reduce:transition-none in-data-[menu=open]:pointer-events-none in-data-[menu=open]:invisible in-data-[menu=open]:opacity-0"
>
    <button
        type="button"
        x-ref="bell"
        id="nexus-bell"
        data-testid="bell"
        aria-haspopup="dialog"
        aria-controls="nexus-notifications"
        aria-expanded="false"
        aria-label="{{ $label }}"
        x-bind:aria-expanded="bellOpen ? 'true' : 'false'"
        x-on:click="bellOpen = ! bellOpen; if (bellOpen) { closeCard(false); $nextTick(() => $refs.panel.focus({ preventScroll: true })) }"
        class="relative grid size-11 cursor-pointer place-items-center rounded-lg border border-band-content/20 hover:bg-band-content/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-band-content aria-expanded:bg-band-content/10"
    >
        <x-icon name="o-bell" class="w-[22px] h-[22px]" />

        @if ($unread > 0)
            <span aria-hidden="true" data-testid="bell-count" class="absolute -end-2 -top-2 h-[21px] min-w-[21px] rounded-full bg-band-content px-[5px] text-center font-mono text-xs/[21px] font-bold text-band shadow-[0_0_0_2px_var(--color-band)]">{{ $badge }}</span>
        @endif
    </button>

    <div
        id="nexus-notifications"
        x-ref="panel"
        role="dialog"
        aria-labelledby="nexus-notifications-title"
        tabindex="-1"
        data-open="false"
        x-bind:data-open="bellOpen ? 'true' : 'false'"
        class="pointer-events-none invisible fixed end-5 top-[74px] z-[8] flex max-h-[min(700px,calc(100vh_-_90px))] w-[400px] origin-top-right -translate-y-1.5 scale-[.94] flex-col overflow-hidden rounded-[10px] bg-base-100 text-base-content opacity-0 shadow-float outline-none transition-[opacity,scale,translate,visibility] duration-300 ease-settle motion-reduce:transition-none data-[open=true]:pointer-events-auto data-[open=true]:visible data-[open=true]:translate-y-0 data-[open=true]:scale-100 data-[open=true]:opacity-100 data-[open=true]:transition-[opacity,scale,translate] data-[open=true]:duration-[400ms] data-[open=true]:ease-open max-md:inset-x-2 max-md:top-[66px] max-md:w-auto max-md:max-h-[calc(100vh_-_82px)]"
    >
        <div class="flex flex-none items-center justify-between gap-3 bg-band px-4 py-3.5 text-band-content">
            <h2 id="nexus-notifications-title" class="text-[17px] font-bold">Notificaciones</h2>

            @if ($unread > 0)
                <span class="rounded-full border border-band-content/30 px-[9px] py-0.5 font-mono text-[13px] whitespace-nowrap">{{ $badge }} sin leer</span>
            @endif
        </div>

        <div aria-hidden="true" class="h-[3px] flex-none bg-accent"></div>

        @if ($items->isEmpty())
            <div class="grid justify-items-center gap-2.5 px-4 py-10 text-center text-base-content/70">
                <span class="grid size-[52px] place-items-center rounded-full border border-base-300 bg-base-200">
                    <x-icon name="o-bell" class="w-6 h-6" />
                </span>
                <b class="text-[15px] font-semibold text-base-content">Sin notificaciones</b>
            </div>
        @else
            <div class="flex flex-none justify-end border-b border-base-300 px-2 py-1.5">
                <button
                    type="button"
                    wire:click="markAllRead"
                    data-testid="mark-all-read"
                    @disabled($unread === 0)
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-[13.5px] font-medium whitespace-nowrap hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:text-base-content/45 disabled:hover:bg-transparent"
                >
                    <x-icon name="o-check" class="w-4 h-4" />
                    Marcar todas como leídas
                </button>
            </div>

            <ul role="list" class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                @foreach ($items as $item)
                    <li wire:key="notification-{{ $item['id'] }}" class="border-b border-base-300 last:border-b-0">
                        <button
                            type="button"
                            wire:click="open('{{ $item['id'] }}')"
                            data-testid="notification"
                            data-unread="{{ $item['read'] ? 'false' : 'true' }}"
                            class="grid w-full cursor-pointer grid-cols-[36px_minmax(0,1fr)_12px] items-start gap-3 px-4 py-3 text-start hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-3 focus-visible:outline-primary data-[unread=true]:bg-info/7 data-[unread=true]:hover:bg-info/12"
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
                                <span @class(['block leading-[1.3] [overflow-wrap:anywhere]', 'font-bold' => ! $item['read'], 'font-medium' => $item['read']])>{{ $item['title'] }}</span>
                                <span class="mt-0.5 block text-[13.5px]/[1.4] text-base-content/70 [overflow-wrap:anywhere]">{{ $item['body'] }}</span>
                                <span class="mt-1 block text-[12.5px] text-base-content/70">{{ $item['at']->locale('es')->diffForHumans() }}</span>
                            </span>

                            <span aria-hidden="true" @class(['mt-[7px] size-[9px] rounded-full bg-info', 'invisible' => $item['read']])></span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
