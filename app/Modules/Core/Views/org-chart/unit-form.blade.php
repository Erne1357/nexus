{{--
    El formulario del panel del Organigrama (core::components.org-unit-panel), en un `x-modal`:
    - «Nueva unidad»: nombre, clave, tipo (sin Dirección ni Departamento académico) y «Depende de», con la madre ya
      elegida;
    - «Renombrar unidad»: nombre, clave y tipo; el de la Dirección y el de Subdirección Académica no cambian;
    - «Mover unidad»: «Depende de», sin la unidad ni las que cuelgan de ella.
    Los errores de las Actions salen junto a su campo; los de la unidad, arriba.
--}}
@php
    $titles = ['create' => 'Nueva unidad', 'edit' => 'Renombrar unidad', 'move' => 'Mover unidad'];
    $subtitles = [
        'create' => 'Queda al final de las hijas de la unidad de la que depende.',
        'edit' => 'Cambia el nombre, la clave o el tipo; el lugar se cambia con «Mover».',
        'move' => $detail->unit->name,
    ];
@endphp

<x-modal wire:model="showForm" :title="$titles[$mode] ?? ''" :subtitle="$subtitles[$mode] ?? ''" box-class="max-w-xl" separator>
    <x-form wire:submit="{{ $mode === 'move' ? 'move' : 'save' }}" no-separator>
        @error('unit')
            <x-alert :title="$message" icon="o-x-circle" class="alert-error alert-soft" />
        @enderror

        @if (in_array($mode, ['create', 'edit'], true))
            <x-input label="Nombre" name="name" wire:model="name" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Clave" name="code" wire:model="code" class="font-mono" />
                <div>
                    <x-select label="Tipo" name="kind" wire:model="kind" :options="$kinds" :disabled="$fixedKind !== null" />
                    @if ($fixedKind !== null)
                        <p class="mt-1 text-sm text-neutral" data-testid="unit-kind-fixed">{{ $fixedKind }}</p>
                    @endif
                </div>
            </div>
        @endif

        @if (in_array($mode, ['create', 'move'], true))
            <x-select label="Depende de" name="parent" wire:model="parent" :options="$parentOptions" />
        @endif

        @if ($mode === 'move')
            <p class="text-sm text-neutral">Al moverla, sus unidades hijas se van con ella. No puede depender de sí misma ni de una de sus hijas.</p>
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" wire:click="closeForm" />
            <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" :spinner="$mode === 'move' ? 'move' : 'save'" data-testid="unit-form-submit" />
        </x-slot:actions>
    </x-form>
</x-modal>
