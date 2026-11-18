{{--
    La marca de lo que le cambió a un grupo en la última publicación de su carga (tarea 8.5, DESIGN.md «Consulta
    publicada»): la insignia de Core con qué cambió («Cambió: horario y salón», `info` con `o-arrow-path`), «Grupo nuevo»
    (`success`) o «Cancelado» (`error`), sin número de versión ni cupos. En un grupo que sigue, la insignia es el
    `<summary>` de un `<details>` que abre el detalle en palabras (de lo que tenía a lo que quedó): se abre con teclado y
    los lectores de pantalla lo anuncian sin JavaScript. Sin cambio no pinta nada.
--}}
@props(['change'])

@if ($change !== null)
    <div {{ $attributes->class(['min-w-0']) }} data-change="{{ implode(' ', $change->kinds) }}">
        @if ($change->detail === null)
            <x-core::status-badge :tone="$change->tone()" :icon="$change->icon()" :label="$change->label()" />
        @else
            <details class="group/change">
                <summary class="inline-flex cursor-pointer list-none items-center rounded-field focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary max-md:min-h-11 [&::-webkit-details-marker]:hidden">
                    <x-core::status-badge :tone="$change->tone()" :icon="$change->icon()" :label="$change->label()">
                        <x-icon name="o-chevron-down" class="h-3.5 w-3.5 transition-transform group-open/change:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                    </x-core::status-badge>
                    <span class="sr-only">Ver qué cambió</span>
                </summary>
                <p class="mt-1.5 text-[13px]/[1.4] text-neutral">{{ $change->detail }}</p>
            </details>
        @endif
    </div>
@endif
