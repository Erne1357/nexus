{{--
    El formulario «Nombrar» del panel del Organigrama (core::components.org-unit-panel), en un `x-modal`: la persona (se
    busca por nombre o número y se elige) y los campos de «Personas y puestos» (core::people.appoint-fields) con la unidad
    del panel fija. Un error de `unit` sale bajo la unidad fija; el de `appointUserId`, bajo la persona.
--}}
<x-modal wire:model="showAppoint" :title="'Nombrar a alguien en '.$detail->unit->name" subtitle="El formulario pide solo lo que el puesto necesita." box-class="max-w-xl" separator>
    <x-form wire:submit="appoint" no-separator>
        <div class="grid gap-2" data-testid="appoint-person">
            @if ($person === null)
                <x-input label="Persona" name="findPerson" wire:model.live.debounce.300ms="findPerson" placeholder="Buscar persona: nombre o número" icon="o-magnifying-glass" clearable data-testid="appoint-find" />

                @if ($found->isNotEmpty())
                    <ul class="flex flex-wrap gap-2" data-testid="appoint-found" aria-label="Personas encontradas">
                        @foreach ($found as $other)
                            <li wire:key="appoint-found-{{ $other->id }}">
                                <button type="button" wire:click="choosePerson('{{ $other->id }}')" class="btn btn-outline btn-sm max-md:h-11" data-testid="appoint-pick-{{ $other->username }}">
                                    {{ $other->name }} <span class="font-mono tabular-nums text-neutral">{{ $other->username }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif (trim($findPerson) !== '')
                    <p class="text-sm text-neutral" data-testid="appoint-found-none">Nadie coincide con «{{ $findPerson }}».</p>
                @endif
            @else
                <span class="text-sm font-medium">Persona</span>
                <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-base-300 bg-base-200/60 px-3 py-2">
                    <p data-testid="appoint-chosen">{{ $person->name }} · <span class="font-mono tabular-nums">{{ $person->username }}</span></p>
                    <x-button label="Cambiar" wire:click="$set('appointUserId', null)" class="btn-outline btn-sm max-md:h-11" />
                </div>
            @endif

            @error('appointUserId')
                <p class="text-sm text-error" data-testid="appoint-person-error">{{ $message }}</p>
            @enderror
        </div>

        @include('core::people.appoint-fields', ['roleNote' => 'Aquí se nombran los puestos que van sobre esta unidad.', 'lockedUnit' => $detail->unit])

        <x-slot:actions>
            <x-button label="Cancelar" @click="$wire.showAppoint = false" />
            <x-button label="Nombrar" type="submit" icon="o-plus" class="btn-primary" spinner="appoint" data-testid="appoint-submit" />
        </x-slot:actions>
    </x-form>
</x-modal>
