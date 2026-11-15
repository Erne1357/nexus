{{--
    El bloque «Cuenta» de la ficha (1.22-C1): usuario en Mono, correo institucional (o «Sin correo institucional», y «sin
    confirmar» si no lo está), estado de la cuenta, estado del acceso (AC-04) y último acceso. La de un alumno lo dice:
    su cuenta sigue su estatus (CF-S4). Espera $account.
--}}
@php($person = $account->person)

<section aria-labelledby="account-title" class="rounded-box border border-base-300 bg-base-100 p-4" data-testid="account">
    <h2 id="account-title" class="mb-3 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Cuenta</h2>

    <dl class="grid grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-4 gap-y-2.5 text-sm">
        <dt class="text-neutral">Usuario</dt>
        <dd class="font-mono tabular-nums" data-testid="account-username">{{ $person->username }}</dd>

        <dt class="text-neutral">Correo</dt>
        <dd class="min-w-0 break-words" data-testid="account-email">
            @if ($person->email !== null)
                {{ $person->email }}
                @unless ($account->emailVerified)
                    <span class="text-neutral">· sin confirmar</span>
                @endunless
            @else
                <span class="text-neutral">Sin correo institucional</span>
            @endif
        </dd>

        <dt class="text-neutral">Estado</dt>
        <dd data-testid="account-status">
            @if ($person->is_active)
                <x-core::status-badge tone="success" label="Activa" />
            @else
                <x-core::status-badge tone="neutral" label="Dada de baja" />
            @endif
        </dd>

        <dt class="text-neutral">Acceso</dt>
        <dd><x-core::status-badge :tone="$account->access->tone()" :label="$account->access->label()" data-testid="account-access" /></dd>

        <dt class="text-neutral">Último acceso</dt>
        <dd data-testid="account-last-login">
            @if ($person->last_login_at !== null)
                <span class="font-mono tabular-nums">{{ $person->last_login_at->setTimezone(config('app.timezone'))->format('d/m/Y H:i') }}</span>
            @else
                <span class="text-neutral">Nunca ha entrado</span>
            @endif
        </dd>
    </dl>

    @if ($account->isStudent)
        <p class="mt-3 text-sm text-neutral" data-testid="account-student">Es alumno: su cuenta se activa y se desactiva con su estatus en el SII.</p>
    @endif
</section>
