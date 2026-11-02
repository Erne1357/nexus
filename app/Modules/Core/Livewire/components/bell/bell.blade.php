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
            <span aria-hidden="true" data-testid="bell-count" class="absolute -end-2 -top-2 h-[21px] min-w-[21px] rounded-full bg-band-content px-[5px] text-center font-mono text-[11.5px]/[21px] font-bold text-band shadow-[0_0_0_2px_var(--color-band)]">{{ $badge }}</span>
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
        class="pointer-events-none invisible fixed end-5 top-[74px] z-[8] flex max-h-[min(700px,calc(100dvh_-_90px))] w-[400px] origin-top-right -translate-y-1.5 scale-[.94] flex-col overflow-hidden rounded-[10px] bg-base-100 text-base-content opacity-0 shadow-float outline-none transition-[opacity,scale,translate,visibility] duration-300 ease-settle motion-reduce:transition-none data-[open=true]:pointer-events-auto data-[open=true]:visible data-[open=true]:translate-y-0 data-[open=true]:scale-100 data-[open=true]:opacity-100 data-[open=true]:transition-[opacity,scale,translate] data-[open=true]:duration-[400ms] data-[open=true]:ease-open max-md:inset-x-2 max-md:top-[66px] max-md:w-auto max-md:max-h-[calc(100dvh_-_82px)]"
    >
        <div class="flex flex-none items-center justify-between gap-3 bg-band px-4 py-3.5 text-band-content">
            <h2 id="nexus-notifications-title" class="text-base font-bold">Notificaciones</h2>

            @if ($unread > 0)
                <span class="rounded-full border border-band-content/30 px-[9px] py-0.5 font-mono text-[15px] whitespace-nowrap">{{ $badge }} sin leer</span>
            @endif
        </div>

        <div aria-hidden="true" class="h-[3px] flex-none bg-accent"></div>

        @if ($items->isEmpty())
            <div class="grid justify-items-center gap-2.5 px-4 py-10 text-center text-base-content/70">
                <span class="grid size-[52px] place-items-center rounded-full border border-base-300 bg-base-200">
                    <x-icon name="o-bell" class="w-6 h-6" />
                </span>
                <b class="text-base font-semibold text-base-content">Sin notificaciones</b>
            </div>
        @else
            <div class="flex flex-none justify-end border-b border-base-300 px-2 py-1.5 max-md:py-0.5">
                <button
                    type="button"
                    wire:click="markAllRead"
                    data-testid="mark-all-read"
                    @disabled($unread === 0)
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-md px-2.5 text-sm font-medium max-md:h-11 whitespace-nowrap hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:text-base-content/45 disabled:hover:bg-transparent"
                >
                    <x-icon name="o-check" class="w-4 h-4" />
                    Marcar todas como leídas
                </button>
            </div>

            <ul role="list" class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                @include('core::notifications.list', ['rows' => $items, 'showCampus' => $showCampus])
            </ul>

            <div class="flex-none border-t border-base-300 p-1.5">
                <a
                    href="{{ route('core.notifications') }}"
                    wire:navigate
                    data-testid="bell-all"
                    x-on:click="bellOpen = false"
                    class="flex h-10 items-center justify-center gap-1.5 rounded-md text-sm font-medium text-primary hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary max-md:h-11"
                >
                    Ver todas ({{ $total }})
                    <x-icon name="o-arrow-right" class="w-4 h-4" />
                </a>
            </div>
        @endif
    </div>
</div>
