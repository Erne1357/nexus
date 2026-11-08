{{--
    Filtro segmentado de las pantallas de Staffing, con el mismo marcado que «Todos · Sin docente · Con docente» de la
    asignación (assign/state-filter): un `join` de botones de 40 px (44 en celular), cada uno con su cifra en Mono y
    `aria-pressed`, el elegido en primary. Si no cabe, se desplaza de lado dentro de su caja, nunca la página.
    Espera $label (nombre del grupo), $property (la propiedad que fija), $options (valor => texto), $current, $counts
    (valor => cifra) y $testid (prefijo: `<testid>-<valor>`, o `<testid>-todas` para el valor vacío).
--}}
<div class="min-w-0 max-w-full overflow-x-auto overscroll-x-contain">
    <div role="group" aria-label="{{ $label }}" class="join" data-testid="{{ $testid }}">
        @foreach ($options as $value => $optionLabel)
            <button type="button" wire:click="$set('{{ $property }}', '{{ $value }}')" aria-pressed="{{ $current === (string) $value ? 'true' : 'false' }}" data-testid="{{ $testid }}-{{ $value === '' ? 'todas' : $value }}"
                @class(['btn join-item flex-none flex-nowrap gap-1.5 whitespace-nowrap px-3 max-md:h-11', 'btn-primary' => $current === (string) $value])>{{ $optionLabel }} <span class="font-mono text-sm tabular-nums opacity-85">{{ $counts[$value] }}</span></button>
        @endforeach
    </div>
</div>
