{{--
    El aviso de la pantalla oculta para los demás (MN-02): una nota neutra, con el ojo tachado, arriba del
    contenido. Sin motivo no pinta nada.
--}}
<div>
    @if ($reason !== null)
        <div role="note" class="mb-4 flex max-w-[1100px] items-start gap-2.5 rounded-box border border-neutral/20 bg-neutral/8 px-3.5 py-2.5 text-base-content">
            <x-icon name="o-eye-slash" class="mt-0.5 w-4 h-4 flex-none text-secondary-muted" />
            <p><b>Oculta para los demás.</b> {{ $reason }}. Tú la ves porque administras Nexus; en el menú de los demás no aparece.</p>
        </div>
    @endif
</div>
