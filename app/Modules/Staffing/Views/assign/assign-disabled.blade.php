{{--
    «Asignar» para quien no puede asignar (solo consulta, o la carga en otro estado): deshabilitado y con su motivo, que
    también dice la línea de arriba de la página (DESIGN.md, «Nada oculto por permiso»).
--}}
<span class="inline-flex" title="{{ $readOnlyReason }}">
    <button type="button" disabled aria-label="Asignar a {{ $candidate->teacherName }}: {{ $readOnlyReason }}" class="btn btn-ghost btn-sm max-md:h-11" data-testid="assign-disabled"><x-icon name="o-arrow-right-circle" class="h-4 w-4" />Asignar</button>
</span>
