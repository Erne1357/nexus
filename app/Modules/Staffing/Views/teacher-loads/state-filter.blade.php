{{--
    «Todos · Exceden · Sin carga · Libres · Completa · Honorarios», con cuántos docentes hay de cada estado; el elegido
    en primary. Mismo marcado que el filtro de estado de Asignación; en celular se desplaza dentro de su caja.
--}}
<div class="max-w-full overflow-x-auto" data-testid="state-filter">
    <div role="group" aria-label="Estado de la carga" class="join w-max">
        @foreach ($stateOptions as $option => $optionLabel)
            <button type="button" wire:click="$set('state', '{{ $option }}')" aria-pressed="{{ $state === $option ? 'true' : 'false' }}" data-testid="state-{{ $option }}"
                @class(['btn join-item flex-nowrap gap-1.5 whitespace-nowrap px-3 max-md:h-11', 'btn-primary' => $state === $option])>{{ $optionLabel }} <span class="font-mono tabular-nums opacity-85">{{ $stateCounts[$option] }}</span></button>
        @endforeach
    </div>
</div>
