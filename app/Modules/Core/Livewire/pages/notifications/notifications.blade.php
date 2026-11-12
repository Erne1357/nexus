{{--
    «Todas las notificaciones» (maqueta C de la campana): encabezado, filtro Todas / Sin leer, «Marcar todas como
    leídas», el filtro por tipo (si tiene más de uno) y la lista en una superficie con filete, agrupada por día,
    con el mismo renglón que la tarjeta de la campana (core::notifications.item). Paginada de 25 en 25. La lista
    mide como máximo 48 rem (max-w-3xl) para que en pantallas anchas el punto de «sin leer» no quede lejos del título.
    Contadores como en la bandeja: «Todas 28», «Sin leer 3».
--}}
<div>
    <x-header title="Todas las notificaciones" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator />

    <div class="max-w-3xl" data-testid="notifications-list">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div role="group" aria-label="Filtrar notificaciones" class="join">
                <button
                    type="button"
                    wire:click="$set('filter', 'todas')"
                    data-testid="filter-all"
                    aria-pressed="{{ $onlyUnread ? 'false' : 'true' }}"
                    @class(['btn join-item h-11 md:btn-sm md:h-10', 'btn-primary' => ! $onlyUnread])
                >
                    Todas <span class="font-mono tabular-nums">{{ $total }}</span>
                </button>
                <button
                    type="button"
                    wire:click="$set('filter', 'sin-leer')"
                    data-testid="filter-unread"
                    aria-pressed="{{ $onlyUnread ? 'true' : 'false' }}"
                    @class(['btn join-item h-11 md:btn-sm md:h-10', 'btn-primary' => $onlyUnread])
                >
                    Sin leer <span class="font-mono tabular-nums">{{ $unread }}</span>
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3 max-md:w-full">
                @if (count($types) > 1)
                    <x-select
                        wire:model.live="type"
                        :options="$types"
                        placeholder="Todos los tipos"
                        placeholder-value=""
                        aria-label="Filtrar por tipo de aviso"
                        class="select-sm"
                        data-testid="filter-type"
                    />
                @endif

                <x-button
                    label="Marcar todas como leídas"
                    icon="o-check"
                    wire:click="markAllRead"
                    data-testid="mark-all-read"
                    :disabled="$unread === 0"
                    class="h-11 md:h-10 max-md:w-full"
                />
            </div>
        </div>

        <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
            @if ($items->isEmpty())
                <div class="grid justify-items-center gap-2.5 px-4 py-12 text-center text-neutral">
                    <span class="grid size-[52px] place-items-center rounded-full border border-base-300 bg-base-200">
                        <x-icon name="o-bell" class="w-6 h-6" />
                    </span>
                    <b class="text-base font-semibold text-base-content">{{ $onlyUnread ? 'No tienes notificaciones sin leer' : 'Sin notificaciones' }}</b>
                </div>
            @else
                <ul role="list">
                    @include('core::notifications.list', ['rows' => $items->items(), 'showRole' => $showRole])
                </ul>

                @if ($items->hasPages())
                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                        <p>Notificaciones <span class="font-mono">{{ $items->firstItem() }}–{{ $items->lastItem() }}</span> de <span class="font-mono">{{ $items->total() }}</span></p>

                        {{ $items->onEachSide(1)->links(data: ['scrollTo' => false, 'summary' => false]) }}
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
