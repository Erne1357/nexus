{{--
    La miniatura 3:4 de un alumno en «Fotos de alumnos»: su foto (URL firmada) o, sin ella, el icono de la cámara con
    sus iniciales para lectores de pantalla. `$url`, `$initials` y `$size` (una clase de ancho).
--}}
@if ($url !== null)
    <img src="{{ $url }}" alt="" class="{{ $size }} aspect-3/4 shrink-0 rounded-md border border-base-300 bg-base-200 object-cover object-top" />
@else
    <span class="{{ $size }} grid aspect-3/4 shrink-0 place-items-center rounded-md border border-base-300 bg-base-200 text-neutral" aria-hidden="true"><x-icon name="o-camera" class="h-5 w-5" /></span>
@endif
