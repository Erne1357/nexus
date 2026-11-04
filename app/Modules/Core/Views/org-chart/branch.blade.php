{{--
    Las hijas de una caja del Organigrama, del segundo nivel para abajo (core::pages.org-chart), colgadas de un riel:
    la lista lleva el filete a la izquierda y cada renglón su codo; el último corta el riel bajo su codo con el fondo de
    la superficie. Se llama a sí misma para las hijas de cada hija. Todo con utilidades de tokens.

    @var list<\App\Modules\Core\Queries\OrgChartNode> $nodes
    @var int $level
--}}
@if ($nodes !== [])
    <ul class="ms-2 mt-2 grid gap-2 border-s-2 border-primary/40 ps-4">
        @foreach ($nodes as $node)
            <li
                wire:key="org-{{ $node->id }}"
                class="relative before:absolute before:-left-4 before:top-6 before:w-3.5 before:border-t-2 before:border-primary/40 last:after:absolute last:after:-left-[1.125rem] last:after:top-[1.625rem] last:after:-bottom-px last:after:w-0.5 last:after:bg-base-100"
            >
                @include('core::org-chart.box', ['node' => $node, 'level' => $level])
                @include('core::org-chart.branch', ['nodes' => $node->children, 'level' => $level + 1])
            </li>
        @endforeach
    </ul>
@endif
