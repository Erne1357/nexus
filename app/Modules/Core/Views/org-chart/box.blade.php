{{--
    Una caja del Organigrama (core::pages.org-chart), de un OrgChartNode: el tipo en versalitas (con candado si es un
    departamento académico, que se edita en Administración › Departamentos), el nombre, el titular o lo que falta («Sin jefe vigente»,
    «Sin titular vigente»; la raíz, «Sin titular en Nexus»), la clave en Mono con las personas de la unidad y sus hijas
    y «Inactiva» si aplica. La raíz lleva filete superior `primary`, el primer nivel uno más delgado y la académica
    borde punteado sobre `base-200`. Es un botón: elegirla, atenuarla al buscar y el marco de elegida los hace el
    Alpine de la página.

    @var \App\Modules\Core\Queries\OrgChartNode $node
    @var int $level
--}}
<button
    type="button"
    data-testid="org-unit-{{ $node->code }}"
    data-search="{{ $node->search() }}"
    x-on:click="pick('{{ $node->id }}')"
    x-bind:class="{ 'ring-2 ring-primary': selected === '{{ $node->id }}', 'opacity-35': ! matches($el.dataset.search) }"
    x-bind:aria-current="selected === '{{ $node->id }}' ? 'true' : null"
    @class([
        'grid w-full cursor-pointer grid-cols-[minmax(0,1fr)] gap-0.5 rounded-box border px-2.5 py-2 text-left transition-opacity hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
        'border-base-300 bg-base-100' => ! $node->locked,
        'border-dashed border-base-300 bg-base-200' => $node->locked,
        'border-t-4 border-t-primary' => $level === 0,
        'border-t-[3px] border-t-primary/60' => $level === 1,
    ])
>
    <span class="flex items-center gap-1.5 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">
        {{ $node->kind->label() }}
        @if ($node->locked)
            <x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span class="sr-only">(se edita en Administración › Departamentos)</span>
        @endif
    </span>

    <span @class(['text-sm leading-snug font-bold', 'text-neutral' => ! $node->active])>{{ $node->name }}</span>

    @if ($node->titular !== null)
        <span class="text-[13px] leading-snug">{{ $node->titular }}</span>
    @elseif ($node->missingTitular)
        <span class="flex items-center gap-1 text-[13px] leading-snug font-medium text-warning">
            <x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $node->locked ? 'Sin jefe vigente' : 'Sin titular vigente' }}
        </span>
    @elseif ($level === 0)
        <span class="text-[13px] leading-snug text-neutral">Sin titular en Nexus</span>
    @endif

    <span class="mt-0.5 flex flex-wrap items-center justify-between gap-x-2 text-[12.5px] text-neutral">
        <span class="min-w-0 truncate font-mono" title="{{ $node->code }}">{{ $node->code }}</span>
        <span class="ms-auto inline-flex shrink-0 items-center gap-1 font-mono tabular-nums">
            <x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />{{ $node->people }}<span class="sr-only"> personas</span>
        </span>
    </span>

    @unless ($node->active)
        <x-core::status-badge tone="neutral" label="Inactiva" class="mt-1" />
    @endunless
</button>
