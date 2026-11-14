{{--
    «Marcar como resuelta» de una observación, igual en la revisión y en el tablero de avance (CV10): abre en su lugar la
    nota opcional de lo que se hizo, que le llega en la campana al jefe que la puso, y «Resolver» llama a
    resolveFlag(id, nota) del componente. La nota vive en el navegador hasta resolver. `$flag` es la observación;
    `$testid`, el data-testid del botón; `$buttonClass`, su clase.
--}}
<div x-data="{ resolving: false, note: '' }" class="space-y-2" data-testid="resolve-{{ $flag->id }}">
    <div x-show="! resolving" class="flex justify-end">
        <x-button label="Marcar como resuelta" icon="o-check" :class="$buttonClass ?? 'btn-sm'" @click="resolving = true; $nextTick(() => $refs.note.focus())" data-testid="{{ $testid }}" />
    </div>
    <div x-show="resolving" x-cloak class="space-y-2">
        <x-textarea id="note-{{ $flag->id }}" label="Qué se hizo" hint="Opcional. Se lo avisamos a quien la puso." x-ref="note" x-model="note" rows="2" maxlength="1000" omit-error data-testid="resolve-note-{{ $flag->id }}" />
        <div class="flex justify-end gap-2">
            <x-button label="Cancelar" class="btn-ghost btn-sm" @click="resolving = false; note = ''" />
            <x-button label="Resolver" icon="o-check" class="btn-primary btn-sm" @click="$wire.resolveFlag('{{ $flag->id }}', note)" wire:loading.attr="disabled" wire:target="resolveFlag" data-testid="resolve-submit-{{ $flag->id }}" />
        </div>
    </div>
</div>
