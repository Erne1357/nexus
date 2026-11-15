{{--
    «Reenviar» de una invitación pendiente en «Correos y colas» (CF-07), en la tabla y en los renglones apilados de
    celular. Confirma con el correo al que va y avisa si el usuario tiene un puesto. Solo la tabla lleva el data-testid.
    Espera $row y $inTable.
--}}
@can('resendInvitation', \App\Modules\Core\Models\MailLog::class)
    <x-button
        label="Reenviar"
        icon="o-paper-airplane"
        wire:click="resend('{{ $row['user']->id }}')"
        x-confirm="Le llegan a {{ $row['user']->email }} su usuario y un enlace nuevo para activar su acceso; el anterior deja de servir.{{ $row['staff'] ? ' Tiene un puesto: el enlace le dará acceso con él.' : '' }}"
        data-confirm-title="¿Reenviar la invitación a {{ $row['user']->username }}?"
        data-confirm-button="Reenviar"
        data-confirm-tone="{{ $row['staff'] ? 'warning' : 'primary' }}"
        spinner="resend('{{ $row['user']->id }}')"
        :class="$inTable ? 'btn-ghost btn-sm' : 'btn-ghost btn-sm -ms-3 max-md:h-11'"
        :data-testid="$inTable ? 'resend-'.$row['user']->username : null"
    />
@else
    <span class="tooltip tooltip-left" data-tip="Solo el administrador reenvía invitaciones.">
        <x-button label="Reenviar" icon="o-paper-airplane" class="btn-ghost btn-sm" disabled />
    </span>
@endcan
