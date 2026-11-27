{{--
    Lo que el alumno contestó del SIISAE al pedir la reposición (CO-12, autodeclarado): «Foto» o «Sin foto» y «Datos
    correctos». Sin respuestas (foto sola o solicitud anterior) no pinta nada y la tabla pone «—».

    $row: CredentialRequest.
--}}
@if ($row->siisae_photo_visible === null && $row->siisae_data_correct === null)
    <span class="text-neutral">—</span>
@else
    <div class="flex flex-wrap gap-1.5">
        @if ($row->siisae_photo_visible !== null)
            <x-core::status-badge :tone="$row->siisae_photo_visible ? 'success' : 'warning'" :label="$row->siisae_photo_visible ? 'Foto' : 'Sin foto'" icon="o-camera" />
        @endif
        @if ($row->siisae_data_correct !== null)
            <x-core::status-badge :tone="$row->siisae_data_correct ? 'success' : 'error'" :label="$row->siisae_data_correct ? 'Datos correctos' : 'Datos incorrectos'" />
        @endif
    </div>
@endif
