{{--
    Los avisos de HC-08 de la carga en «Para cerrar» (tarea 1.50): los docentes que pasan su banda de horas frente a
    grupo. No impiden cerrar; cada uno dice si falta el oficio de conformidad del docente o si lo aceptó con la casilla.
    Sin avisos, nada. `$notices` (LoadClosing::hourNotices); `$flush` en el drawer, sin margen a los lados.
--}}
@if ($notices !== [])
    <div role="note" @class(['border-t border-base-300 py-3', 'px-4 md:px-5' => ! ($flush ?? false)]) data-testid="closing-hour-notices">
        <p class="flex items-center gap-2 text-sm font-semibold">
            <x-icon name="o-document-text" class="h-5 w-5 shrink-0 text-warning" aria-hidden="true" />
            {{ count($notices) === 1 ? '1 docente pasa su banda de horas frente a grupo' : count($notices).' docentes pasan su banda de horas frente a grupo' }}
            <span class="font-normal text-neutral">· aviso, no impide cerrar</span>
        </p>
        <ul class="mt-1.5 space-y-1 ps-7 text-[12.5px] text-neutral">
            @foreach ($notices as $notice)
                <li>{{ $notice }}</li>
            @endforeach
        </ul>
    </div>
@endif
