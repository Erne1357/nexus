{{--
    El resumen de departamentos de la propuesta (academic-load::pages.proposal), el mismo en la retícula y en la
    tabla: por departamento, el estado de su parte (Pendiente, Aceptada, Regresada o «Sin enviar» antes del envío) y
    los grupos que se le piden. Elegir uno marca sus materias (en la retícula, como una cadena resaltada) y lo pinta
    sólido en `primary` con una × a la vista; elegirlo otra vez lo quita.

    Desde 1700 px es un riel de 15 rem a la izquierda, con el nombre del departamento y el comentario del jefe. Debajo
    es una franja de chips sobre la vista, tras la etiqueta «Departamentos» (clave, grupos y estado; el nombre queda
    para lectores de pantalla): así la retícula usa todo el ancho y los nueve semestres caben desde 1366 px. En
    celular la franja se desplaza de lado, lleva a la vista el departamento elegido al abrir la página y con «Ver», y
    la etiqueta queda para lectores de pantalla.
--}}
<nav aria-label="Departamentos" class="flex gap-2 max-[1699px]:flex-wrap max-md:flex-nowrap max-md:overflow-x-auto max-md:pb-1 min-[1700px]:flex-col" data-testid="proposal-departments"
    x-data="{
        reveal() {
            const pill = $el.querySelector('[aria-pressed=&quot;true&quot;]');
            if (! pill || $el.scrollWidth <= $el.clientWidth) { return; }
            const own = pill.getBoundingClientRect();
            const strip = $el.getBoundingClientRect();
            if (own.right > strip.right) { $el.scrollLeft += own.right - strip.right + 2; } else if (own.left < strip.left) { $el.scrollLeft -= strip.left - own.left + 2; }
        },
    }"
    x-init="reveal(); document.fonts.ready.then(() => reveal())" x-on:proposal-focus.window="$nextTick(() => reveal())">
    <h2 class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral max-md:sr-only md:max-[1699px]:me-1 md:max-[1699px]:self-center">Departamentos</h2>
    @forelse ($departments as $row)
        @php($code = $row['department']->code)
        <button type="button" wire:click="pickDepartment('{{ $code }}')" wire:key="department-{{ $code }}" aria-pressed="{{ $department === $code ? 'true' : 'false' }}" data-department="{{ $code }}" data-testid="department-{{ $code }}"
            class="group relative flex min-w-0 cursor-pointer rounded-box border border-base-300 bg-base-100 text-left aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:text-primary-content max-[1699px]:shrink-0 max-[1699px]:flex-row max-[1699px]:items-center max-[1699px]:gap-2.5 max-[1699px]:px-2.5 max-[1699px]:py-1.5 min-[1700px]:flex-col min-[1700px]:gap-1.5 min-[1700px]:p-3">
            <span class="flex items-center justify-between gap-2 min-[1700px]:group-aria-pressed:pe-6">
                <span class="font-mono text-[15px] font-semibold">{{ $code }}</span>
                <span class="font-mono text-sm text-neutral group-aria-pressed:text-primary-content/80">{{ $row['groups'] }} {{ $row['groups'] === 1 ? 'grupo' : 'grupos' }}</span>
            </span>
            <span class="truncate text-sm max-[1699px]:sr-only" title="{{ $row['department']->name }}">{{ $row['department']->name }}</span>
            @if ($row['review'] === null)
                <span class="text-sm text-neutral group-aria-pressed:text-primary-content/80">Sin enviar</span>
            @else
                <x-core::status-badge :tone="$row['review']->status->tone()" :label="$row['review']->status->label()" class="self-start" />
                @if ($row['review']->comment)
                    <span class="text-sm text-neutral group-aria-pressed:text-primary-content/80 max-[1699px]:hidden">{{ $row['review']->comment }}</span>
                @endif
            @endif
            {{-- La × va en un span: el icono de MaryUI trae `inline`, que le gana a `hidden`. --}}
            <span class="hidden shrink-0 group-aria-pressed:inline-flex max-[1699px]:-ms-0.5 min-[1700px]:absolute min-[1700px]:top-3 min-[1700px]:right-3" aria-hidden="true"><x-icon name="o-x-mark" class="w-4 h-4" /></span>
        </button>
    @empty
        <p class="text-sm text-neutral">Todavía no se pide ningún grupo.</p>
    @endforelse
</nav>
