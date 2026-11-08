{{--
    Los campos de una solicitud de cambio, iguales en el modal de «Mi carga» (el docente) y en el de «Solicitudes de
    cambio» (el coordinador): «Qué quieres cambiar» (horario o salón, `requestKind`), «Qué propones» (`proposal`, con un
    ejemplo según el tipo en el placeholder) y «Motivo» (`reason`). Lo que rechaza RequestChange sobre el grupo sale
    arriba como alerta.
--}}
@use('App\Modules\Staffing\Enums\ChangeRequestKind')
@php($isSchedule = $requestKind !== ChangeRequestKind::Room->value)

@error('group')
    <x-alert icon="o-exclamation-triangle" class="alert-error alert-soft text-sm" :title="$message" data-testid="request-error" />
@enderror

<fieldset class="fieldset py-0">
    <legend class="fieldset-legend mb-1">Qué quieres cambiar</legend>
    <div class="join w-full" role="radiogroup" aria-label="Qué quieres cambiar">
        @foreach (ChangeRequestKind::cases() as $kind)
            <label @class([
                'btn join-item flex-1 h-11 md:h-10 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary',
                'btn-primary' => $requestKind === $kind->value,
            ]) data-testid="request-kind-{{ $kind->value }}">
                <input type="radio" class="sr-only" name="request-kind" value="{{ $kind->value }}" wire:model.live="requestKind" />
                <x-icon :name="$kind === ChangeRequestKind::Schedule ? 'o-calendar-days' : 'o-map-pin'" class="h-4 w-4" aria-hidden="true" />{{ $kind->label() }}
            </label>
        @endforeach
    </div>
    @error('requestKind')
        <p class="mt-1.5 text-sm text-error">{{ $message }}</p>
    @enderror
</fieldset>

<x-textarea label="Qué propones" wire:model="proposal" rows="2" maxlength="{{ $maxText }}" data-testid="request-proposal"
    :placeholder="$isSchedule ? 'Por ejemplo: martes y jueves de 10:00 a 12:00.' : 'Por ejemplo: un laboratorio de cómputo, o el salón LC-5.'" />

<x-textarea label="Motivo" wire:model="reason" rows="4" maxlength="{{ $maxText }}" data-testid="request-reason" />
