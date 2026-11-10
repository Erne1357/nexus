{{--
    Lo que AssignTeacher rechazó al asignar al primer lugar (un choque, la carga en otro estado…), junto al docente.
--}}
<p class="flex items-start gap-2 rounded-box bg-error/10 px-3 py-2 text-sm text-error" role="alert" data-testid="assign-failure">
    <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
    <span><span class="font-semibold">No se asignó.</span> {{ $failure['message'] }}</span>
</p>
