{{--
    Acciones de un alumno en «Fotos de alumnos» (communication::pages.student-photos), las mismas en la tabla y en los
    renglones del celular: «Subir foto» o «Cambiar foto» visible y lo demás en el menú «Acciones». `$student` es el alumno;
    `$photo` su URL firmada o null.
--}}
<div @class(['flex gap-1', 'justify-end' => $inTable, '-ms-3' => ! $inTable])>
    <x-button icon="o-arrow-up-tray" wire:click="openPhoto('{{ $student->id }}')" aria-label="{{ $photo === null ? 'Subir foto' : 'Cambiar foto' }} de {{ $student->name }}" class="btn-ghost btn-sm" spinner="openPhoto('{{ $student->id }}')" data-testid="photo-open-{{ $student->control_number }}">
        <span @class(['max-[1200px]:hidden' => $inTable])>{{ $photo === null ? 'Subir foto' : 'Cambiar foto' }}</span>
    </x-button>
    @if ($photo !== null)
        <x-core::row-menu :name="$student->name" :compact="$inTable">
            <x-core::row-menu-item label="Ver foto" :href="$photo" target="_blank" rel="noopener" />
        </x-core::row-menu>
    @endif
</div>
