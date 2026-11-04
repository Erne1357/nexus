{{--
    La confirmación de aceptar una parte (academic-load::pages.inbox), dentro de x-modal: los totales de lo que se
    acepta y, destacado, que lo aceptado ya no cambia. Confirmar llama a `accept`.
--}}
<div class="space-y-4">
    <h2 id="accept-title" class="text-xl font-bold">Aceptar la parte de {{ $target['career']->code }}</h2>
    <dl class="grid grid-cols-2 gap-3 rounded-box border border-base-300 px-4 py-3">
        @foreach ([['Materias', $target['subjects']], ['Grupos', $target['groups']]] as [$label, $value])
            <div>
                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                <dd class="font-mono text-xl font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
    <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm font-semibold" title="Lo aceptado ya no cambia." data-testid="accept-warning" />
    @if ($selfApproval)
        @include('core::self-approval', ['title' => 'Tú enviaste esta propuesta.'])
    @endif
    <div class="flex justify-end gap-2">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" wire:click="$set('showAccept', false)" />
        <x-button label="Aceptar" icon="o-check" class="btn-primary max-md:h-11 max-md:min-h-11" wire:click="accept" spinner="accept" data-testid="confirm-accept" />
    </div>
</div>
