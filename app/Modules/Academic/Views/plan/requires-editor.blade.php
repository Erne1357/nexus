{{--
    «Pide» en el panel de una materia (academic::plan.subject-panel), editable (tarea 1.8.10; variante A, «Pide» y
    «Agregar requisito»):

    - Cada requisito lleva la clave en Mono, el nombre y el semestre de la otra materia, su tipo en un select
      (`kinds.{id}`: cambiarlo llama a changeKind()) y quitar (removePrerequisite()). Bajo 640 px el select y
      quitar bajan a un segundo renglón, bajo la clave, como en la maqueta.
    - «Agregar requisito» busca otra materia de esta retícula por clave o nombre: `$requisiteOptions` son las que
      coinciden, sin la propia ni las que ya pide (el componente las filtra de la retícula, sin consultar); null
      sin búsqueda o con una ya elegida, que queda escrita en la búsqueda. Debajo, su tipo y «Agregar». Los
      errores de la materia caen bajo la búsqueda.
    - Un ciclo (el error de `kind`, al agregar o al cambiar el tipo) se muestra al final, con su cadena del
      requisito a la materia (`$cyclePath`, cada paso pide al siguiente).

    `$row` es la fila de PlanGridEditorQuery de la materia del panel y `$kindOptions`, los dos tipos.
--}}
<section class="space-y-2.5" data-testid="requires-section">
    <h3 class="flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Pide <span class="font-mono tracking-normal text-base-content">{{ count($row['requires']) }}</span></h3>

    @if ($row['requires'] === [])
        <p class="rounded-box border border-base-300 px-3 py-2.5 text-neutral">No pide ninguna materia.</p>
    @else
        <ul class="divide-y divide-base-300 rounded-box border border-base-300">
            @foreach ($row['requires'] as $requisite)
                <li class="grid grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1.5 py-2 ps-3 pe-2 sm:grid-cols-[5.5rem_minmax(0,1fr)_auto]" data-requisite="{{ $requisite['code'] }}" wire:key="requires-{{ $requisite['prerequisite_id'] }}">
                    <span class="font-mono text-[13.5px] font-semibold">{{ $requisite['code'] }}</span>
                    <span class="min-w-0 leading-snug">
                        {{ $requisite['name'] }}
                        <span class="block text-xs text-neutral">Semestre <span class="font-mono">{{ $requisite['semester'] }}</span></span>
                    </span>
                    <div class="col-span-2 flex items-center gap-1 sm:col-span-1">
                        <div class="w-36">
                            <x-select wire:model.live="kinds.{{ $requisite['prerequisite_id'] }}" :options="$kindOptions" class="select-sm text-sm" aria-label="Tipo del requisito {{ $requisite['code'] }}" omit-error />
                        </div>
                        <x-button
                            icon="o-x-mark"
                            class="btn-ghost btn-sm btn-square"
                            wire:click="removePrerequisite('{{ $requisite['prerequisite_id'] }}')"
                            spinner="removePrerequisite('{{ $requisite['prerequisite_id'] }}')"
                            aria-label="Quitar el requisito {{ $requisite['code'] }}"
                        />
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="space-y-2 pt-1" data-testid="add-requisite">
        <x-input
            label="Agregar requisito"
            hint="Otra materia de esta retícula"
            name="requisiteSearch"
            wire:model.live.debounce.300ms="requisiteSearch"
            placeholder="Clave o nombre"
            icon="o-magnifying-glass"
            autocomplete="off"
            omit-error
        />

        @if ($requisiteOptions !== null)
            @if ($requisiteOptions === [])
                <p class="rounded-box border border-base-300 px-3 py-2.5 text-neutral">Ninguna materia de la retícula coincide.</p>
            @else
                <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" aria-label="Materias de esta retícula" data-testid="requisite-options">
                    @foreach ($requisiteOptions as $option)
                        <li wire:key="requisite-option-{{ $option['subject_id'] }}">
                            <button
                                type="button"
                                class="grid w-full grid-cols-[5.5rem_minmax(0,1fr)_auto] items-start gap-x-3 px-3 py-2 text-left hover:bg-base-200 focus-visible:bg-base-200"
                                data-requisite-option="{{ $option['code'] }}"
                                wire:click="pickRequisite('{{ $option['subject_id'] }}')"
                            >
                                <span class="font-mono text-[13.5px] font-semibold">{{ $option['code'] }}</span>
                                <span class="min-w-0 leading-snug">{{ $option['name'] }}</span>
                                <span class="whitespace-nowrap text-xs text-neutral">Sem. <span class="font-mono">{{ $option['semester'] }}</span></span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif

        @error('prerequisite_subject_id')
            <p class="text-sm text-error">{{ $message }}</p>
        @enderror

        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
            <x-select name="kind" wire:model="kind" :options="$kindOptions" aria-label="Tipo del requisito nuevo" data-testid="new-requisite-kind" omit-error />
            <x-button label="Agregar" icon="o-plus" class="btn-outline" wire:click="addPrerequisite" spinner="addPrerequisite" data-testid="add-requisite-submit" />
        </div>

        @error('kind')
            <x-alert class="alert-error alert-soft text-sm text-base-content" role="alert" data-testid="cycle-error">
                <span class="flex items-start gap-2.5">
                    <x-icon name="o-x-circle" class="mt-0.5 size-5 shrink-0 text-error" />
                    <span class="min-w-0">
                        @if (Str::startsWith($message, 'Cierra un ciclo: '))
                            <span class="font-semibold">Cierra un ciclo:</span> {{ Str::after($message, 'Cierra un ciclo: ') }}
                        @else
                            {{ $message }}
                        @endif

                        @if ($cyclePath)
                            <ol class="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1" aria-label="Cadena: cada materia pide a la siguiente" data-testid="cycle-path">
                                @foreach ($cyclePath as $code)
                                    <li class="flex items-center gap-1.5">
                                        @unless ($loop->first)
                                            <x-icon name="o-arrow-right" class="size-3.5 text-neutral" />
                                        @endunless
                                        <span class="rounded border border-base-300 bg-base-100 px-1.5 font-mono text-[13px] font-semibold text-base-content">{{ $code }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </span>
                </span>
            </x-alert>
        @enderror
    </div>
</section>
