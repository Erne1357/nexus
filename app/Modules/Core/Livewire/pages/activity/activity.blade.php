{{--
    «Bitácora» (CF-S5): encabezado, la barra de filtros estándar y una tabla con fecha y hora, quién, puesto, qué, tipo de
    registro y evento; tocar un renglón abre el drawer con el detalle. Solo lectura. Paginación simple (sin total): la
    tabla es grande. Debajo de 768 px la tabla cede su lugar a renglones apilados.
--}}
<div>
    <x-header title="Bitácora" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Quién cambió qué y cuándo. Solo se lee: la bitácora no se edita ni se borra.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar narrow>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" placeholder="Texto de la descripción" aria-label="Buscar en la bitácora" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-input wire:model.live.debounce.300ms="who" placeholder="Quién" aria-label="Quién hizo el cambio" clearable />
        <x-select wire:model.live="subjectType" aria-label="Tipo de registro" :options="$subjectTypes" placeholder="Tipo de registro" />
        <x-select wire:model.live="event" aria-label="Evento" :options="$events" placeholder="Evento" />
        <x-input wire:model.live="from" type="date" aria-label="Desde" />
        <x-input wire:model.live="to" type="date" aria-label="Hasta" />
    </x-core::catalog-toolbar>

    @php
        $headers = [
            ['key' => 'created_at', 'label' => 'Fecha y hora', 'sortable' => false],
            ['key' => 'causer', 'label' => 'Quién', 'sortable' => false],
            ['key' => 'acting_as', 'label' => 'Puesto', 'sortable' => false],
            ['key' => 'description', 'label' => 'Qué', 'sortable' => false],
            ['key' => 'subject_type', 'label' => 'Registro', 'sortable' => false],
            ['key' => 'event', 'label' => 'Evento', 'sortable' => false],
        ];
    @endphp

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="activity-table">
        <x-table
            :headers="$headers"
            :rows="$entries"
            @row-click="$wire.openEntry($event.detail.id)"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_created_at', $entry)
                <span class="font-mono text-sm tabular-nums whitespace-nowrap">{{ $entry->created_at->format('d/m/Y H:i') }}</span>
            @endscope

            @scope('cell_causer', $entry)
                @if ($entry->causer)
                    <span class="font-mono text-[15px] tabular-nums">{{ $entry->causer->username }}</span>
                    <span class="block text-xs text-neutral">{{ $entry->causer->name }}</span>
                @else
                    <span class="text-neutral">Sistema</span>
                @endif
            @endscope

            @scope('cell_acting_as', $entry)
                @php($role = $entry->properties['acting_as'] ?? null)
                {{ $role === null ? '—' : (\App\Modules\Core\Enums\Role::tryFrom($role)?->listLabel() ?? $role) }}
            @endscope

            @scope('cell_description', $entry)
                <span class="max-w-md break-words">{{ $entry->description }}</span>
            @endscope

            @scope('cell_subject_type', $entry)
                {{ \App\Modules\Core\Support\ActivityRecordTypes::label($entry->subject_type) }}
            @endscope

            @scope('cell_event', $entry)
                <span class="font-mono text-sm">{{ $entry->event ?? '—' }}</span>
            @endscope

            @scope('actions', $entry)
                <x-button label="Detalle" icon="o-eye" wire:click="openEntry({{ $entry->id }})" spinner="openEntry({{ $entry->id }})" class="btn-ghost btn-sm" @click.stop :data-testid="'detail-'.$entry->id" />
            @endscope

            <x-slot:empty>
                <x-icon name="o-clipboard-document-list" label="Ninguna entrada coincide con los filtros." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="activity-cards">
            @foreach ($entries as $entry)
                <li wire:key="activity-card-{{ $entry->id }}" class="space-y-1 px-4 py-3">
                    <p class="font-mono text-xs tabular-nums text-neutral">{{ $entry->created_at->format('d/m/Y H:i') }} · {{ $entry->causer?->username ?? 'Sistema' }}</p>
                    <p class="break-words">{{ $entry->description }}</p>
                    <p class="text-xs text-neutral">{{ \App\Modules\Core\Support\ActivityRecordTypes::label($entry->subject_type) }} · <span class="font-mono">{{ $entry->event ?? '—' }}</span></p>
                    <x-button label="Detalle" icon="o-eye" wire:click="openEntry({{ $entry->id }})" class="btn-ghost btn-sm -ms-3" />
                </li>
            @endforeach
        </ul>

        @if ($entries->hasPages())
            <div class="border-t border-base-300 px-4 py-2.5">{{ $entries->links(data: ['scrollTo' => false]) }}</div>
        @endif
    </div>

    <x-drawer wire:model.live="showDetail" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="activity-title" data-testid="activity-drawer">
        @if ($detail)
            <div class="space-y-5 p-6">
                <div>
                    <h2 id="activity-title" class="text-lg font-semibold break-words">{{ $detail->activity->description }}</h2>
                    <p class="mt-1 font-mono text-sm tabular-nums text-neutral">{{ $detail->activity->created_at->format('d/m/Y H:i:s') }}</p>
                </div>

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="text-neutral">Quién</dt>
                    <dd>{{ $detail->activity->causer ? $detail->activity->causer->username.' · '.$detail->activity->causer->name : 'Sistema' }}</dd>
                    <dt class="text-neutral">Puesto</dt>
                    <dd>{{ $detail->actingAs ?? '—' }}</dd>
                    <dt class="text-neutral">Evento</dt>
                    <dd class="font-mono">{{ $detail->activity->event ?? '—' }}</dd>
                    <dt class="text-neutral">Registro</dt>
                    <dd>
                        {{ $detail->typeLabel }}@if ($detail->subjectName) · {{ $detail->subjectName }}@endif
                        @if ($detail->activity->subject_id)
                            <span class="block break-all font-mono text-xs text-neutral">{{ $detail->activity->subject_id }}</span>
                        @endif
                    </dd>
                </dl>

                <section>
                    <h3 class="mb-2 text-sm font-semibold">Cambios</h3>
                    @if ($detail->changes === [])
                        <p class="text-sm text-neutral">Esta entrada no guardó campos antes y después.</p>
                    @else
                        <div class="overflow-x-auto rounded-box border border-base-300">
                            <table class="table table-sm" data-testid="activity-changes">
                                <thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
                                <tbody>
                                    @foreach ($detail->changes as $change)
                                        <tr>
                                            <td class="font-mono text-sm">{{ $change['field'] }}</td>
                                            <td class="break-all text-neutral">{{ $change['old'] }}</td>
                                            <td class="break-all font-medium">{{ $change['new'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                @if ($detail->properties !== [])
                    <section>
                        <h3 class="mb-2 text-sm font-semibold">Propiedades</h3>
                        <pre class="max-h-72 overflow-auto rounded-box bg-base-200 p-3 text-xs whitespace-pre-wrap break-words" data-testid="activity-properties">{{ json_encode($detail->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </section>
                @endif
            </div>
        @endif
    </x-drawer>
</div>
