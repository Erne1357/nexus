{{--
    Las confirmaciones de las acciones de una carga (ClosesLoads), iguales en «Cierre de la carga» y en «Avance del
    cierre» (sobre su drawer):
    - Cerrar: el aviso de lo que pasa al cerrar con la cifra de grupos que se confirman, los que pasan con justificación
      y un comentario opcional.
    - Reabrir (o «Regresar con comentarios» en una modalidad con visto bueno): el motivo, obligatorio; el botón se habilita al
      escribirlo.
    - Dar visto bueno: comentario opcional; solo en una modalidad que lo exige.
    `$closing` es la carga a la vista (o null).
--}}
@php($load = $closing?->load)
@php($where = $load === null ? '' : "{$load->department->name} en {$load->modality->name}")
@php($returning = $load !== null && $load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Closed && $load->modality->profile->requiresSubdireccionApproval)

<x-modal wire:model="showClose" :title="'Cerrar la carga de '.$where" box-class="max-w-xl" data-testid="close-modal">
    @if ($closing)
        <div class="space-y-4">
            <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="close-warning">
                <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
                <p>
                    @if ($closing->activeGroups === 1)
                        Al cerrar, el grupo activo queda confirmado y la carga ya no se edita: horarios, docentes, salones y cupos quedan fijos. Solo Subdirección puede reabrirla.
                    @else
                        Al cerrar, los {{ $closing->activeGroups }} grupos activos quedan confirmados y la carga ya no se edita: horarios, docentes, salones y cupos quedan fijos. Solo Subdirección puede reabrirla.
                    @endif
                </p>
            </div>
            @if ($closing->justifiedCount() > 0)
                @php($parts = array_filter([$closing->justifiedTeacher() > 0 ? $closing->justifiedTeacher().' sin docente' : null, $closing->justifiedRoom() > 0 ? $closing->justifiedRoom().' sin salón' : null]))
                <p class="text-sm">Pasan con justificación {{ $closing->justifiedCount() }} {{ $closing->justifiedCount() === 1 ? 'grupo' : 'grupos' }}: {{ \Illuminate\Support\Arr::join($parts, ', ', ' y ') }}.</p>
            @endif
            <x-textarea label="Comentario (opcional)" wire:model="closeComment" rows="3" :placeholder="$closing->justifiedCount() > 0 ? 'Por ejemplo: Cerramos con '.$closing->justifiedCount().' '.($closing->justifiedCount() === 1 ? 'grupo justificado' : 'grupos justificados').'.' : 'Por ejemplo: Todo asignado y sin choques.'" data-testid="close-comment" />
        </div>
    @endif
    <x-slot:actions>
        <x-button label="Cancelar" class="btn-ghost" @click="$wire.showClose = false" />
        <x-button label="Cerrar carga" icon="o-lock-closed" class="btn-primary" wire:click="close" spinner="close" data-testid="confirm-close" />
    </x-slot:actions>
</x-modal>

<x-modal wire:model="showReopen" :title="($returning ? 'Regresar con comentarios la carga de ' : 'Reabrir la carga de ').$where" box-class="max-w-xl" data-testid="reopen-modal">
    <div class="space-y-4" x-data="{ filled: false }" x-init="$watch(() => $wire.showReopen, () => filled = false)">
        @if ($load)
            <p class="text-sm">
                {{ $returning ? 'La carga regresa a Ajustes con tus comentarios y la jefatura del departamento la corrige.' : 'La carga regresa a Ajustes y la jefatura del departamento vuelve a editarla.' }}
                @if ($load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Published)
                    Mientras tanto se sigue viendo la versión publicada{{ $load->current_version > 0 ? ' (v'.$load->current_version.')' : '' }}.
                @endif
            </p>
        @endif
        <x-textarea :label="$returning ? 'Comentarios' : 'Motivo'" wire:model="reopenReason" rows="4" x-on:input="filled = $event.target.value.trim() !== ''" data-testid="reopen-reason" />
        <div class="modal-action mt-0 flex-wrap items-center">
            <p class="me-auto text-sm text-neutral" x-show="! filled">{{ $returning ? 'Escribe tus comentarios para regresarla.' : 'Escribe el motivo para reabrirla.' }}</p>
            <x-button label="Cancelar" class="btn-ghost" @click="$wire.showReopen = false" />
            <x-button :label="$returning ? 'Regresar con comentarios' : 'Reabrir carga'" :icon="$returning ? 'o-arrow-uturn-left' : 'o-lock-open'" class="btn-primary" wire:click="reopen" spinner="reopen" x-bind:disabled="! filled" data-testid="confirm-reopen" />
        </div>
    </div>
</x-modal>

<x-modal wire:model="showApprove" :title="'Dar el visto bueno a la carga de '.$where" box-class="max-w-xl" data-testid="approve-modal">
    <div class="space-y-4">
        <p class="text-sm">La carga queda aprobada y lista para publicarse.</p>
        <x-textarea label="Comentario (opcional)" wire:model="approveComment" rows="3" data-testid="approve-comment" />
    </div>
    <x-slot:actions>
        <x-button label="Cancelar" class="btn-ghost" @click="$wire.showApprove = false" />
        <x-button label="Dar visto bueno" icon="o-check-badge" class="btn-primary" wire:click="approve" spinner="approve" data-testid="confirm-approve" />
    </x-slot:actions>
</x-modal>
