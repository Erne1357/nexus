{{--
    La confirmación de aceptar una parte (academic-load::pages.inbox), dentro de x-modal: los totales de lo que se
    acepta. Confirmar llama a `accept`.
--}}
<div class="space-y-4">
    <h2 id="accept-title" class="text-xl font-bold">Aceptar la parte de {{ $target['career']->code }}</h2>
    <dl class="grid grid-cols-3 gap-3 rounded-box border border-base-300 px-4 py-3">
        @foreach ([['Materias', $target['subjects']], ['Grupos', $target['groups']], ['Esperados', $target['expected']]] as [$label, $value])
            <div>
                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                <dd class="font-mono text-xl font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="text-sm text-neutral">Lo aceptado ya no cambia.</p>
    <div class="flex justify-end gap-2">
        <x-button label="Cancelar" class="btn-ghost" wire:click="$set('showAccept', false)" />
        <x-button label="Aceptar" icon="o-check" class="btn-primary" wire:click="accept" spinner="accept" data-testid="confirm-accept" />
    </div>
</div>
