{{--
    La tarjeta «Cuenta de envío» de «Correos y colas» (tarea 1.29, ADR-0032, CF-S9): con qué cuenta de Microsoft 365 sale
    el correo por Microsoft Graph y su estado (faltan variables, sin conectar, conectada desde cuándo y su último envío,
    o vencida), el aviso del regreso de Microsoft, lo que falta en el .env (MAIL_MAILER, MAIL_FROM_ADDRESS) y la
    dirección de regreso que se registra en Azure. Acciones: «Conectar con Microsoft» (o «Reconectar»), «Enviar correo de
    prueba» y «Desconectar» con la confirmación de Core (ADR-0023). Lo que el puesto no puede, deshabilitado con su
    motivo. Espera $sending (MailConnectionQuery::card()), $notice y $canManageSending.
--}}
@php
    $state = $sending['state'];
    $connection = $sending['connection'];
    $States = \App\Modules\Core\Enums\MailConnectionState::class;
    $reason = 'Solo el administrador conecta la cuenta de envío.';
@endphp

<section class="mb-6 rounded-box border border-base-300 bg-base-100" aria-labelledby="sending-title" data-testid="sending-account">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
        <h2 id="sending-title" class="text-lg font-bold">Cuenta de envío</h2>
        <x-core::status-badge :tone="$state->tone()" :label="$state->label()" data-testid="sending-state" />
    </header>

    <div class="space-y-3 px-5 py-4">
        @if ($notice)
            <x-alert :title="$notice['title']" :description="$notice['text']" :icon="$notice['tone'] === 'success' ? 'o-check-circle' : 'o-x-circle'" @class(['alert-soft text-sm', 'alert-success' => $notice['tone'] === 'success', 'alert-error' => $notice['tone'] === 'error']) data-testid="sending-notice" />
        @endif

        @switch ($state)
            @case ($States::Unconfigured)
                <p class="max-w-prose text-sm">
                    Para mandar el correo por Microsoft 365, pon en el .env de Nexus
                    @foreach ($sending['missing'] as $variable)
                        <span class="font-mono font-semibold">{{ $variable }}</span>{{ $loop->last ? '' : ',' }}
                    @endforeach
                    y reinicia Nexus. Salen de la app registrada en Azure.
                </p>
                @break

            @case ($States::Disconnected)
                <p class="max-w-prose text-sm">Ninguna cuenta de Microsoft 365 manda el correo de Nexus. Conéctala con la cuenta que será el remitente, por ejemplo un buzón institucional.</p>
                @break

            @case ($States::Expired)
                <p class="max-w-prose text-sm">Microsoft ya no renueva la conexión de <span class="font-semibold break-all">{{ $connection->account_email }}</span>: reconéctala para que el correo vuelva a salir.</p>
                @break

            @default
                <p class="text-sm">El correo sale como <span class="font-semibold break-all" data-testid="sending-email">{{ $connection->account_email }}</span>.</p>
        @endswitch

        @if ($connection && $state !== $States::Disconnected)
            <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                <dt class="text-neutral">Conectada</dt>
                <dd>
                    <span class="font-mono tabular-nums">{{ $connection->connected_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                    @if ($connection->connectedBy)
                        <span class="text-neutral">· por {{ $connection->connectedBy->name }}</span>
                    @endif
                </dd>
                <dt class="text-neutral">Último envío</dt>
                <dd>
                    @if ($connection->last_used_at)
                        <span class="font-mono tabular-nums">{{ $connection->last_used_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
                    @else
                        <span class="text-neutral">Todavía no sale ningún correo por esta cuenta.</span>
                    @endif
                </dd>
                @if ($connection->last_error)
                    <dt class="text-neutral">Último error</dt>
                    <dd class="break-words" data-testid="sending-error">{{ \App\Modules\Core\Support\RedactsSecrets::redact($connection->last_error) }}</dd>
                @endif
            </dl>
        @endif

        @if (! $sending['active'] && $state !== $States::Unconfigured)
            <x-alert icon="o-information-circle" class="alert-info alert-soft text-sm" data-testid="sending-mailer">
                El correo de Nexus todavía sale por «{{ $sending['mailer'] }}». Para que salga por esta cuenta, pon <span class="font-mono">MAIL_MAILER=microsoft-graph</span> en el .env y reinicia Nexus.
            </x-alert>
        @endif

        @if ($sending['fromMismatch'])
            <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="sending-from">
                MAIL_FROM_ADDRESS dice <span class="font-mono break-all">{{ $sending['fromMismatch'] }}</span>, pero Microsoft manda como la cuenta conectada: el remitente será <span class="font-mono break-all">{{ $connection->account_email }}</span>. Pon la misma cuenta en MAIL_FROM_ADDRESS.
            </x-alert>
        @endif

        <p class="text-sm text-neutral">
            Dirección de regreso para Azure (Redirect URI, tipo Web):
            <span class="font-mono break-all text-base-content" data-testid="sending-redirect-uri">{{ $sending['redirectUri'] }}</span>
        </p>

        <div class="flex flex-wrap gap-2 max-sm:flex-col">
            @if ($state === $States::Unconfigured)
                <span class="tooltip" data-tip="Faltan variables de la app de Azure en el .env.">
                    <x-button label="Conectar con Microsoft" icon="o-link" class="btn-primary max-sm:w-full" disabled />
                </span>
            @elseif (in_array($state, [$States::Disconnected, $States::Expired], true))
                @php($connectLabel = $state === $States::Expired ? 'Reconectar con Microsoft' : 'Conectar con Microsoft')
                @if ($canManageSending)
                    <x-button :label="$connectLabel" icon="o-link" class="btn-primary max-sm:h-11" wire:click="connectMicrosoft" spinner="connectMicrosoft" data-testid="sending-connect" />
                @else
                    <span class="tooltip" data-tip="{{ $reason }}">
                        <x-button :label="$connectLabel" icon="o-link" class="btn-primary max-sm:w-full" disabled />
                    </span>
                @endif
            @endif

            @if ($state === $States::Connected)
                @if ($canManageSending)
                    <x-button label="Enviar correo de prueba" icon="o-paper-airplane" class="btn-primary max-sm:h-11" wire:click="sendTestMail" spinner="sendTestMail" data-testid="sending-test" />
                @else
                    <span class="tooltip" data-tip="{{ $reason }}">
                        <x-button label="Enviar correo de prueba" icon="o-paper-airplane" class="btn-primary max-sm:w-full" disabled />
                    </span>
                @endif
            @endif

            @if ($connection)
                @if ($canManageSending)
                    <x-button
                        label="Desconectar"
                        icon="o-link-slash"
                        class="btn-ghost max-sm:h-11"
                        wire:click="disconnectAccount"
                        x-confirm="Se borra la conexión y el correo de Nexus deja de salir hasta que conectes otra cuenta. Queda en la bitácora."
                        data-confirm-title="¿Desconectar {{ $connection->account_email }}?"
                        data-confirm-button="Desconectar"
                        data-confirm-tone="error"
                        spinner="disconnectAccount"
                        data-testid="sending-disconnect"
                    />
                @else
                    <span class="tooltip" data-tip="{{ $reason }}">
                        <x-button label="Desconectar" icon="o-link-slash" class="btn-ghost max-sm:w-full" disabled />
                    </span>
                @endif
            @endif
        </div>
    </div>
</section>
