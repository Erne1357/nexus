{{--
    «Correos y colas» (CF-S8): encabezado, pestañas y, según la pestaña, el registro de correos con su barra de
    filtros estándar, las invitaciones pendientes con «Reenviar», o los tamaños de las colas y los trabajos fallidos con
    «Acciones» (Reintentar, Descartar con confirmación corta y Ver error en un drawer). Solo lectura salvo esas acciones.
--}}
<div>
    <x-header title="Correos y colas" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Lo que salió por correo (sin su contenido), las invitaciones que siguen sin activarse y el estado de las colas de trabajo.
        </x-slot:subtitle>
    </x-header>

    <div role="tablist" aria-label="Vistas de correos y colas" class="tabs tabs-border mb-4" data-testid="mail-tabs">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="showTab('{{ $key }}')" @class(['tab max-md:min-h-11', 'tab-active font-semibold' => $activeTab === $key]) aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" data-testid="tab-{{ $key }}">{{ $label }}</button>
        @endforeach
    </div>

    @php
        $tableClass = '[&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40';
    @endphp

    @if ($activeTab === 'mail')
        <x-core::catalog-toolbar narrow>
            <x-slot:search>
                <x-input wire:model.live.debounce.300ms="search" placeholder="Destinatario o asunto" aria-label="Buscar correos" icon="o-magnifying-glass" clearable />
            </x-slot:search>

            <x-select wire:model.live="kind" aria-label="Tipo" :options="$kinds" placeholder="Tipo" />
            <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Estado" />
            <x-input wire:model.live="from" type="date" aria-label="Desde" />
            <x-input wire:model.live="to" type="date" aria-label="Hasta" />
        </x-core::catalog-toolbar>

        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100" data-testid="mail-table">
            <x-table :headers="[['key' => 'sent_at', 'label' => 'Fecha y hora', 'sortable' => false], ['key' => 'to', 'label' => 'Para', 'sortable' => false], ['key' => 'subject', 'label' => 'Asunto', 'sortable' => false], ['key' => 'kind', 'label' => 'Tipo', 'sortable' => false], ['key' => 'status', 'label' => 'Estado', 'sortable' => false]]" :rows="$mailLog" :class="$tableClass">
                @scope('cell_sent_at', $mail)
                    <span class="font-mono text-sm tabular-nums whitespace-nowrap">{{ $mail->sent_at->format('d/m/Y H:i') }}</span>
                @endscope

                @scope('cell_to', $mail)
                    <span class="break-all">{{ $mail->to }}</span>
                    @if ($mail->user)
                        <span class="block text-xs text-neutral">{{ $mail->user->username }}</span>
                    @endif
                @endscope

                @scope('cell_kind', $mail)
                    <span class="font-mono text-sm">{{ $mail->kind }}</span>
                @endscope

                @scope('cell_status', $mail)
                    <x-core::status-badge :tone="$mail->status === \App\Modules\Core\Enums\MailStatus::Sent ? 'success' : 'error'" :label="$mail->status->label()" />
                    @if ($mail->error)
                        <span class="mt-1 block max-w-xs text-xs text-neutral">{{ $mail->error }}</span>
                    @endif
                @endscope

                <x-slot:empty>
                    <x-icon name="o-envelope" label="Ningún correo coincide con la búsqueda." />
                </x-slot:empty>
            </x-table>

            @if ($mailLog->hasPages())
                <div class="border-t border-base-300 px-4 py-2.5">{{ $mailLog->onEachSide(1)->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </div>
    @elseif ($activeTab === 'invitations')
        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100" data-testid="invitations-table">
            <x-table :headers="[['key' => 'username', 'label' => 'Usuario', 'sortable' => false], ['key' => 'email', 'label' => 'Correo', 'sortable' => false], ['key' => 'expires', 'label' => 'Vence', 'sortable' => false]]" :rows="$pending" :class="$tableClass">
                @scope('cell_username', $row)
                    <span class="font-mono text-[15px] tabular-nums">{{ $row['user']->username }}</span>
                    <span class="block text-xs text-neutral">{{ $row['user']->name }}</span>
                @endscope

                @scope('cell_email', $row)
                    <span class="break-all">{{ $row['user']->email }}</span>
                @endscope

                @scope('cell_expires', $row)
                    <span class="font-mono text-sm tabular-nums whitespace-nowrap">{{ $row['expires_at']->format('d/m/Y H:i') }}</span>
                    @if ($row['expired'])
                        <x-core::status-badge tone="warning" label="Vencida" class="ms-2" />
                    @endif
                @endscope

                @scope('actions', $row)
                    @can('resendInvitation', \App\Modules\Core\Models\MailLog::class)
                        <x-button label="Reenviar" icon="o-paper-airplane" wire:click="resend('{{ $row['user']->id }}')" spinner="resend('{{ $row['user']->id }}')" class="btn-ghost btn-sm" :data-testid="'resend-'.$row['user']->username" />
                    @else
                        <span class="tooltip tooltip-left" data-tip="Solo el administrador reenvía invitaciones.">
                            <x-button label="Reenviar" icon="o-paper-airplane" class="btn-ghost btn-sm" disabled />
                        </span>
                    @endcan
                @endscope

                <x-slot:empty>
                    <x-icon name="o-envelope-open" label="No hay invitaciones pendientes." />
                </x-slot:empty>
            </x-table>
        </div>
    @else
        <ul class="mb-4 grid gap-3 sm:grid-cols-2" data-testid="queue-sizes">
            @foreach ($sizes as $size)
                <li class="rounded-box border border-base-300 bg-base-100 px-4 py-3" data-testid="queue-{{ $size['queue'] }}">
                    <p class="font-mono text-[15px] font-semibold">{{ $size['queue'] }}</p>
                    <dl class="mt-2 grid grid-cols-3 gap-2 text-sm">
                        <div><dt class="text-neutral">Esperan</dt><dd class="font-mono text-xl tabular-nums">{{ $size['waiting'] }}</dd></div>
                        <div><dt class="text-neutral">Diferidos</dt><dd class="font-mono text-xl tabular-nums">{{ $size['delayed'] }}</dd></div>
                        <div><dt class="text-neutral">En proceso</dt><dd class="font-mono text-xl tabular-nums">{{ $size['reserved'] }}</dd></div>
                    </dl>
                </li>
            @endforeach
        </ul>

        <h2 class="mb-2 text-lg font-semibold">Trabajos fallidos</h2>

        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100" data-testid="failed-table">
            <x-table :headers="[['key' => 'failed_at', 'label' => 'Fecha y hora', 'sortable' => false], ['key' => 'queue', 'label' => 'Cola', 'sortable' => false], ['key' => 'job_name', 'label' => 'Trabajo', 'sortable' => false], ['key' => 'exception_head', 'label' => 'Error', 'sortable' => false]]" :rows="$failedJobs" :class="$tableClass">
                @scope('cell_failed_at', $job)
                    <span class="font-mono text-sm tabular-nums whitespace-nowrap">{{ $job->failed_at->format('d/m/Y H:i') }}</span>
                @endscope

                @scope('cell_queue', $job)
                    <span class="font-mono text-sm">{{ $job->queue }}</span>
                @endscope

                @scope('cell_job_name', $job)
                    <span class="font-mono text-sm break-all">{{ $job->job_name }}</span>
                @endscope

                @scope('cell_exception_head', $job)
                    <span class="line-clamp-3 max-w-md whitespace-pre-line break-words text-sm text-neutral">{{ $job->exception_head }}</span>
                @endscope

                @scope('actions', $job)
                    <x-core::row-menu :name="$job->job_name">
                        <x-core::row-menu-item label="Ver error" wire:click="openError('{{ $job->uuid }}')" :spinner="'openError(\''.$job->uuid.'\')'" />
                        @can('retry', $job)
                            <x-core::row-menu-item label="Reintentar" wire:click="retry('{{ $job->uuid }}')" :spinner="'retry(\''.$job->uuid.'\')'" />
                            <x-core::row-menu-item
                                label="Descartar"
                                danger
                                wire:click="forget('{{ $job->uuid }}')"
                                x-confirm="El trabajo se borra de la lista de fallidos y no se vuelve a correr."
                                data-confirm-title="¿Descartar este trabajo?"
                                data-confirm-button="Descartar"
                                data-confirm-tone="error"
                                :spinner="'forget(\''.$job->uuid.'\')'"
                            />
                        @else
                            <x-core::row-menu-item label="Reintentar" reason="Solo el administrador reintenta trabajos." disabled />
                            <x-core::row-menu-item label="Descartar" reason="Solo el administrador descarta trabajos." disabled />
                        @endcan
                    </x-core::row-menu>
                @endscope

                <x-slot:empty>
                    <x-icon name="o-check-circle" label="No hay trabajos fallidos." />
                </x-slot:empty>
            </x-table>

            @if ($failedJobs->hasPages())
                <div class="border-t border-base-300 px-4 py-2.5">{{ $failedJobs->onEachSide(1)->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </div>

        <x-drawer wire:model.live="showError" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="error-title" data-testid="error-drawer">
            @if ($errorJob)
                <div class="space-y-3 p-6">
                    <h2 id="error-title" class="text-lg font-semibold">Error del trabajo</h2>
                    <p class="font-mono text-sm break-all">{{ $errorJob->job_name }}</p>
                    <p class="text-sm text-neutral">Cola <span class="font-mono">{{ $errorJob->queue }}</span> · {{ $errorJob->failed_at->format('d/m/Y H:i') }}</p>
                    <pre class="max-h-[60vh] overflow-auto rounded-box bg-base-200 p-3 text-xs whitespace-pre-wrap break-words">{{ $errorJob->exception_shown }}</pre>
                </div>
            @endif
        </x-drawer>
    @endif
</div>
