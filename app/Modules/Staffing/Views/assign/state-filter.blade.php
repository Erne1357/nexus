{{--
    «Todos · Sin docente · Con docente», con cuántos grupos hay de cada uno en el departamento; el elegido en primary.
--}}
<div role="group" aria-label="Docente" class="join w-full" data-testid="state-filter">
    @foreach (['todos' => 'Todos', 'sin' => 'Sin docente', 'con' => 'Con docente'] as $option => $optionLabel)
        <button type="button" wire:click="$set('state', '{{ $option }}')" aria-pressed="{{ $state === $option ? 'true' : 'false' }}" data-testid="state-{{ $option }}"
            @class(['btn join-item flex-1 gap-1.5 px-3 max-md:h-11', 'btn-primary' => $state === $option])>{{ $optionLabel }} <span class="font-mono text-[13px] tabular-nums opacity-85">{{ $counts[$option] }}</span></button>
    @endforeach
</div>
