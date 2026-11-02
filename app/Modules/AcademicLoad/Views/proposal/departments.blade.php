{{--
    El resumen de departamentos de la propuesta (academic-load::pages.proposal), el mismo en la retícula y en la
    tabla: por departamento, el estado de su parte (Pendiente, Aceptada, Regresada o «Sin enviar» antes del envío) y
    los grupos que se le piden. Elegir uno resalta sus materias; elegirlo otra vez quita el resalte.

    Desde 1700 px es un riel de 15 rem a la izquierda, con el nombre del departamento y el comentario del jefe. Debajo
    es una franja de chips sobre la vista (clave, grupos y estado; el nombre queda para lectores de pantalla): así la
    retícula usa todo el ancho y los nueve semestres caben desde 1366 px. En celular la franja se desplaza de lado.
--}}
<nav aria-label="Departamentos" class="flex gap-2 max-[1699px]:flex-wrap max-md:flex-nowrap max-md:overflow-x-auto max-md:pb-1 min-[1700px]:flex-col" data-testid="proposal-departments">
    <h2 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-[1699px]:sr-only">Departamentos</h2>
    @forelse ($departments as $row)
        @php($code = $row['department']->code)
        <button type="button" wire:click="pickDepartment('{{ $code }}')" wire:key="department-{{ $code }}" aria-pressed="{{ $department === $code ? 'true' : 'false' }}" data-department="{{ $code }}"
            class="flex min-w-0 cursor-pointer rounded-box border border-base-300 bg-base-100 text-left aria-pressed:border-primary aria-pressed:ring-1 aria-pressed:ring-primary max-[1699px]:shrink-0 max-[1699px]:flex-row max-[1699px]:items-center max-[1699px]:gap-2.5 max-[1699px]:px-2.5 max-[1699px]:py-1.5 min-[1700px]:flex-col min-[1700px]:gap-1.5 min-[1700px]:p-3">
            <span class="flex items-center justify-between gap-2">
                <span class="font-mono text-[15px] font-semibold">{{ $code }}</span>
                <span class="font-mono text-sm text-neutral">{{ $row['groups'] }} {{ $row['groups'] === 1 ? 'grupo' : 'grupos' }}</span>
            </span>
            <span class="truncate text-sm max-[1699px]:sr-only" title="{{ $row['department']->name }}">{{ $row['department']->name }}</span>
            @if ($row['review'] === null)
                <span class="text-sm text-neutral">Sin enviar</span>
            @else
                <x-core::status-badge :tone="$row['review']->status->tone()" :label="$row['review']->status->label()" class="self-start" />
                @if ($row['review']->comment)
                    <span class="text-sm text-neutral max-[1699px]:hidden">{{ $row['review']->comment }}</span>
                @endif
            @endif
        </button>
    @empty
        <p class="text-sm text-neutral">Todavía no se pide ningún grupo.</p>
    @endforelse
</nav>
