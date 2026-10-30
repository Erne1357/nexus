{{--
    Adscripciones de un docente (faculty::pages.teachers): la principal primero, «SC en C2», con las claves en Mono y
    los nombres en el `title`; las otras después de «También». TeacherQuery ya las trae ordenadas, la principal
    primero, con su departamento y su campus aunque estén borrados. En la tabla (`$stacked`) las otras van en su
    propio renglón; en el renglón apilado del celular, entre paréntesis.
--}}
@forelse ($teacher->adscriptions as $adscription)
    @if ($loop->index === 1)
        <span @class(['text-neutral', 'block text-xs' => $stacked])>{{ $stacked ? 'También' : '(también' }}
    @endif
    <span class="whitespace-nowrap" title="{{ $adscription->department?->name }}, {{ $adscription->campus?->name }}"><span class="font-mono">{{ $adscription->department?->code }}</span> en <span class="font-mono">{{ $adscription->campus?->code }}</span></span>{{ $loop->index === 0 ? '' : ($loop->last ? ($stacked ? '' : ')') : ',') }}
    @if ($loop->index >= 1 && $loop->last)
        </span>
    @endif
@empty
    <span class="text-neutral">Sin adscripción</span>
@endforelse
