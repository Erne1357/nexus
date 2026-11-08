{{--
    «Cupos por carrera» del cajón del grupo (maqueta `.impeccable/mocks/horarios/cajones.html`, estado `#grupo`):
    - La barra de lugares repartidos (`meter`) con los propuestos aparte en rayas de advertencia, y «N de M lugares
      repartidos» en Mono; «+N propuestos · no cuentan» en insignia de advertencia.
    - El error HC-07 cuando la suma pasa la capacidad.
    - Un renglón por carrera: clave en Mono, nombre, lugares, quitar y, debajo, su estado (Dueña, Aceptado,
      Propuesto con su motivo). En celular el renglón se apila (clave y nombre, luego lugares).
    - «Agregar carrera» y «Guardar cupos». Sin permiso, lo mismo en solo lectura y con su motivo.
--}}
<section aria-labelledby="quotas-title-{{ $groupId }}" class="grid gap-3" data-testid="group-quotas">
    <h3 id="quotas-title-{{ $groupId }}" class="text-base font-semibold">Cupos por carrera</h3>

    @php($shared = min(100, $capacity > 0 ? $counted / $capacity * 100 : 0))
    @php($pending = min(100 - $shared, $capacity > 0 ? $proposed / $capacity * 100 : 0))
    <div class="flex h-2.5 overflow-hidden rounded-full border border-base-300 bg-base-200" role="meter" aria-valuemin="0" aria-valuemax="{{ $capacity }}" aria-valuenow="{{ $counted }}" aria-label="Lugares repartidos">
        <i class="block h-full {{ $over > 0 ? 'bg-error' : 'bg-primary' }}" style="width: {{ $shared }}%"></i>
        <i class="block h-full bg-warning/70" style="width: {{ $pending }}%"></i>
    </div>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <b class="font-mono font-semibold" data-testid="quotas-sum">{{ $counted }} de {{ $capacity }} lugares repartidos</b>
        @if ($proposed > 0)
            <x-core::status-badge tone="warning" :label="'+'.$proposed.' propuestos · no cuentan'" />
        @endif
    </div>

    @if ($over > 0)
        <div role="alert" class="flex items-start gap-2 rounded-field border border-error/40 bg-error/10 px-3 py-2 text-sm text-error" data-testid="quotas-over">
            <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="text-base-content"><b class="font-mono">HC-07</b> La suma de lugares por carrera no puede pasar la capacidad del grupo (<span class="font-mono">{{ $capacity }}</span>). Quita <span class="font-mono">{{ $over }}</span> lugares.</span>
        </div>
    @elseif ($errors->has('quotas'))
        <div role="alert" class="flex items-start gap-2 rounded-field border border-error/40 bg-error/10 px-3 py-2 text-sm text-error" data-testid="quotas-error">
            <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <span class="text-base-content">{{ $errors->first('quotas') }}</span>
        </div>
    @endif

    <div class="grid gap-2" data-testid="quota-rows">
        @foreach ($rows as $row)
            @php($proposedRow = $row['state'] === 'proposed')
            <div wire:key="quota-{{ $row['career_id'] }}" data-quota="{{ $row['code'] }}" data-state="{{ $row['state'] }}"
                class="grid grid-cols-[3.25rem_1fr_auto_2.5rem] items-center gap-x-2.5 gap-y-1 rounded-box border px-3 py-2.5 max-sm:grid-cols-[3.25rem_1fr_2.5rem] {{ $proposedRow ? 'border-dashed border-warning/50 bg-warning/8' : 'border-base-300 bg-base-100' }}">
                <span class="font-mono text-[15px] font-semibold">{{ $row['code'] }}</span>
                <span class="min-w-0 leading-snug">{{ $row['name'] }}</span>
                <div class="max-sm:order-last max-sm:col-span-3 max-sm:col-start-1">
                    @if ($canManage)
                        <x-input wire:model.live.debounce.400ms="quotas.{{ $row['career_id'] }}" type="number" min="1" max="{{ $maxSeats }}" inputmode="numeric" omit-error
                            class="w-20 text-right font-mono" aria-label="Lugares de {{ $row['code'] }}" />
                    @else
                        <span class="font-mono font-semibold" aria-label="Lugares de {{ $row['code'] }}">{{ $quotas[$row['career_id']] ?? $row['seats'] }}</span>
                    @endif
                </div>
                @if ($row['state'] === 'owner')
                    <span class="grid size-10 place-items-center text-base-content/30"><x-icon name="o-lock-closed" class="h-4 w-4" aria-hidden="true" /><span class="sr-only">La carrera dueña no se quita</span></span>
                @else
                    <button type="button" wire:click="removeCareer('{{ $row['career_id'] }}')" @disabled(! $canManage)
                        class="btn btn-ghost btn-sm size-10 p-0 text-base-content/60" aria-label="Quitar {{ $row['code'] }}">
                        <x-icon name="o-x-mark" class="h-4 w-4" aria-hidden="true" />
                    </button>
                @endif
                <div class="col-start-2 col-end-[-1] flex flex-wrap items-center gap-x-2 gap-y-1">
                    @if ($row['state'] === 'owner')
                        <x-core::status-badge tone="neutral" label="Dueña" icon="o-lock-closed" />
                        <small class="text-neutral">No se quita</small>
                    @elseif ($row['state'] === 'accepted')
                        <x-core::status-badge tone="success" label="Aceptado" />
                    @elseif ($proposedRow)
                        <x-core::status-badge tone="warning" label="Propuesto" />
                        <small>Espera al jefe de {{ $row['decides']['name'] }}. Todavía no cuenta.</small>
                    @else
                        <x-core::status-badge tone="draft" label="Sin guardar" />
                    @endif
                </div>
                @foreach ($errors->get('quotas.'.$row['career_id']) as $message)
                    <p class="col-start-2 col-end-[-1] text-sm text-error" role="alert">{{ $message }}</p>
                @endforeach
            </div>
        @endforeach
    </div>

    <div class="grid gap-2 border-t border-base-300 pt-3" data-testid="quota-add">
        <b>Agregar carrera</b>
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-44 flex-1">
                <x-select label="Carrera" wire:model.live="newCareer" :options="$options" placeholder="Elige una carrera" :disabled="! $canManage || ! $hasAddable" omit-error />
            </div>
            <div>
                <x-input label="Lugares" wire:model.live="newSeats" type="number" min="1" max="{{ $maxSeats }}" inputmode="numeric" class="w-24 text-right font-mono" :disabled="! $canManage" omit-error />
            </div>
        </div>
        @error('newCareer') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
        @error('newSeats') <p class="text-sm text-error" role="alert">{{ $message }}</p> @enderror
        @if ($selected && $selected['needs_agreement'])
            <p class="flex items-start gap-2 text-sm"><x-icon name="o-information-circle" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />Se crea un acuerdo: el cupo cuenta cuando el jefe de {{ $selected['department']['name'] }} lo acepta.</p>
        @endif
        <div>
            <x-button wire:click="addCareer" icon="o-plus" class="btn-outline" :disabled="! $canManage || ! $hasAddable" spinner="addCareer"
                :label="$selected && $selected['needs_agreement'] ? 'Proponer a '.$selected['department']['name'] : 'Agregar carrera'" />
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-3 border-t border-base-300 pt-3">
        @if ($reason)
            <span class="flex items-center gap-1.5 text-sm text-neutral" data-testid="quotas-reason"><x-icon name="o-lock-closed" class="h-4 w-4" aria-hidden="true" />{{ $reason }}</span>
        @elseif ($over > 0)
            <span class="flex items-center gap-1.5 text-sm text-error"><x-icon name="o-x-circle" class="h-4 w-4" aria-hidden="true" />Corrige los lugares para guardar.</span>
        @endif
        <x-button wire:click="save" class="btn-primary" label="Guardar cupos" spinner="save" :disabled="! $canManage || $over > 0" />
    </div>
</section>
