{{--
    El modal «Solicitar cambio» de Mi carga (tarea 3.11, maqueta C), dentro de x-modal: el grupo con su horario, qué
    quiere cambiar (horario o salón), qué propone y el motivo, los dos obligatorios. Enviar llama a `sendRequest`;
    lo que rechaza RequestChange (la carga ya no está en Asignación o Ajustes, ya hay una abierta) sale arriba.
--}}
@php($isSchedule = $requestKind !== \App\Modules\Staffing\Enums\ChangeRequestKind::Room->value)
<form wire:submit="sendRequest" class="space-y-5" novalidate>
    <div>
        <h2 id="request-title" class="text-xl font-bold">Solicitar cambio</h2>
        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-neutral">
            <span class="font-medium text-base-content">{{ $group['subject_name'] }}</span>
            <span>Grupo <span class="font-mono font-semibold text-base-content">{{ $group['code'] }}</span></span>
            @foreach ($group['schedule'] as $line)
                <span class="whitespace-nowrap">{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span></span>
            @endforeach
            <span class="badge badge-neutral badge-soft badge-sm font-mono">{{ $group['campus_code'] }}</span>
        </p>
    </div>

    @error('group')
        <x-alert icon="o-exclamation-triangle" class="alert-error alert-soft text-sm" :title="$message" data-testid="request-error" />
    @enderror

    <fieldset class="fieldset py-0">
        <legend class="fieldset-legend mb-1">Qué quieres cambiar</legend>
        <div class="join w-full" role="radiogroup" aria-label="Qué quieres cambiar">
            @foreach (\App\Modules\Staffing\Enums\ChangeRequestKind::cases() as $kind)
                <label @class([
                    'btn join-item flex-1 h-11 md:h-10 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary',
                    'btn-primary' => $requestKind === $kind->value,
                ]) data-testid="request-kind-{{ $kind->value }}">
                    <input type="radio" class="sr-only" name="request-kind" value="{{ $kind->value }}" wire:model.live="requestKind" />
                    <x-icon :name="$kind === \App\Modules\Staffing\Enums\ChangeRequestKind::Schedule ? 'o-clock' : 'o-map-pin'" class="h-4 w-4" />{{ $kind->label() }}
                </label>
            @endforeach
        </div>
        @error('requestKind')
            <p class="mt-1.5 text-sm text-error">{{ $message }}</p>
        @enderror
    </fieldset>

    <x-textarea label="Qué propones" wire:model="proposal" rows="2" maxlength="{{ $maxText }}" data-testid="request-proposal"
        :hint="$isSchedule ? 'Por ejemplo: martes y jueves de 10:00 a 12:00.' : 'Por ejemplo: un laboratorio de cómputo, o el salón LC-5.'" />

    <x-textarea label="Motivo" wire:model="reason" rows="4" maxlength="{{ $maxText }}" hint="Tu jefe de departamento lo lee para decidir." data-testid="request-reason" />

    <div class="flex flex-wrap justify-end gap-2 border-t border-base-300 pt-4">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" wire:click="$set('showRequest', false)" />
        <x-button label="Enviar solicitud" type="submit" icon="o-paper-airplane" class="btn-primary max-md:h-11 max-md:min-h-11" spinner="sendRequest" data-testid="send-request" />
    </div>
</form>
