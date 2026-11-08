{{--
    Acuerdos entre departamentos (tareas 3.6 y 3.7, maqueta B «Bandeja de dos paneles»): encabezado con el periodo y el
    campus; a la izquierda la lista agrupada («Por decidir», «Enviados», «Decididos», plegables) con búsqueda y filtro
    de tipo; a la derecha el detalle con las acciones fijas al pie. Desde lg los dos paneles van juntos; debajo, la lista
    y el detalle van por separado y el detalle trae «Volver». Todo lo que se pinta viene de AgreementsInboxQuery.

    `reveal()` lleva a la vista el renglón abierto dentro de la lista (al entrar con ?acuerdo= y al pasar al siguiente
    por decidir) y, después de decidir, pone el foco en el título del que sigue.
--}}
<div class="space-y-4" data-testid="agreements-page"
    x-data="{
        reveal(focusTitle) {
            const list = $root.querySelector('[data-agreements-list]');
            const row = list?.querySelector('[aria-current=true]');

            if (row && list.scrollHeight > list.clientHeight) {
                const box = list.getBoundingClientRect();
                const place = row.getBoundingClientRect();

                if (place.top < box.top + 36 || place.bottom > box.bottom) {
                    list.scrollTop += place.top - box.top - (box.height - place.height) / 2;
                }
            }

            const title = $root.querySelector('[data-testid=agreement-title]');

            if (focusTitle && title && title.offsetParent !== null) {
                title.focus({ preventScroll: true });

                if (title.getBoundingClientRect().top < 0) {
                    title.scrollIntoView({ block: 'start' });
                }
            }
        },
    }"
    x-init="$nextTick(() => reveal(false))"
    x-on:agreement-shown.window="$nextTick(() => requestAnimationFrame(() => reveal(true)))">
    <div>
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Acuerdos</h1>
        @if ($groups !== null)
            <p class="mt-1.5 text-neutral">Préstamos de salón y grupos compartidos entre departamentos, <span data-testid="agreements-term">{{ $term->name }}</span> en {{ $campus->name }}.</p>
        @endif
    </div>

    @if ($groups === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="agreements-empty">{{ $message }}</div>
    @else
        <div class="overflow-clip rounded-box border border-base-300 bg-base-100 lg:grid lg:h-[calc(100dvh-215px)] lg:min-h-[32rem] lg:grid-cols-[minmax(18.75rem,23.75rem)_minmax(0,1fr)]" data-testid="agreements-pane">
            <section @class(['flex min-h-0 flex-col border-base-300 lg:border-e', 'max-lg:hidden' => $mobileDetail]) aria-label="Lista de acuerdos" data-testid="agreements-list">
                <div class="grid gap-2 border-b border-base-300 p-3">
                    <x-input icon="o-magnifying-glass" placeholder="Salón o grupo" aria-label="Buscar por salón o grupo" wire:model.live.debounce.300ms="search" clearable data-testid="agreements-search" />
                    <x-select :options="[['id' => '', 'name' => 'Todos los tipos'], ['id' => 'room_loan', 'name' => 'Préstamo de salón'], ['id' => 'shared_group', 'name' => 'Grupo compartido']]" wire:model.live="kind" aria-label="Tipo" data-testid="agreements-kind" />
                </div>
                @include('scheduling::agreements.list')
            </section>

            <section @class(['flex min-h-0 min-w-0 flex-col', 'max-lg:hidden' => ! $mobileDetail]) aria-label="Detalle del acuerdo" data-testid="agreements-detail">
                @if ($detail === null)
                    <div class="m-auto grid justify-items-center gap-2 p-6 text-neutral">
                        <x-icon name="o-arrows-right-left" class="h-5 w-5" />
                        <span>Elige un acuerdo de la lista.</span>
                    </div>
                @else
                    @include('scheduling::agreements.detail')
                @endif
            </section>
        </div>
    @endif
</div>
