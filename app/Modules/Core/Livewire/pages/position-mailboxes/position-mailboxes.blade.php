{{--
    «Buzones de puesto» (CE-02, tarea 1.63; DESIGN.md, «Anatomía de una pantalla de catálogo»): encabezado; si hay, los
    correos en espera sin buzón agrupados por puesto, cada uno con «Capturar buzón»; la barra con la búsqueda y «Agregar
    buzón»; la tabla de buzones (puesto con su alcance, buzón en Mono, correos en espera que recibiría, estado y acciones)
    y, debajo de 768 px, los mismos buzones como renglones apilados, que no repiten los data-testid de la tabla. El cajón
    de la derecha captura o edita un buzón; desactivar pide confirmación con el diálogo de Core (x-confirm).
--}}
@php
    $chipsText = fn (array $chips): string => implode(' · ', array_column($chips, 'value'));
    $unattendedTotal = array_sum(array_column($unattended, 'count'));
    $manageReason = 'Solo el administrador captura y cambia buzones de puesto.';
@endphp

<div>
    <x-header title="Buzones de puesto" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los avisos que competen a un puesto mandan su correo al buzón institucional del puesto, no al de quien lo ocupa. Si el puesto todavía no tiene buzón, el aviso llega a la campana y su correo espera aquí.
        </x-slot:subtitle>
    </x-header>

    @if ($unattended !== [])
        <section class="mb-6 overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="waiting-title" data-testid="waiting-without-mailbox">
            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-base-300 px-4 py-3">
                <x-icon name="o-clock" class="h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
                <h2 id="waiting-title" class="font-semibold">Correos en espera sin buzón</h2>
                <x-core::status-badge tone="warning" :label="$unattendedTotal === 1 ? '1 correo' : $unattendedTotal.' correos'" />
                <p class="w-full text-sm text-neutral">Ya llegaron a la campana de quien ocupa el puesto. Su correo sale en cuanto captures el buzón.</p>
            </header>

            <ul class="divide-y divide-base-300">
                @foreach ($unattended as $group)
                    <li wire:key="waiting-{{ md5($group['position']->key()) }}" class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                        <div class="min-w-0 flex-1 basis-64">
                            <p class="font-semibold">{{ $group['label'] }}</p>
                            @if ($group['chips'] !== [])
                                <p class="text-sm text-neutral">{{ $chipsText($group['chips']) }}</p>
                            @endif
                        </div>
                        <p class="text-sm text-neutral">
                            <span class="font-mono tabular-nums text-base-content">{{ $group['count'] }}</span> {{ $group['count'] === 1 ? 'correo' : 'correos' }} desde el <span class="font-mono tabular-nums">{{ $group['since']->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                        </p>
                        @if ($canManage)
                            <x-button label="Capturar buzón" icon="o-plus" class="btn-ghost btn-sm max-md:h-11 max-md:w-full" wire:click="capture('{{ $group['position']->key() }}')" spinner="capture('{{ $group['position']->key() }}')" data-testid="capture-mailbox" />
                        @else
                            <span class="tooltip tooltip-left" data-tip="{{ $manageReason }}">
                                <x-button label="Capturar buzón" icon="o-plus" class="btn-ghost btn-sm" disabled />
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" placeholder="Correo o puesto" aria-label="Buscar buzones" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-slot:action>
            @if ($canManage)
                <x-button label="Agregar buzón" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" data-testid="add-mailbox" />
            @else
                <span class="tooltip tooltip-left" data-tip="{{ $manageReason }}">
                    <x-button label="Agregar buzón" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100" data-testid="mailboxes-table">
        <x-table
            :headers="$headers"
            :rows="$rows"
            :row-decoration="['text-neutral' => fn (array $row): bool => ! $row['mailbox']->is_active]"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_position', $row)
                <span @class(['block', 'font-semibold' => $row['mailbox']->is_active])>{{ $row['label'] }}</span>
                @if ($row['chips'] !== [])
                    <span class="block text-sm text-neutral">{{ implode(' · ', array_column($row['chips'], 'value')) }}</span>
                @endif
            @endscope

            @scope('cell_email', $row)
                <span class="font-mono text-[14px] break-all">{{ $row['mailbox']->email }}</span>
            @endscope

            @scope('cell_waiting', $row)
                @if ($row['waiting'] > 0)
                    <x-core::status-badge tone="info" :label="$row['waiting'].' por salir'" icon="o-clock" />
                @else
                    <span class="font-mono tabular-nums text-neutral">0</span>
                @endif
            @endscope

            @scope('cell_status', $row)
                <x-core::status-badge :tone="$row['mailbox']->is_active ? 'success' : 'neutral'" :label="$row['mailbox']->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $row)
                @include('core::position-mailboxes.row-actions', ['mailbox' => $row['mailbox'], 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-inbox-stack" :label="$search === '' ? 'Todavía no hay buzones de puesto. Agrega el primero o captura uno de los que esperan.' : 'Ningún buzón coincide con la búsqueda.'" />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="mailbox-cards">
            @forelse ($rows as $row)
                <li wire:key="mailbox-card-{{ $row['id'] }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $row['mailbox']->is_active])>
                    <div class="flex items-start gap-2.5">
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $row['mailbox']->is_active])>{{ $row['label'] }}</span>
                        <x-core::status-badge :tone="$row['mailbox']->is_active ? 'success' : 'neutral'" :label="$row['mailbox']->is_active ? 'Activo' : 'Inactivo'" />
                    </div>
                    @if ($row['chips'] !== [])
                        <p class="text-sm text-neutral">{{ $chipsText($row['chips']) }}</p>
                    @endif
                    <p class="font-mono text-sm break-all">{{ $row['mailbox']->email }}</p>
                    @if ($row['waiting'] > 0)
                        <p class="text-sm text-neutral"><span class="font-mono tabular-nums text-base-content">{{ $row['waiting'] }}</span> por salir</p>
                    @endif
                    @include('core::position-mailboxes.row-actions', ['mailbox' => $row['mailbox'], 'inTable' => false])
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-neutral">{{ $search === '' ? 'Todavía no hay buzones de puesto.' : 'Ningún buzón coincide con la búsqueda.' }}</li>
            @endforelse
        </ul>

        @if ($rows !== [])
            @php $active = count(array_filter($rows, fn (array $row): bool => $row['mailbox']->is_active)); @endphp
            <p class="border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                <span class="font-mono tabular-nums">{{ count($rows) }}</span> {{ count($rows) === 1 ? 'buzón' : 'buzones' }} · <span class="font-mono tabular-nums">{{ $active }}</span> {{ $active === 1 ? 'activo' : 'activos' }}
            </p>
        @endif
    </div>

    @if ($held->total() > 0)
        <section class="mt-6 overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="held-title" data-testid="held-mails">
            <header class="border-b border-base-300 px-4 py-3">
                <h2 id="held-title" class="font-semibold">Correos de puesto en espera o con falla</h2>
                <p class="text-sm text-neutral">Uno por aviso. Un correo que falló varias veces deja de intentarse solo: reinténtalo o descártalo. Descartar no quita la campana de quien lo recibió.</p>
            </header>

            <ul class="divide-y divide-base-300">
                @foreach ($held as $row)
                    @php $mail = $row['held']; @endphp
                    <li wire:key="held-{{ $row['id'] }}" class="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                        <div class="min-w-0 flex-1 basis-72">
                            <p class="font-semibold">{{ $mail->subject ?? \App\Modules\Core\Support\MailKinds::label($mail->kind) }}</p>
                            <p class="text-sm text-neutral">{{ $row['label'] }}@if ($row['chips'] !== []) · {{ $chipsText($row['chips']) }}@endif</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm text-neutral">
                            @if ($mail->failed_at !== null)
                                <x-core::status-badge tone="error" label="Falló" />
                                <span><span class="font-mono tabular-nums text-base-content">{{ $mail->attempts }}</span> {{ $mail->attempts === 1 ? 'intento' : 'intentos' }}</span>
                            @else
                                <x-core::status-badge tone="warning" :label="$row['mailbox'] === null ? 'Sin buzón' : 'Por salir'" icon="o-clock" />
                            @endif
                            <span>desde el <span class="font-mono tabular-nums">{{ $mail->held_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span></span>
                        </div>
                        <div class="flex gap-1 max-md:w-full">
                            @can('retry', $mail)
                                <x-button label="Reintentar" icon="o-arrow-path" class="btn-ghost btn-sm max-md:h-11 max-md:flex-1" wire:click="retry('{{ $mail->id }}')" spinner="retry('{{ $mail->id }}')" data-testid="retry-held" />
                            @endcan
                            @can('discard', $mail)
                                <x-button
                                    label="Descartar"
                                    icon="o-trash"
                                    class="btn-ghost btn-sm text-error max-md:h-11 max-md:flex-1"
                                    wire:click="discard('{{ $mail->id }}')"
                                    x-confirm="Ya no se mandará. La campana de quien lo recibió se queda igual."
                                    data-confirm-title="¿Descartar este correo de puesto?"
                                    data-confirm-button="Descartar"
                                    data-confirm-tone="error"
                                    spinner="discard('{{ $mail->id }}')"
                                    data-testid="discard-held"
                                />
                            @else
                                <span class="tooltip tooltip-left" data-tip="Solo el administrador descarta correos de puesto.">
                                    <x-button label="Descartar" icon="o-trash" class="btn-ghost btn-sm" disabled />
                                </span>
                            @endcan
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($held->hasPages())
                <div class="border-t border-base-300 px-4 py-2.5">{{ $held->onEachSide(1)->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </section>
    @endif

    <p class="mt-4 flex items-start gap-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-3 text-sm" data-testid="mailboxes-note">
        <x-icon name="o-information-circle" class="mt-0.5 h-5 w-5 shrink-0 text-neutral" aria-hidden="true" />
        <span>Un buzón vale para el alcance que fija; lo que dejas vacío es «todas». Si a un puesto le sirven varios, gana el más específico. Los avisos de persona (contraseña, invitación, avisos al docente) siguen yendo al correo institucional de cada quien.</span>
    </p>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="mailbox-drawer-title" data-testid="mailbox-drawer">
        @include('core::position-mailboxes.drawer')
    </x-drawer>
</div>
