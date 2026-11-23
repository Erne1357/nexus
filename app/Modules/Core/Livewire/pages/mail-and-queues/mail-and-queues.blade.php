{{--
    «Correos y colas» (CF-S8): encabezado, la tarjeta «Cuenta de envío» (tarea 1.29, sending-account), pestañas y, según la pestaña, el registro de correos con su barra de
    filtros estándar, las invitaciones pendientes con «Reenviar», o los tamaños de las colas y los trabajos fallidos con
    «Acciones» (Reintentar, Descartar con confirmación corta y Ver error en un drawer). Solo lectura salvo esas acciones.
    El tipo de correo sale en español (MailKinds). Debajo de 768 px cada tabla cede su lugar a renglones apilados, que no
    repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Correos y colas" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            La cuenta con que sale el correo, lo que salió (sin su contenido), las invitaciones que siguen sin activarse y el estado de las colas de trabajo.
        </x-slot:subtitle>
    </x-header>

    @if ($sending)
        @include('core::mail-and-queues.sending-account')
    @endif

    <div role="tablist" aria-label="Vistas de correos y colas" class="tabs tabs-border mb-4" data-testid="mail-tabs">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" wire:click="showTab('{{ $key }}')" @class(['tab max-md:min-h-11', 'tab-active font-semibold' => $activeTab === $key]) aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}" data-testid="tab-{{ $key }}">{{ $label }}</button>
        @endforeach
    </div>

    @php
        $tableClass = '[&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40';
    @endphp

    {{-- Cada pestaña lleva su llave: sin ella, Livewire reusa la tabla de una para la otra y la de MaryUI arranca sin sus encabezados. --}}
    <div wire:key="tab-content-{{ $activeTab }}">
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
            <x-table :headers="[['key' => 'sent_at', 'label' => 'Fecha y hora', 'sortable' => false], ['key' => 'to', 'label' => 'Para', 'sortable' => false], ['key' => 'subject', 'label' => 'Asunto', 'sortable' => false], ['key' => 'kind', 'label' => 'Tipo', 'sortable' => false], ['key' => 'status', 'label' => 'Estado', 'sortable' => false]]" :rows="$mailLog" :class="'max-md:hidden '.$tableClass">
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
                    {{ \App\Modules\Core\Support\MailKinds::label($mail->kind) }}
                @endscope

                @scope('cell_status', $mail)
                    <x-core::status-badge :tone="$mail->status === \App\Modules\Core\Enums\MailStatus::Sent ? 'success' : 'error'" :label="$mail->status->label()" />
                    @if ($mail->error)
                        <span class="mt-1 block max-w-xs text-xs text-neutral">{{ \App\Modules\Core\Support\RedactsSecrets::redact($mail->error) }}</span>
                    @endif
                @endscope

                <x-slot:empty>
                    <x-icon name="o-envelope" label="Ningún correo coincide con la búsqueda." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="mail-cards">
                @forelse ($mailLog as $mail)
                    <li wire:key="mail-card-{{ $mail->id }}" class="space-y-1 px-4 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-xs tabular-nums text-neutral">{{ $mail->sent_at->format('d/m/Y H:i') }}</span>
                            <x-core::status-badge :tone="$mail->status === \App\Modules\Core\Enums\MailStatus::Sent ? 'success' : 'error'" :label="$mail->status->label()" />
                        </div>
                        <p class="font-semibold">{{ $mail->subject }}</p>
                        <p class="break-all text-sm">{{ $mail->to }}@if ($mail->user) <span class="text-neutral">· {{ $mail->user->username }}</span>@endif</p>
                        <p class="text-xs text-neutral">{{ \App\Modules\Core\Support\MailKinds::label($mail->kind) }}</p>
                        @if ($mail->error)
                            <p class="text-xs text-neutral">{{ \App\Modules\Core\Support\RedactsSecrets::redact($mail->error) }}</p>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-neutral">Ningún correo coincide con la búsqueda.</li>
                @endforelse
            </ul>

            @if ($mailLog->hasPages())
                <div class="border-t border-base-300 px-4 py-2.5">{{ $mailLog->onEachSide(1)->links(data: ['scrollTo' => false]) }}</div>
            @endif
        </div>
    @elseif ($activeTab === 'invitations')
        <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100" data-testid="invitations-table">
            <x-table :headers="[['key' => 'username', 'label' => 'Usuario', 'sortable' => false], ['key' => 'email', 'label' => 'Correo', 'sortable' => false], ['key' => 'expires', 'label' => 'Vence', 'sortable' => false]]" :rows="$pending" :class="'max-md:hidden '.$tableClass">
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
                    @include('core::mail-and-queues.resend-button', ['row' => $row, 'inTable' => true])
                @endscope

                <x-slot:empty>
                    <x-icon name="o-envelope-open" label="No hay invitaciones pendientes." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="invitation-cards">
                @forelse ($pending as $row)
                    <li wire:key="invitation-card-{{ $row['user']->id }}" class="space-y-1 px-4 py-3">
                        <p class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-mono text-[15px] tabular-nums">{{ $row['user']->username }}</span>
                            <span class="min-w-0">{{ $row['user']->name }}</span>
                        </p>
                        <p class="break-all text-sm">{{ $row['user']->email }}</p>
                        <p class="flex flex-wrap items-center gap-2 text-sm text-neutral">
                            <span>Vence <span class="font-mono tabular-nums">{{ $row['expires_at']->format('d/m/Y H:i') }}</span></span>
                            @if ($row['expired'])
                                <x-core::status-badge tone="warning" label="Vencida" />
                            @endif
                        </p>
                        @include('core::mail-and-queues.resend-button', ['row' => $row, 'inTable' => false])
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-neutral">No hay invitaciones pendientes.</li>
                @endforelse
            </ul>
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
            <x-table :headers="[['key' => 'failed_at', 'label' => 'Fecha y hora', 'sortable' => false], ['key' => 'queue', 'label' => 'Cola', 'sortable' => false], ['key' => 'job_name', 'label' => 'Trabajo', 'sortable' => false], ['key' => 'exception_head', 'label' => 'Error', 'sortable' => false]]" :rows="$failedJobs" :class="'max-md:hidden '.$tableClass">
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
                    @include('core::mail-and-queues.failed-job-menu', ['job' => $job, 'inTable' => true])
                @endscope

                <x-slot:empty>
                    <x-icon name="o-check-circle" label="No hay trabajos fallidos." />
                </x-slot:empty>
            </x-table>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="failed-cards">
                @forelse ($failedJobs as $job)
                    <li wire:key="failed-card-{{ $job->uuid }}" class="flex items-start gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1 space-y-1">
                            <p class="font-mono text-xs tabular-nums text-neutral">{{ $job->failed_at->format('d/m/Y H:i') }} · {{ $job->queue }}</p>
                            <p class="break-all font-mono text-sm">{{ $job->job_name }}</p>
                            <p class="line-clamp-3 whitespace-pre-line break-words text-sm text-neutral">{{ $job->exception_head }}</p>
                        </div>
                        @include('core::mail-and-queues.failed-job-menu', ['job' => $job, 'inTable' => false])
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-neutral">No hay trabajos fallidos.</li>
                @endforelse
            </ul>

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
</div>
