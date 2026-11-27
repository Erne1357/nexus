{{--
    El jefe de un departamento en «Avance del cierre» (ClosingBoardQuery::heads): su nombre, o «Sin jefe vigente» en
    warning con su icono, como en el Organigrama. `$names`.
--}}
@if ($names === [])
    <span class="inline-flex items-center gap-1 text-warning"><x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Sin jefe vigente</span>
@else
    <span>{{ implode(', ', $names) }}</span>
@endif
