{{--
    El resumen de departamentos de la propuesta (academic-load::pages.proposal), el mismo en la retícula y en la
    tabla: por departamento, el estado de su parte (Pendiente, Aceptada, Regresada con el comentario del jefe, o
    «Sin enviar» antes del envío) y los grupos que se le piden. Elegir uno resalta sus materias; elegirlo otra
    vez quita el resalte. Desde xl es una columna a la izquierda; debajo, tarjetas que se acomodan en renglones.
--}}
<nav aria-label="Departamentos" class="flex flex-col gap-2 max-xl:grid max-xl:grid-cols-[repeat(auto-fill,minmax(14rem,1fr))]" data-testid="proposal-departments">
    <h2 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-xl:col-span-full">Departamentos</h2>
    @forelse ($departments as $row)
        @php($code = $row['department']->code)
        <button type="button" wire:click="pickDepartment('{{ $code }}')" wire:key="department-{{ $code }}" aria-pressed="{{ $department === $code ? 'true' : 'false' }}" data-department="{{ $code }}"
            class="flex min-w-0 cursor-pointer flex-col gap-1.5 rounded-box border border-base-300 bg-base-100 p-3 text-left aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary">
            <span class="flex items-center justify-between gap-2">
                <span class="font-mono text-[15px] font-semibold">{{ $code }}</span>
                <span class="font-mono text-sm text-neutral">{{ $row['groups'] }} {{ $row['groups'] === 1 ? 'grupo' : 'grupos' }}</span>
            </span>
            <span class="truncate text-sm" title="{{ $row['department']->name }}">{{ $row['department']->name }}</span>
            @if ($row['review'] === null)
                <span class="text-[12.5px] text-neutral">Sin enviar</span>
            @else
                <x-core::status-badge :tone="$row['review']->status->tone()" :label="$row['review']->status->label()" class="self-start" />
                @if ($row['review']->comment)
                    <span class="text-[12.5px] text-neutral">{{ $row['review']->comment }}</span>
                @endif
            @endif
        </button>
    @empty
        <p class="text-sm text-neutral max-xl:col-span-full">Todavía no se pide ningún grupo.</p>
    @endforelse
</nav>
