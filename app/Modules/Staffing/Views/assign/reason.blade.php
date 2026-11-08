{{--
    El motivo de asignar fuera del primer lugar del ranking (HC-16), en el renglón de donde se pidió: el lugar que ocupa
    el docente (o que no está en el ranking), el motivo, los errores de AssignTeacher y «Asignar con motivo».
--}}
<div class="grid gap-2.5 rounded-box border border-warning/40 bg-warning/10 p-3" data-testid="reason-box" role="group" aria-label="Motivo para asignar a {{ $pending['name'] }}">
    <p class="flex items-center gap-1.5 text-sm" id="reason-place-{{ $pending['group'] }}">
        <x-icon name="o-arrow-trending-down" class="h-4 w-4 shrink-0 text-warning" aria-hidden="true" />
        @if ($pending['position'] === null)
            <span>No está en el ranking del grupo.</span>
        @else
            <span>Ocupa el lugar <span class="font-mono font-semibold tabular-nums">{{ $pending['position'] }}</span> de <span class="font-mono font-semibold tabular-nums">{{ $pending['size'] }}</span> del ranking.</span>
        @endif
    </p>
    <x-textarea :label="'Motivo para asignar a '.$pending['name']" wire:model="reason" rows="2" data-testid="reason-input" aria-describedby="reason-place-{{ $pending['group'] }}" />
    @error('assignment')
        <p class="flex items-start gap-2 text-sm text-error" role="alert"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $message }}</p>
    @enderror
    <div class="flex flex-wrap justify-end gap-2">
        <button type="button" wire:click="cancelReason" class="btn btn-ghost btn-sm max-md:h-11">Cancelar</button>
        <x-button label="Asignar con motivo" icon="o-check" class="btn-primary btn-sm max-md:h-11" wire:click="confirmAssign" spinner="confirmAssign" data-testid="confirm-assign" />
    </div>
</div>
